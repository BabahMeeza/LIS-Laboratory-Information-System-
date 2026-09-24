<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Helper;
use App\Core\Logger;

/**
 * Pemroses hasil dari alat laboratorium.
 *
 * Menerima payload ternormalisasi dari middleware, mencocokkannya dengan
 * spesimen/order, memetakan kode parameter alat ke pemeriksaan LIS,
 * menghitung flag terhadap nilai rujukan, menjalankan delta check,
 * lalu menyimpan hasil.
 *
 * Bentuk payload (satu pesan dapat memuat beberapa sampel):
 * [
 *   'instrument_code' => 'HEMA-01',
 *   'protocol'        => 'astm',
 *   'raw'             => '...',
 *   'samples' => [
 *     [
 *       'sample_id' => '2608310001',
 *       'results'   => [
 *         ['code'=>'WBC','value'=>'7.2','unit'=>'10*3/uL','flags'=>'N','completed_at'=>'2026-08-31 08:12:00'],
 *       ],
 *     ],
 *   ],
 * ]
 */
final class ResultProcessor
{
    /**
     * @param  array<string,mixed> $payload
     * @return array{message_id:int,status:string,total:int,tersimpan:int,detail:array<int,array<string,mixed>>}
     */
    public static function proses(array $payload): array
    {
        $kodeAlat = trim((string) ($payload['instrument_code'] ?? ''));
        $protokol = (string) ($payload['protocol'] ?? '');
        $samples  = is_array($payload['samples'] ?? null) ? $payload['samples'] : [];

        $instrument = $kodeAlat === '' ? null : Database::selectOne(
            'SELECT * FROM instruments WHERE kode = ? LIMIT 1',
            [$kodeAlat]
        );

        $sampleIdPertama = (string) ($samples[0]['sample_id'] ?? '');

        $messageId = Database::insert('instrument_messages', [
            'instrument_id' => $instrument['id'] ?? null,
            'kode_alat'     => $kodeAlat,
            'arah'          => 'in',
            'protokol'      => $protokol !== '' ? substr($protokol, 0, 10) : null,
            'sample_id'     => $sampleIdPertama !== '' ? $sampleIdPertama : null,
            'raw'           => self::simpanRaw($instrument) ? (string) ($payload['raw'] ?? '') : null,
            'parsed'        => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'        => 'diterima',
        ]);

        if ($instrument === null) {
            self::tandaiPesan($messageId, 'error', 0, 0, 'Alat dengan kode "' . $kodeAlat . '" belum terdaftar di LIS.');

            return [
                'message_id' => $messageId,
                'status'     => 'error',
                'total'      => 0,
                'tersimpan'  => 0,
                'detail'     => [],
            ];
        }

        $total     = 0;
        $tersimpan = 0;
        $detail    = [];

        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $hasil     = self::prosesSampel($instrument, $sample, $messageId);
            $total    += $hasil['total'];
            $tersimpan += $hasil['tersimpan'];
            $detail[]  = $hasil;
        }

        $status = match (true) {
            $total === 0                  => 'tidak_cocok',
            $tersimpan === 0              => 'tidak_cocok',
            $tersimpan < $total           => 'sebagian',
            default                       => 'diproses',
        };

        self::tandaiPesan($messageId, $status, $total, $tersimpan, null);

        Database::update('instruments', [
            'status_koneksi' => 'online',
            'last_seen_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$instrument['id']]);

        return [
            'message_id' => $messageId,
            'status'     => $status,
            'total'      => $total,
            'tersimpan'  => $tersimpan,
            'detail'     => $detail,
        ];
    }

    /**
     * @param  array<string,mixed> $instrument
     * @param  array<string,mixed> $sample
     * @return array{sample_id:string,total:int,tersimpan:int,pesan:array<int,string>}
     */
    private static function prosesSampel(array $instrument, array $sample, int $messageId): array
    {
        $sampleId = trim((string) ($sample['sample_id'] ?? ''));
        $results  = is_array($sample['results'] ?? null) ? $sample['results'] : [];
        $pesan    = [];

        if ($sampleId === '' || $results === []) {
            return ['sample_id' => $sampleId, 'total' => count($results), 'tersimpan' => 0, 'pesan' => ['Sample ID kosong atau tanpa hasil.']];
        }

        $konteks = self::cariKonteks($sampleId);

        if ($konteks === null) {
            self::simpanMenggantung($messageId, (int) $instrument['id'], $sampleId, $sample, 'sample_tidak_ditemukan');

            return [
                'sample_id' => $sampleId,
                'total'     => count($results),
                'tersimpan' => 0,
                'pesan'     => ['Sample ID "' . $sampleId . '" tidak cocok dengan order mana pun.'],
            ];
        }

        $rentangHari  = Config::settingInt('hasil.delta_check_hari', 7);
        $ambangPersen = (float) Config::settingInt('hasil.delta_ambang_persen', 30);

        // Autovalidasi hanya berjalan bila ada penanggung jawab yang ditunjuk.
        // Hasil tanpa nama penanggung jawab tidak layak diterbitkan dan tidak
        // memenuhi syarat telusur akreditasi, sehingga di sini autovalidasi
        // dimatikan bila pengaturan penanggung jawab belum diisi.
        $penanggungJawab = self::penanggungJawabAutovalidasi();
        $autoVerify      = ((int) $instrument['auto_verify'] === 1)
            && Config::settingBool('hasil.auto_verify', false)
            && $penanggungJawab !== null;

        if ((int) $instrument['auto_verify'] === 1
            && Config::settingBool('hasil.auto_verify', false)
            && $penanggungJawab === null) {
            Logger::warning(
                'Autovalidasi dilewati: pengaturan "hasil.auto_verify_user" belum menunjuk '
                . 'dokter penanggung jawab yang aktif.',
                ['instrument' => $instrument['kode']]
            );
        }

        $total     = 0;
        $tersimpan = 0;
        $belumDipetakan = [];

        // Setiap nilai yang tidak jadi tersimpan dikumpulkan di sini beserta
        // sebabnya. Sebelumnya hanya dua sebab yang menghasilkan baris
        // "Hasil Belum Terpetakan" — sampel tidak ditemukan dan parameter
        // belum dipetakan — sementara nilai yang ditolak karena tidak diminta
        // pada order atau karena hasil lamanya sudah diverifikasi hanya
        // dicatat ke berkas log lalu hilang dari database.
        //
        // Itu keliru untuk sistem laboratorium: analyzer yang mengulang
        // sebuah sampel adalah kejadian rutin, dan nilai ulangannya justru
        // yang sering perlu ditinjau. Kini seluruh nilai yang tidak tersimpan
        // dipertahankan, kecuali yang memang sengaja diabaikan lewat
        // pemetaan (keputusan sadar operator).
        /** @var array<int,array<string,mixed>> $tidakTersimpan */
        $tidakTersimpan = [];
        /** @var array<int,string> $sebabDitemui */
        $sebabDitemui = [];

        $catatTidakTersimpan = static function (array $r, string $sebab) use (&$tidakTersimpan, &$sebabDitemui): void {
            $r['_alasan']     = $sebab;
            $tidakTersimpan[] = $r;
            $sebabDitemui[]   = $sebab;
        };

        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            $total++;

            $kodeAlat = trim((string) ($r['code'] ?? ''));
            if ($kodeAlat === '') {
                $catatTidakTersimpan($r, 'kode_kosong');
                continue;
            }

            $map = self::pemetaan((int) $instrument['id'], $kodeAlat);

            if ($map === null) {
                $belumDipetakan[] = $kodeAlat;
                self::catatKodeBaru((int) $instrument['id'], $kodeAlat, (string) ($r['unit'] ?? ''));
                $catatTidakTersimpan($r, 'parameter_belum_dipetakan');
                continue;
            }
            if ((int) $map['abaikan'] === 1 || $map['test_id'] === null) {
                // Diabaikan secara sengaja lewat pemetaan — bukan kehilangan.
                continue;
            }

            // Nilai kosong dari analyzer adalah kejadian rutin (parameter
            // ditekan, hasil di luar rentang ukur, kanal dimatikan). Nilai
            // seperti ini bukan kehilangan data, jadi tidak dijadikan baris
            // menggantung — bila dijadikan, layar Hasil Belum Terpetakan
            // akan penuh derau dan justru berhenti diperiksa orang.
            if (trim((string) ($r['value'] ?? '')) === '') {
                continue;
            }

            $testId = (int) $map['test_id'];

            // Cari order_item yang menunggu hasil untuk pemeriksaan ini.
            $item = self::cariOrderItem($konteks, $testId);
            if ($item === null) {
                $pesan[] = 'Parameter ' . $kodeAlat . ' tidak diminta pada order ' . $konteks['no_order'] . '.';
                $catatTidakTersimpan($r, 'tidak_diminta_pada_order');
                continue;
            }

            $disimpan = self::simpanHasil(
                $konteks,
                $item,
                $r,
                $map,
                (int) $instrument['id'],
                $messageId,
                $rentangHari,
                $ambangPersen,
                $autoVerify,
                (string) $instrument['auto_verify_max_flag'],
                $penanggungJawab
            );

            if ($disimpan) {
                $tersimpan++;
            } else {
                // simpanHasil menolak: nilai kosong, atau hasil lama sudah
                // diverifikasi/dikoreksi sehingga tidak boleh ditimpa alat.
                $catatTidakTersimpan($r, 'ditolak_hasil_sudah_diverifikasi');
            }
        }

        if ($belumDipetakan !== []) {
            $pesan[] = 'Parameter belum dipetakan: ' . implode(', ', array_unique($belumDipetakan));
        }

        if ($tidakTersimpan !== []) {
            $unik = array_values(array_unique($sebabDitemui));

            // Satu baris menggantung per sampel, memuat seluruh nilai yang
            // tidak tersimpan. Kunci "results" dipertahankan apa adanya agar
            // alur pemasangan ulang (pasangMenggantung) tetap bekerja.
            self::simpanMenggantung(
                $messageId,
                (int) $instrument['id'],
                $sampleId,
                [
                    'sample_id'      => $sampleId,
                    'results'        => $tidakTersimpan,
                    'belum_dipetakan' => array_values(array_unique($belumDipetakan)),
                    'no_order'       => $konteks['no_order'] ?? null,
                ],
                count($unik) === 1 ? $unik[0] : 'sebagian_tidak_tersimpan'
            );

            Logger::warning(
                'Hasil alat tidak tersimpan dan dipindahkan ke Hasil Belum Terpetakan.',
                [
                    'instrument' => $instrument['kode'],
                    'sample_id'  => $sampleId,
                    'jumlah'     => count($tidakTersimpan),
                    'sebab'      => $unik,
                ]
            );
        }

        if ($tersimpan > 0) {
            OrderService::segarkanStatus((int) $konteks['order_id']);
        }

        return ['sample_id' => $sampleId, 'total' => $total, 'tersimpan' => $tersimpan, 'pesan' => $pesan];
    }

    /**
     * Cocokkan sample ID dengan order.
     *
     * Jalur utama adalah barcode spesimen: satu barcode menunjuk tepat satu
     * tabung, sehingga hasil dapat ditautkan ke spesimen yang benar.
     *
     * Bila alat mengirim nomor lab atau nomor order (bukan barcode tabung),
     * spesimen TIDAK dapat ditentukan secara pasti pada order yang memiliki
     * lebih dari satu tabung. Dalam hal itu specimen_id sengaja dibiarkan
     * kosong dan diisi belakangan dari order_item — bukan ditebak.
     *
     * @return array<string,mixed>|null
     */
    private static function cariKonteks(string $sampleId): ?array
    {
        // 1. Barcode spesimen — jalur normal, pencocokan pasti.
        $row = Database::selectOne(
            'SELECT o.id AS order_id, o.no_order, o.no_lab, o.status AS status_order,
                    o.patient_id, p.jk, p.tgl_lahir,
                    s.id AS specimen_id, s.collected_at
             FROM specimens s
             JOIN orders o   ON o.id = s.order_id
             JOIN patients p ON p.id = o.patient_id
             WHERE s.barcode = ? AND o.status <> \'cancelled\'
             ORDER BY o.id DESC LIMIT 1',
            [$sampleId]
        );
        if ($row !== null) {
            return $row;
        }

        // 2. Nomor lab atau nomor order — pencocokan order saja.
        $row = self::konteksTanpaSpesimen('o.no_lab = ? OR o.no_order = ?', [$sampleId, $sampleId]);
        if ($row !== null) {
            return $row;
        }

        // 3. Sebagian alat memangkas angka nol di depan sample ID.
        $tanpaNol = ltrim($sampleId, '0');
        if ($tanpaNol !== '' && $tanpaNol !== $sampleId) {
            $row = Database::selectOne(
                'SELECT o.id AS order_id, o.no_order, o.no_lab, o.status AS status_order,
                        o.patient_id, p.jk, p.tgl_lahir,
                        s.id AS specimen_id, s.collected_at
                 FROM specimens s
                 JOIN orders o   ON o.id = s.order_id
                 JOIN patients p ON p.id = o.patient_id
                 WHERE TRIM(LEADING \'0\' FROM s.barcode) = ? AND o.status <> \'cancelled\'
                 ORDER BY o.id DESC LIMIT 1',
                [$tanpaNol]
            );
            if ($row !== null) {
                return $row;
            }

            return self::konteksTanpaSpesimen(
                'TRIM(LEADING \'0\' FROM o.no_lab) = ? OR TRIM(LEADING \'0\' FROM o.no_order) = ?',
                [$tanpaNol, $tanpaNol]
            );
        }

        return null;
    }

    /**
     * Konteks order tanpa mengikat spesimen tertentu. specimen_id dibiarkan
     * null; pemilihan tabung diserahkan ke order_item masing-masing hasil.
     *
     * @param  array<int,mixed> $bind
     * @return array<string,mixed>|null
     */
    private static function konteksTanpaSpesimen(string $kondisi, array $bind): ?array
    {
        $row = Database::selectOne(
            'SELECT o.id AS order_id, o.no_order, o.no_lab, o.status AS status_order,
                    o.patient_id, p.jk, p.tgl_lahir,
                    NULL AS specimen_id,
                    (SELECT MIN(s.collected_at) FROM specimens s WHERE s.order_id = o.id) AS collected_at
             FROM orders o
             JOIN patients p ON p.id = o.patient_id
             WHERE (' . $kondisi . ') AND o.status <> \'cancelled\'
             ORDER BY o.id DESC LIMIT 1',
            $bind
        );

        return $row;
    }

    /** @return array<string,mixed>|null */
    private static function pemetaan(int $instrumentId, string $kodeAlat): ?array
    {
        $map = Database::selectOne(
            'SELECT * FROM instrument_test_map WHERE instrument_id = ? AND kode_alat = ? LIMIT 1',
            [$instrumentId, $kodeAlat]
        );

        if ($map !== null) {
            return $map;
        }

        // Fallback: kode alat kebetulan sama persis dengan kode pemeriksaan LIS.
        //
        // Kemudahan ini disengaja agar alat sederhana langsung bekerja, tetapi
        // pencocokan tersirat seperti ini TIDAK boleh senyap: bila kode alat
        // bertabrakan dengan kode LIS yang berbeda arti, hasil bisa masuk ke
        // pemeriksaan yang salah. Karena itu pemetaan hasil tebakan langsung
        // dituliskan ke tabel pemetaan agar terlihat dan dapat dikoreksi
        // petugas, serta dicatat di log.
        $test = Database::selectOne(
            'SELECT id, kode, nama FROM tests WHERE kode = ? AND aktif = 1 LIMIT 1',
            [$kodeAlat]
        );

        if ($test === null) {
            return null;
        }

        try {
            Database::execute(
                'INSERT IGNORE INTO instrument_test_map (instrument_id, kode_alat, test_id) VALUES (?,?,?)',
                [$instrumentId, $kodeAlat, (int) $test['id']]
            );
        } catch (\Throwable $e) {
            Logger::warning('Gagal menyimpan pemetaan otomatis: ' . $e->getMessage());
        }

        Logger::warning('Pemetaan parameter dibuat otomatis dari kesamaan kode — mohon diverifikasi', [
            'instrument_id' => $instrumentId,
            'kode_alat'     => $kodeAlat,
            'test_id'       => (int) $test['id'],
            'nama_test'     => $test['nama'],
        ]);

        Audit::log(
            'pemetaan_otomatis_alat',
            'instrument',
            (string) $instrumentId,
            'Kode alat "' . $kodeAlat . '" dipetakan otomatis ke pemeriksaan "' . $test['nama']
            . '" karena kodenya sama. Mohon diperiksa pada menu Pemetaan Parameter.'
        );

        return [
            'id'            => 0,
            'instrument_id' => $instrumentId,
            'kode_alat'     => $kodeAlat,
            'test_id'       => (int) $test['id'],
            'faktor'        => 1,
            'offset_nilai'  => 0,
            'satuan_alat'   => null,
            'abaikan'       => 0,
        ];
    }

    /**
     * Catat kode parameter baru agar muncul di layar pemetaan
     * (test_id NULL = menunggu keputusan pengguna).
     */
    private static function catatKodeBaru(int $instrumentId, string $kodeAlat, string $satuan): void
    {
        try {
            Database::execute(
                'INSERT IGNORE INTO instrument_test_map (instrument_id, kode_alat, test_id, satuan_alat, abaikan)
                 VALUES (?, ?, NULL, ?, 0)',
                [$instrumentId, $kodeAlat, $satuan !== '' ? $satuan : null]
            );
        } catch (\Throwable $e) {
            Logger::warning('Gagal mencatat kode alat baru: ' . $e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed> $konteks
     * @return array<string,mixed>|null
     */
    private static function cariOrderItem(array $konteks, int $testId): ?array
    {
        return Database::selectOne(
            'SELECT oi.*, t.tipe_hasil, t.desimal, t.satuan, t.kode AS kode_test, t.nama AS nama_test
             FROM order_items oi
             JOIN tests t ON t.id = oi.test_id
             WHERE oi.order_id = ? AND oi.test_id = ? AND oi.status <> \'cancelled\'
             LIMIT 1',
            [$konteks['order_id'], $testId]
        );
    }

    /**
     * @param array<string,mixed> $konteks
     * @param array<string,mixed> $item
     * @param array<string,mixed> $r
     * @param array<string,mixed> $map
     */
    private static function simpanHasil(
        array $konteks,
        array $item,
        array $r,
        array $map,
        int $instrumentId,
        int $messageId,
        int $rentangHari,
        float $ambangPersen,
        bool $autoVerify,
        string $autoVerifyMaxFlag,
        ?int $penanggungJawab = null
    ): bool {
        $nilaiMentah = trim((string) ($r['value'] ?? ''));
        if ($nilaiMentah === '') {
            return false;
        }

        $testId = (int) $item['test_id'];
        $umur   = Helper::umurHari($konteks['tgl_lahir'] ?? null, $konteks['collected_at'] ?? null);
        $ruj    = ReferenceRangeService::untuk($testId, $konteks['jk'] ?? null, $umur);

        // Satuan alat vs satuan master LIS.
        //
        // Analyzer sering memakai satuan SI sementara master memakai satuan
        // konvensional. Membandingkan angka mentah lintas satuan menghasilkan
        // flag yang salah ARAH — Hb 64 g/L (6,4 g/dL, anemia berat) dinilai
        // "kritis tinggi" terhadap rujukan g/dL. Karena itu satuan diselaraskan
        // lebih dulu, dan bila konversinya tidak pasti, penilaian ditahan.
        $satuanAlat   = trim((string) ($r['unit'] ?? ''));
        $satuanMaster = trim((string) ($item['satuan'] ?? ''));

        $faktorSatuan   = 1.0;
        $satuanBentrok  = false;

        // Faktor manual pada pemetaan selalu menang: bila operator sudah
        // mengoreksi sendiri, jangan dikonversi dua kali.
        $faktorManual = (float) $map['faktor'];

        if (abs($faktorManual - 1.0) < 1e-9 && $satuanAlat !== '' && $satuanMaster !== '') {
            $f = UnitConverter::faktor($satuanAlat, $satuanMaster);

            if ($f === null) {
                $satuanBentrok = true;
            } else {
                $faktorSatuan = $f;
            }
        }

        // Nilai numerik (bila ada) dengan koreksi faktor & offset dari pemetaan.
        $nilaiNum = null;
        $normal   = str_replace(',', '.', $nilaiMentah);
        if (is_numeric($normal)) {
            $nilaiNum    = (((float) $normal * $faktorSatuan) * $faktorManual) + (float) $map['offset_nilai'];
            $nilaiMentah = Helper::nilai($nilaiNum, (int) $item['desimal']);
        }

        if ($satuanBentrok) {
            // Simpan nilainya, tetapi JANGAN menilai. Flag yang salah arah
            // lebih berbahaya daripada tidak ada flag sama sekali.
            $flag = '';

            Logger::warning(
                'Satuan alat berbeda dari master dan konversinya tidak pasti — '
                . 'penilaian flag ditahan, hasil perlu ditinjau manual.',
                [
                    'instrument'  => $instrumentId,
                    'kode_alat'   => $r['code'] ?? '',
                    'konversi'    => UnitConverter::jelaskan($satuanAlat, $satuanMaster),
                    'nilai'       => $nilaiMentah,
                ]
            );
        } else {
            $flag = $nilaiNum !== null
                ? ReferenceRangeService::flagNumerik($nilaiNum, $ruj)
                : ReferenceRangeService::flagTeks($nilaiMentah, $ruj);
        }

        $delta = ReferenceRangeService::deltaCheck(
            (int) $konteks['patient_id'],
            $testId,
            $nilaiNum,
            $rentangHari,
            $ambangPersen,
            (int) $konteks['order_id']
        );

        $kritis = ReferenceRangeService::kritis($flag);

        // Autovalidasi hanya untuk hasil yang aman: tidak kritis, tidak
        // ditandai delta check, dan flag tidak melebihi batas yang diizinkan.
        $bolehAuto = $autoVerify
            && !$kritis
            && !$satuanBentrok    // satuan meragukan tidak boleh lolos otomatis
            && $delta['status'] !== 'flagged'
            && self::flagDalamBatas($flag, $autoVerifyMaxFlag);

        $status = $bolehAuto ? 'verified' : 'final';

        $data = [
            'order_id'      => (int) $konteks['order_id'],
            'order_item_id' => (int) $item['id'],
            'test_id'       => $testId,
            // Bila sample ID bukan barcode tabung, spesimen diambil dari
            // order_item — bukan ditebak dari salah satu tabung order.
            'specimen_id'   => $konteks['specimen_id'] ?? ($item['specimen_id'] ?? null),
            'nilai'         => mb_substr($nilaiMentah, 0, 255),
            'nilai_num'     => $nilaiNum,
            // Setelah konversi, nilai berada dalam satuan MASTER — maka
            // satuan itulah yang disimpan. Menyimpan satuan alat di samping
            // nilai yang sudah dikonversi (mis. "6.4" dengan label "g/L")
            // akan menyesatkan pembaca hasil.
            'satuan'        => $satuanBentrok
                ? ($satuanAlat !== '' ? mb_substr($satuanAlat, 0, 30) : ($item['satuan'] ?? null))
                : ($satuanMaster !== '' ? mb_substr($satuanMaster, 0, 30)
                    : ($satuanAlat !== '' ? mb_substr($satuanAlat, 0, 30) : null)),
            'flag'          => $flag,
            'flag_alat'     => ($r['flags'] ?? '') !== '' ? mb_substr((string) $r['flags'], 0, 10) : null,
            'ref_low'       => $ruj['low']  ?? null,
            'ref_high'      => $ruj['high'] ?? null,
            'ref_teks'      => ReferenceRangeService::teks($ruj),
            'instrument_id' => $instrumentId,
            'message_id'    => $messageId,
            'is_manual'     => 0,
            'status'        => $status,
            'delta_check'   => $delta['status'],
            'delta_persen'  => $delta['persen'],
            'is_kritis'     => $kritis ? 1 : 0,
            'entered_at'    => date('Y-m-d H:i:s'),
        ];

        if ($bolehAuto) {
            $data['verified_at'] = date('Y-m-d H:i:s');
            // Penanggung jawab autovalidasi wajib terisi — lihat
            // penanggungJawabAutovalidasi(). Tanpa itu $bolehAuto pasti false.
            $data['verified_by'] = $penanggungJawab;
            $data['catatan']     = trim('Divalidasi otomatis oleh sistem. ' . (string) ($data['catatan'] ?? ''));
        }

        $existing = Database::selectOne(
            'SELECT id, nilai, status FROM results WHERE order_item_id = ? LIMIT 1',
            [$item['id']]
        );

        if ($existing === null) {
            $resultId = Database::insert('results', $data);
        } else {
            // Hasil yang sudah diverifikasi manusia tidak ditimpa otomatis.
            if (in_array((string) $existing['status'], ['verified', 'corrected'], true)) {
                Logger::info('Hasil dari alat diabaikan karena sudah diverifikasi', [
                    'result_id' => $existing['id'],
                    'test'      => $item['kode_test'],
                ]);

                return false;
            }

            $resultId = (int) $existing['id'];
            unset($data['order_id'], $data['order_item_id'], $data['test_id']);
            Database::update('results', $data, 'id = ?', [$resultId]);

            if ((string) $existing['nilai'] !== (string) $data['nilai']) {
                Database::insert('result_history', [
                    'result_id'   => $resultId,
                    'nilai_lama'  => $existing['nilai'],
                    'nilai_baru'  => $data['nilai'],
                    'status_lama' => $existing['status'],
                    'status_baru' => $status,
                    'alasan'      => 'Pembaruan otomatis dari alat',
                    'user_id'     => null,
                    'sumber'      => 'alat',
                ]);
            }
        }

        Database::update('order_items', ['status' => $bolehAuto ? 'verified' : 'resulted'], 'id = ?', [$item['id']]);

        if ($kritis) {
            Logger::warning('NILAI KRITIS terdeteksi', [
                'order'     => $konteks['no_order'],
                'test'      => $item['kode_test'],
                'nilai'     => $nilaiMentah,
                'flag'      => $flag,
                'result_id' => $resultId,
            ]);
        }

        return true;
    }

    /**
     * Pengguna yang bertanggung jawab atas hasil yang divalidasi otomatis.
     *
     * Hasil laboratorium yang diterbitkan harus dapat ditelusuri kepada
     * seorang penanggung jawab. Karena itu autovalidasi mensyaratkan
     * pengaturan "hasil.auto_verify_user" menunjuk pengguna aktif dengan
     * peran verifikator atau admin; bila tidak, autovalidasi tidak berjalan.
     */
    private static function penanggungJawabAutovalidasi(): ?int
    {
        $userId = Config::settingInt('hasil.auto_verify_user', 0);
        if ($userId <= 0) {
            return null;
        }

        $ada = Database::scalar(
            "SELECT id FROM users WHERE id = ? AND aktif = 1 AND role IN ('verifikator','admin') LIMIT 1",
            [$userId]
        );

        return $ada === null ? null : (int) $ada;
    }

    private static function flagDalamBatas(string $flag, string $maks): bool
    {
        if ($flag === '' || $flag === 'N') {
            return true;
        }
        if ($maks === 'N') {
            return false;
        }

        // maks 'L' atau 'H' mengizinkan flag di luar rujukan tapi bukan kritis.
        return in_array($flag, ['L', 'H'], true);
    }

    /** @param array<string,mixed> $sample */
    private static function simpanMenggantung(
        int $messageId,
        int $instrumentId,
        string $sampleId,
        array $sample,
        string $alasan
    ): void {
        Database::insert('orphan_results', [
            'message_id'    => $messageId,
            'instrument_id' => $instrumentId,
            'sample_id'     => $sampleId,
            'payload'       => (string) json_encode($sample, JSON_UNESCAPED_UNICODE),
            'alasan'        => $alasan,
            'status'        => 'menunggu',
        ]);
    }

    private static function tandaiPesan(int $messageId, string $status, int $total, int $tersimpan, ?string $error): void
    {
        Database::update('instrument_messages', [
            'status'        => $status,
            'jml_hasil'     => $total,
            'jml_tersimpan' => $tersimpan,
            'pesan_error'   => $error === null ? null : mb_substr($error, 0, 500),
            'processed_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$messageId]);
    }

    /** @param array<string,mixed>|null $instrument */
    private static function simpanRaw(?array $instrument): bool
    {
        return $instrument === null || (int) $instrument['simpan_raw'] === 1;
    }

    /**
     * Pasang ulang hasil menggantung ke sebuah order setelah pengguna
     * menentukan pencocokannya secara manual.
     */
    public static function pasangMenggantung(int $orphanId, int $orderId, int $userId): array
    {
        $orphan = Database::selectOne('SELECT * FROM orphan_results WHERE id = ? LIMIT 1', [$orphanId]);
        if ($orphan === null) {
            return ['sukses' => false, 'pesan' => 'Data hasil menggantung tidak ditemukan.'];
        }
        if ((string) $orphan['status'] !== 'menunggu') {
            return ['sukses' => false, 'pesan' => 'Data ini sudah diproses sebelumnya.'];
        }

        $order = Database::selectOne(
            'SELECT o.id AS order_id, o.no_order, o.no_lab, o.patient_id, p.jk, p.tgl_lahir,
                    (SELECT s.id FROM specimens s WHERE s.order_id = o.id ORDER BY s.id LIMIT 1) AS specimen_id,
                    (SELECT s.collected_at FROM specimens s WHERE s.order_id = o.id ORDER BY s.id LIMIT 1) AS collected_at
             FROM orders o JOIN patients p ON p.id = o.patient_id
             WHERE o.id = ? LIMIT 1',
            [$orderId]
        );
        if ($order === null) {
            return ['sukses' => false, 'pesan' => 'Order tujuan tidak ditemukan.'];
        }

        $instrument = Database::selectOne('SELECT * FROM instruments WHERE id = ? LIMIT 1', [$orphan['instrument_id']]);
        if ($instrument === null) {
            return ['sukses' => false, 'pesan' => 'Alat sumber tidak ditemukan.'];
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode((string) $orphan['payload'], true) ?: [];
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        $rentangHari  = Config::settingInt('hasil.delta_check_hari', 7);
        $ambangPersen = (float) Config::settingInt('hasil.delta_ambang_persen', 30);

        $tersimpan = 0;
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            $map = self::pemetaan((int) $instrument['id'], trim((string) ($r['code'] ?? '')));
            if ($map === null || $map['test_id'] === null || (int) $map['abaikan'] === 1) {
                continue;
            }
            $item = self::cariOrderItem($order, (int) $map['test_id']);
            if ($item === null) {
                continue;
            }
            if (self::simpanHasil(
                $order,
                $item,
                $r,
                $map,
                (int) $instrument['id'],
                (int) $orphan['message_id'],
                $rentangHari,
                $ambangPersen,
                false,
                'N'
            )) {
                $tersimpan++;
            }
        }

        Database::update('orphan_results', [
            'status'      => 'terpasang',
            'order_id'    => $orderId,
            'resolved_by' => $userId,
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$orphanId]);

        OrderService::segarkanStatus($orderId);

        Audit::log(
            'pasang_hasil_menggantung',
            'orphan_result',
            (string) $orphanId,
            'Dipasang ke order ' . $order['no_order'] . ' (' . $tersimpan . ' hasil)'
        );

        return [
            'sukses'    => true,
            'tersimpan' => $tersimpan,
            'pesan'     => $tersimpan . ' hasil berhasil dipasang ke order ' . $order['no_order'] . '.',
        ];
    }
}
