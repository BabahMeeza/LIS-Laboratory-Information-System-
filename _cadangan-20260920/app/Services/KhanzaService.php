<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Helper;
use App\Core\Http;
use App\Core\Logger;

/**
 * Integrasi dua arah dengan SIMRS Khanza melalui konektor REST.
 *
 * Konektor (folder khanza-connector/) dipasang di web server Khanza dan
 * berbicara dengan database bridging resmi Khanza `sik_bridging_lab`:
 *
 *   MASUK  : permintaan_lab + detail_permintaan_lab  →  order di LIS
 *   KELUAR : hasil terverifikasi LIS  →  detail_hasil_lab
 *
 * LIS tidak pernah menyentuh database `sik` secara langsung; seluruh
 * pertukaran data berjalan lewat HTTP + API key sehingga LIS dapat
 * ditempatkan di server terpisah.
 */
final class KhanzaService
{
    // -----------------------------------------------------------------
    // Konfigurasi
    // -----------------------------------------------------------------

    public static function aktif(): bool
    {
        return Config::settingBool('khanza.aktif', (bool) Config::get('khanza.aktif', false));
    }

    private static function baseUrl(): string
    {
        $url = Config::setting('khanza.base_url') ?? (string) Config::get('khanza.base_url', '');

        return rtrim($url, '/');
    }

    private static function apiKey(): string
    {
        return Config::setting('khanza.api_key') ?? (string) Config::get('khanza.api_key', '');
    }

    private static function apiSecret(): string
    {
        return Config::setting('khanza.api_secret') ?? (string) Config::get('khanza.api_secret', '');
    }

    /** @return array<string,string> */
    private static function headers(?string $body = null): array
    {
        $headers = ['X-API-Key' => self::apiKey()];

        $secret = self::apiSecret();
        if ($secret !== '' && $body !== null) {
            $headers['X-Signature'] = Http::signature($body, $secret);
        }

        return $headers;
    }

    /**
     * Sebab kegagalan panggilan konektor, dalam kalimat yang bisa
     * ditindaklanjuti.
     *
     * MENGAPA INI ADA
     *
     * Konektor sudah bersusah payah menjelaskan dirinya. Ketika tabel
     * bridging tidak ditemukan, ia menjawab HTTP 500 dengan badan:
     *
     *   {"sukses":false,"pesan":"Tabel permintaan_lab tidak ditemukan
     *    pada database sikori. Jalankan install.sql atau periksa
     *    konfigurasi database."}
     *
     * Tetapi pemanggilnya menulis:
     *
     *   $res['error'] ?? 'HTTP ' . $res['status']
     *
     * dan `error` hanya terisi bila cURL sendiri gagal. Pada HTTP 500
     * cURL BERHASIL — ia menerima jawaban — sehingga `error` bernilai
     * null dan seluruh penjelasan tadi dibuang, diganti "HTTP 500".
     *
     * Petugas lalu melihat "error 500" tanpa satu pun petunjuk, padahal
     * jawabannya sudah ada di tangan sejak awal. Fungsi ini memungutnya
     * kembali.
     */
    private static function sebabGagal(array $res): string
    {
        // 1. Penjelasan dari konektor — yang paling berguna.
        $pesan = trim((string) ($res['data']['pesan'] ?? ''));
        if ($pesan !== '') {
            return $pesan . ' (HTTP ' . (int) $res['status'] . ')';
        }

        // 2. Kegagalan di tingkat jaringan (cURL): host tidak terjangkau,
        //    kehabisan waktu, sertifikat ditolak.
        $error = trim((string) ($res['error'] ?? ''));
        if ($error !== '') {
            return $error;
        }

        $status = (int) $res['status'];

        // 3. Jawaban datang tetapi bukan JSON yang kita kenali. Biasanya
        //    halaman galat PHP dari sisi Khanza; cuplikannya jauh lebih
        //    menolong daripada nomor status.
        $body = trim((string) ($res['body'] ?? ''));
        if ($body !== '') {
            $ringkas = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
            if ($ringkas !== '') {
                return sprintf(
                    'HTTP %d — konektor menjawab bukan JSON: "%s"',
                    $status,
                    mb_substr($ringkas, 0, 300)
                );
            }
        }

        // 4. Benar-benar tidak ada keterangan.
        return match (true) {
            $status === 401 || $status === 403 =>
                'HTTP ' . $status . ' — kredensial ditolak konektor. Samakan api_key '
                . 'dan api_secret pada config.php konektor dengan Pengaturan → Integrasi di LIS.',
            $status === 404 =>
                'HTTP 404 — berkas endpoint tidak ditemukan. Periksa khanza.base_url; '
                . 'ia harus menunjuk folder khanza-connector, bukan akar situs Khanza.',
            $status === 0 =>
                'Konektor tidak menjawab sama sekali. Periksa alamat, port, dan firewall.',
            default => 'HTTP ' . $status . ' tanpa keterangan dari konektor.',
        };
    }

    // -----------------------------------------------------------------
    // Uji koneksi
    // -----------------------------------------------------------------

    /** @return array{ok:bool,pesan:string,data:array<mixed>|null} */
    public static function ping(): array
    {
        $url = self::baseUrl() . '/api/ping.php';
        $res = Http::get($url, self::headers(), (int) Config::get('khanza.timeout', 15), (bool) Config::get('khanza.verify_ssl', true));

        self::catat('keluar', 'ping', null, null, $url, null, $res);

        if (!$res['ok']) {
            return [
                'ok'    => false,
                'pesan' => 'Konektor tidak dapat dihubungi. ' . self::sebabGagal($res),
                'data'  => null,
            ];
        }

        return [
            'ok'    => true,
            'pesan' => 'Konektor Khanza merespons dengan baik.',
            'data'  => $res['data'],
        ];
    }

    /**
     * Salin permintaan lab dari database UTAMA Khanza ke database bridging.
     *
     * Dipakai bila fitur bridging lab Khanza tidak tersedia sehingga
     * sik_bridging_lab tidak pernah terisi — keadaan yang membuat "Tarik
     * Order" menjawab 200 tanpa data selamanya.
     *
     * Seluruh pekerjaan berat ada di konektor (api/sync.php); di sini
     * hanya pemanggilan dan penerjemahan hasilnya.
     *
     * @return array{sukses:bool,pesan:string,meta:array<string,mixed>}
     */
    public static function sinkronBridging(?string $dari = null, ?string $sampai = null): array
    {
        if (!self::aktif()) {
            return ['sukses' => false, 'pesan' => 'Integrasi Khanza nonaktif.', 'meta' => []];
        }

        $q = [];
        if ($dari !== null && $dari !== '')     { $q['dari'] = $dari; }
        if ($sampai !== null && $sampai !== '') { $q['sampai'] = $sampai; }

        $url = self::baseUrl() . '/api/sync.php' . ($q === [] ? '' : '?' . http_build_query($q));
        $res = Http::get($url, self::headers(), 120, (bool) Config::get('khanza.verify_ssl', true));

        self::catat('keluar', 'sinkron_bridging', null, null, $url, null, $res);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'sukses' => false,
                'pesan'  => 'Sinkronisasi dari database Khanza gagal. ' . self::sebabGagal($res),
                'meta'   => [],
            ];
        }

        return [
            'sukses' => true,
            'pesan'  => (string) ($res['data']['pesan'] ?? 'Sinkronisasi selesai.'),
            'meta'   => is_array($res['data']['meta'] ?? null) ? $res['data']['meta'] : [],
        ];
    }

    // -----------------------------------------------------------------
    // MASUK — order dari Khanza
    // -----------------------------------------------------------------

    /**
     * Terima satu permintaan lab dari Khanza dan ubah menjadi order LIS.
     *
     * Bentuk payload mengikuti tabel bridging Khanza:
     * [
     *   'noorder' => 'LB0001', 'no_rawat' => '2026/08/31/000001',
     *   'no_rkm_medis' => '000123', 'nm_pasien' => 'BUDI',
     *   'jk' => 'L', 'tgl_lahir' => '1990-01-01', 'alamat' => '...',
     *   'tgl_permintaan' => '2026-08-31', 'jam_permintaan' => '08:00:00',
     *   'dokter_perujuk' => 'dr. Andi', 'status' => 'ralan',
     *   'kode_ruang' => 'POLI01', 'nama_ruang' => 'Poli Umum',
     *   'diagnosa_klinis' => '...', 'informasi_tambahan' => '...',
     *   'detail' => [ ['kd_jenis_prw'=>'LK001','id_template'=>12], ... ],
     * ]
     *
     * @param  array<string,mixed> $payload
     * @return array{sukses:bool,pesan:string,order_id:?int,no_order:?string,no_lab:?string,barcode:array<int,string>,tidak_dipetakan:array<int,string>}
     */
    public static function terimaOrder(array $payload): array
    {
        $noorder = trim((string) ($payload['noorder'] ?? ''));
        if ($noorder === '') {
            return self::gagalOrder('Field "noorder" wajib diisi.');
        }

        // Idempoten: order yang sama tidak dibuat dua kali.
        $ada = Database::selectOne(
            'SELECT id, no_order, no_lab FROM orders WHERE khanza_noorder = ? LIMIT 1',
            [$noorder]
        );
        if ($ada !== null) {
            return [
                'sukses'          => true,
                'pesan'           => 'Order sudah pernah diterima sebelumnya.',
                'order_id'        => (int) $ada['id'],
                'no_order'        => (string) $ada['no_order'],
                'no_lab'          => (string) $ada['no_lab'],
                'barcode'         => self::barcodeOrder((int) $ada['id']),
                'tidak_dipetakan' => [],
            ];
        }

        $detail = is_array($payload['detail'] ?? null) ? $payload['detail'] : [];
        if ($detail === []) {
            return self::gagalOrder('Order tidak memuat detail pemeriksaan.');
        }

        return Database::transaction(static function () use ($payload, $noorder, $detail): array {

            $patientId = PatientService::dariKhanza($payload);
            if ($patientId === null) {
                return self::gagalOrder('Data pasien tidak lengkap (no_rkm_medis dan nm_pasien wajib).');
            }

            // Petakan kd_jenis_prw + id_template ke pemeriksaan LIS.
            $testIds        = [];
            $tidakDipetakan = [];

            foreach ($detail as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $kd = trim((string) ($d['kd_jenis_prw'] ?? ''));
                $id = isset($d['id_template']) ? (int) $d['id_template'] : null;

                $testId = self::cariTest($kd, $id);
                if ($testId === null) {
                    $tidakDipetakan[] = $kd . ($id !== null ? '/' . $id : '');
                    self::catatTemplateBaru($kd, $id, (string) ($d['nm_perawatan'] ?? ''), (string) ($d['pemeriksaan'] ?? ''));
                    continue;
                }
                $testIds[] = $testId;
            }

            if ($testIds === []) {
                return self::gagalOrder(
                    'Tidak ada pemeriksaan yang cocok dengan master LIS. Lengkapi pemetaan di menu '
                    . 'Integrasi → Pemetaan Pemeriksaan. Kode belum dipetakan: ' . implode(', ', $tidakDipetakan)
                );
            }

            $tglOrder = trim(
                (string) ($payload['tgl_permintaan'] ?? date('Y-m-d')) . ' ' .
                (string) ($payload['jam_permintaan'] ?? date('H:i:s'))
            );

            // Barcode spesimen mengikuti nomor order Khanza, karena barcode
            // inilah yang dikirim ke alat sebagai Sample ID. Dengan begitu
            // angka yang tertempel di tabung, yang terbaca di layar alat, dan
            // yang tercatat di Khanza adalah satu angka yang sama.
            //
            // Nomor laboratorium TIDAK ikut: itu tetap nomor urut LIS.
            //
            // Order manual tidak terpengaruh — ia tidak punya nomor Khanza,
            // jadi tetap memakai OrderService::barcodeSpesimen().
            $barcodeDasar = null;
            if (Config::settingBool('khanza.barcode_dari_noorder', true)) {
                $barcodeDasar = $noorder;
            }

            $hasil = OrderService::buat([
                'patient_id'         => $patientId,
                'barcode_dasar'      => $barcodeDasar,
                'khanza_noorder'     => $noorder,
                'khanza_no_rawat'    => (string) ($payload['no_rawat'] ?? '') ?: null,
                'asal'               => self::petakanAsal((string) ($payload['status'] ?? 'ralan')),
                'kode_ruang'         => (string) ($payload['kode_ruang'] ?? '') ?: null,
                'nama_ruang'         => (string) ($payload['nama_ruang'] ?? '') ?: null,
                'kode_carabayar'     => (string) ($payload['kode_carabayar'] ?? '') ?: null,
                'nama_carabayar'     => (string) ($payload['nama_carabayar'] ?? '') ?: null,
                'dokter_perujuk'     => (string) ($payload['dokter_perujuk'] ?? '') ?: null,
                'diagnosa_klinis'    => (string) ($payload['diagnosa_klinis'] ?? '') ?: null,
                'informasi_tambahan' => (string) ($payload['informasi_tambahan'] ?? '') ?: null,
                'prioritas'          => self::petakanPrioritas($payload),
                'tgl_order'          => date('Y-m-d H:i:s', strtotime($tglOrder) ?: time()),
                'sumber'             => 'khanza',
                'created_by'         => null,
            ], $testIds);

            // Simpan referensi Khanza pada tiap item agar hasil bisa dikirim balik.
            foreach ($detail as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $kd     = trim((string) ($d['kd_jenis_prw'] ?? ''));
                $idTpl  = isset($d['id_template']) ? (int) $d['id_template'] : null;
                $testId = self::cariTest($kd, $idTpl);
                if ($testId === null) {
                    continue;
                }
                Database::update('order_items', [
                    'khanza_kd_jenis_prw' => $kd,
                    'khanza_id_template'  => $idTpl,
                ], 'order_id = ? AND test_id = ?', [$hasil['order_id'], $testId]);
            }

            // Order tetap dibuat walau sebagian pemeriksaan tidak terpetakan —
            // menolak seluruhnya akan menahan pemeriksaan yang sudah benar.
            // Tetapi kehilangan itu harus terlihat: tanpa catatan ini, satu-
            // satunya jejaknya ada di dialog Khanza yang sudah ditutup, dan
            // gejalanya baru muncul jauh kemudian sebagai hasil alat yang
            // "tidak diminta pada order".
            if ($tidakDipetakan !== []) {
                Logger::warning('Sebagian pemeriksaan Khanza tidak terpetakan dan tidak masuk order.', [
                    'noorder'         => $noorder,
                    'no_order_lis'    => (string) $hasil['no_order'],
                    'diminta'         => count($detail),
                    'masuk'           => count($testIds),
                    'tidak_dipetakan' => implode(', ', $tidakDipetakan),
                ]);
            }

            // Penerimaan otomatis untuk kiriman SIMRS.
            $otomatis = Config::settingBool('khanza.auto_terima', true)
                ? self::terimaOtomatis((int) $hasil['order_id'], $payload)
                : 0;

            self::catat('masuk', 'order', 'order', (string) $hasil['order_id'], null, $payload, ['ok' => true, 'status' => 200, 'body' => '', 'error' => null]);

            Audit::log(
                'terima_order_khanza',
                'order',
                (string) $hasil['order_id'],
                'noorder Khanza: ' . $noorder . ' → ' . $hasil['no_order']
                . ($otomatis > 0 ? ' — ' . $otomatis . ' spesimen diterima otomatis' : '')
            );

            return [
                'sukses'          => true,
                'pesan'           => 'Order berhasil dibuat.',
                'order_id'        => $hasil['order_id'],
                'no_order'        => $hasil['no_order'],
                'no_lab'          => $hasil['no_lab'],
                'barcode'         => $hasil['barcode'],
                'tidak_dipetakan' => $tidakDipetakan,
            ];
        });
    }

    /** @return array{sukses:false,pesan:string,order_id:null,no_order:null,no_lab:null,barcode:array<int,string>,tidak_dipetakan:array<int,string>} */
    private static function gagalOrder(string $pesan): array
    {
        return [
            'sukses'          => false,
            'pesan'           => $pesan,
            'order_id'        => null,
            'no_order'        => null,
            'no_lab'          => null,
            'barcode'         => [],
            'tidak_dipetakan' => [],
        ];
    }

    /** @return array<int,string> */
    private static function barcodeOrder(int $orderId): array
    {
        return array_column(
            Database::select('SELECT barcode FROM specimens WHERE order_id = ?', [$orderId]),
            'barcode'
        );
    }

    /**
     * Pemetaan kd_jenis_prw + id_template → tests.id.
     * Urutan pencarian: pemetaan penuh, lalu cache template, lalu kd saja.
     */
    /**
     * Terima spesimen order Khanza tanpa menunggu petugas menandainya.
     *
     * Menghemat satu langkah manual untuk permintaan yang memang sudah
     * diketahui datang dari SIMRS, sehingga order langsung masuk worklist
     * alat begitu tabungnya sampai.
     *
     * YANG PERLU DISADARI SEBELUM MENGAKTIFKANNYA
     *
     * Penerimaan spesimen bukan sekadar pergantian status. Di situlah
     * petugas menyatakan tabungnya benar-benar sampai dan memeriksa
     * kondisinya — lisis, ikterik, lipemik, volume kurang. Penerimaan
     * otomatis menyatakan hal pertama tanpa ada yang melihatnya, dan
     * melewatkan hal kedua sepenuhnya.
     *
     * Karena itu:
     *   - received_by sengaja NULL. Tidak ada orang yang menerimanya, dan
     *     mencatatkan nama siapa pun di situ akan menjadi keterangan palsu
     *     pada rekaman yang dipakai menelusuri sengketa hasil.
     *   - kondisi TIDAK diklaim "baik". Kolom catatan menyatakan terang-
     *     terangan bahwa tabungnya belum diperiksa.
     *   - collected_at hanya diisi bila Khanza benar-benar mengirim tanggal
     *     dan jam sampel. Bila tidak, dibiarkan kosong — waktu pengambilan
     *     yang dikarang lebih buruk daripada waktu yang tidak diketahui.
     *
     * Matikan lewat pengaturan khanza.auto_terima bila laboratorium
     * menghendaki setiap tabung tetap discan saat diterima.
     *
     * @param  array<string,mixed> $payload
     * @return int Jumlah spesimen yang diterima otomatis
     */
    private static function terimaOtomatis(int $orderId, array $payload): int
    {
        $spesimen = Database::select(
            "SELECT id FROM specimens WHERE order_id = ? AND status = 'pending'",
            [$orderId]
        );

        if ($spesimen === []) {
            return 0;
        }

        $sekarang = date('Y-m-d H:i:s');

        // Waktu pengambilan hanya dari data Khanza yang sebenarnya.
        $diambil = null;
        $tgl     = trim((string) ($payload['tgl_sampel'] ?? ''));
        $jam     = trim((string) ($payload['jam_sampel'] ?? ''));

        if ($tgl !== '' && $tgl !== '0000-00-00') {
            $stempel = strtotime($tgl . ' ' . ($jam !== '' ? $jam : '00:00:00'));
            if ($stempel !== false) {
                $diambil = date('Y-m-d H:i:s', $stempel);
            }
        }

        $catatan = 'Diterima otomatis dari SIMRS Khanza. Kondisi spesimen belum diperiksa petugas.';

        foreach ($spesimen as $s) {
            $ubah = [
                'status'      => 'received',
                'received_at' => $sekarang,
                'received_by' => null,
                'catatan'     => $catatan,
            ];
            if ($diambil !== null) {
                $ubah['collected_at'] = $diambil;
            }

            Database::update('specimens', $ubah, 'id = ?', [(int) $s['id']]);

            Database::execute(
                "UPDATE order_items SET status = 'received'
                  WHERE specimen_id = ? AND status IN ('pending','collected')",
                [(int) $s['id']]
            );
        }

        OrderService::segarkanStatus($orderId);

        Audit::log(
            'terima_spesimen_otomatis',
            'order',
            (string) $orderId,
            count($spesimen) . ' spesimen diterima otomatis (kiriman SIMRS Khanza, tanpa pemeriksaan kondisi)'
        );

        return count($spesimen);
    }

    private static function cariTest(string $kdJenisPrw, ?int $idTemplate): ?int
    {
        if ($kdJenisPrw === '') {
            return null;
        }

        if ($idTemplate !== null) {
            $id = Database::scalar(
                'SELECT id FROM tests WHERE khanza_kd_jenis_prw = ? AND khanza_id_template = ? AND aktif = 1 LIMIT 1',
                [$kdJenisPrw, $idTemplate]
            );
            if ($id !== null) {
                return (int) $id;
            }

            $id = Database::scalar(
                'SELECT test_id FROM khanza_templates WHERE kd_jenis_prw = ? AND id_template = ? AND test_id IS NOT NULL LIMIT 1',
                [$kdJenisPrw, $idTemplate]
            );
            if ($id !== null) {
                return (int) $id;
            }
        }

        $id = Database::scalar(
            'SELECT id FROM tests WHERE khanza_kd_jenis_prw = ? AND khanza_id_template IS NULL AND aktif = 1 LIMIT 1',
            [$kdJenisPrw]
        );

        return $id === null ? null : (int) $id;
    }

    private static function catatTemplateBaru(string $kd, ?int $idTemplate, string $nmPerawatan, string $pemeriksaan): void
    {
        if ($kd === '' || $idTemplate === null) {
            return;
        }
        try {
            Database::execute(
                'INSERT INTO khanza_templates (kd_jenis_prw, nm_perawatan, id_template, pemeriksaan)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE nm_perawatan = VALUES(nm_perawatan), pemeriksaan = VALUES(pemeriksaan)',
                [$kd, $nmPerawatan !== '' ? $nmPerawatan : null, $idTemplate, $pemeriksaan !== '' ? $pemeriksaan : null]
            );
        } catch (\Throwable $e) {
            Logger::warning('Gagal mencatat template Khanza: ' . $e->getMessage());
        }
    }

    private static function petakanAsal(string $status): string
    {
        return match (strtolower($status)) {
            'ranap'  => 'ranap',
            'igd'    => 'igd',
            'mcu'    => 'mcu',
            'luar'   => 'luar',
            default  => 'ralan',
        };
    }

    /** @param array<string,mixed> $payload */
    private static function petakanPrioritas(array $payload): string
    {
        $teks = strtolower(
            (string) ($payload['prioritas'] ?? '') . ' ' .
            (string) ($payload['informasi_tambahan'] ?? '')
        );

        return (str_contains($teks, 'cito') || str_contains($teks, 'urgent') || trim($teks) === 'u')
            ? 'cito' : 'rutin';
    }

    // -----------------------------------------------------------------
    // KELUAR — hasil ke Khanza
    // -----------------------------------------------------------------

    /**
     * Susun payload hasil satu order dalam format yang dipahami konektor.
     *
     * @return array<string,mixed>|null null bila order tidak berasal dari Khanza
     */
    public static function payloadHasil(int $orderId): ?array
    {
        $order = OrderService::detail($orderId);
        if ($order === null || ($order['khanza_noorder'] ?? null) === null) {
            return null;
        }

        $baris = [];
        foreach (OrderService::hasilOrder($orderId) as $h) {
            if ($h['result_id'] === null) {
                continue;
            }
            if (!in_array((string) $h['status_hasil'], ['verified', 'corrected'], true)) {
                continue;
            }
            if ($h['khanza_kd_jenis_prw'] === null) {
                continue; // item ini tidak berasal dari Khanza
            }

            $keterangan = trim((string) ($h['catatan'] ?? ''));
            if ((string) $h['flag'] !== '' && (string) $h['flag'] !== 'N') {
                $label      = Helper::labelFlag((string) $h['flag']);
                $keterangan = trim($label . ' ' . $keterangan);
            }

            $baris[] = [
                'kd_jenis_prw'  => (string) $h['khanza_kd_jenis_prw'],
                'id_template'   => $h['khanza_id_template'] === null ? 0 : (int) $h['khanza_id_template'],
                'nilai'         => (string) $h['nilai'],
                'nilai_rujukan' => mb_substr((string) ($h['ref_teks'] ?? ''), 0, 30),
                'keterangan'    => mb_substr($keterangan, 0, 60),
                // Field tambahan (diabaikan konektor bila tidak dipakai):
                'pemeriksaan'   => (string) $h['nama_test'],
                'satuan'        => (string) ($h['satuan'] ?? $h['satuan_master'] ?? ''),
                'flag'          => (string) $h['flag'],
            ];
        }

        if ($baris === []) {
            return null;
        }

        $petugas = Database::selectOne(
            'SELECT u.nama, u.nip FROM results r
             JOIN users u ON u.id = r.verified_by
             WHERE r.order_id = ? AND r.verified_by IS NOT NULL
             ORDER BY r.verified_at DESC LIMIT 1',
            [$orderId]
        );

        return [
            'noorder'       => (string) $order['khanza_noorder'],
            'no_rawat'      => (string) ($order['khanza_no_rawat'] ?? ''),
            'no_rkm_medis'  => (string) ($order['khanza_no_rkm_medis'] ?? ''),
            'no_lab'        => (string) ($order['no_lab'] ?? ''),
            'tgl_hasil'     => date('Y-m-d'),
            'jam_hasil'     => date('H:i:s'),
            'petugas'       => (string) ($petugas['nama'] ?? ''),
            'nip_petugas'   => (string) ($petugas['nip'] ?? ''),
            'detail'        => $baris,
        ];
    }

    /**
     * Kirim hasil sebuah order ke Khanza. Bila gagal, entri antrian
     * disimpan untuk dicoba ulang oleh bin/sync_khanza.php.
     *
     * @return array{sukses:bool,pesan:string}
     */
    public static function kirimHasil(int $orderId, bool $antrikanBilaGagal = true): array
    {
        if (!self::aktif()) {
            return ['sukses' => false, 'pesan' => 'Integrasi Khanza sedang nonaktif.'];
        }

        $payload = self::payloadHasil($orderId);
        if ($payload === null) {
            return ['sukses' => false, 'pesan' => 'Order ini bukan berasal dari Khanza atau belum ada hasil terverifikasi.'];
        }

        $url  = self::baseUrl() . '/api/results.php';
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $res  = Http::post(
            $url,
            $payload,
            self::headers($body),
            (int) Config::get('khanza.timeout', 15),
            (bool) Config::get('khanza.verify_ssl', true)
        );

        $logId = self::catat('keluar', 'hasil', 'order', (string) $orderId, $url, $payload, $res);

        if ($res['ok'] && (($res['data']['sukses'] ?? true) !== false)) {
            Database::update('khanza_sync_log', ['status' => 'sukses'], 'id = ?', [$logId]);

            return ['sukses' => true, 'pesan' => 'Hasil terkirim ke SIMRS Khanza.'];
        }

        $pesan = self::sebabGagal($res);

        if ($antrikanBilaGagal) {
            Database::update('khanza_sync_log', [
                'status'        => 'antri',
                'percobaan'     => 1,
                'next_retry_at' => date('Y-m-d H:i:s', time() + 300),
                'pesan'         => mb_substr($pesan, 0, 500),
            ], 'id = ?', [$logId]);
        }

        Logger::warning('Pengiriman hasil ke Khanza gagal', ['order_id' => $orderId, 'pesan' => $pesan]);

        return ['sukses' => false, 'pesan' => 'Gagal mengirim ke Khanza: ' . $pesan . ' (masuk antrian kirim ulang).'];
    }

    /**
     * Proses antrian pengiriman yang tertunda. Dipanggil dari
     * bin/sync_khanza.php (cron) atau tombol di menu Integrasi.
     *
     * @return array{diproses:int,sukses:int,gagal:int}
     */
    public static function prosesAntrian(int $maks = 50): array
    {
        $antrian = Database::select(
            'SELECT * FROM khanza_sync_log
             WHERE status = \'antri\' AND arah = \'keluar\'
               AND (next_retry_at IS NULL OR next_retry_at <= NOW())
               AND percobaan < 10
             ORDER BY id ASC LIMIT ' . max(1, min(500, $maks))
        );

        $sukses = 0;
        $gagal  = 0;

        foreach ($antrian as $item) {
            $payload = json_decode((string) $item['payload'], true);
            if (!is_array($payload)) {
                Database::update('khanza_sync_log', [
                    'status' => 'gagal',
                    'pesan'  => 'Payload tidak dapat dibaca.',
                ], 'id = ?', [$item['id']]);
                $gagal++;
                continue;
            }

            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
            $res  = Http::post(
                (string) $item['endpoint'],
                $payload,
                self::headers($body),
                (int) Config::get('khanza.timeout', 15),
                (bool) Config::get('khanza.verify_ssl', true)
            );

            $percobaan = (int) $item['percobaan'] + 1;

            if ($res['ok'] && (($res['data']['sukses'] ?? true) !== false)) {
                Database::update('khanza_sync_log', [
                    'status'    => 'sukses',
                    'percobaan' => $percobaan,
                    'http_code' => $res['status'],
                    'response'  => mb_substr($res['body'], 0, 60000),
                    'pesan'     => null,
                ], 'id = ?', [$item['id']]);
                $sukses++;
                continue;
            }

            // Backoff eksponensial: 5, 10, 20, 40 menit … maksimum 8 jam.
            $tunda = min(28800, 300 * (2 ** min($percobaan - 1, 6)));

            Database::update('khanza_sync_log', [
                'status'        => $percobaan >= 10 ? 'gagal' : 'antri',
                'percobaan'     => $percobaan,
                'http_code'     => $res['status'],
                'response'      => mb_substr($res['body'], 0, 60000),
                'next_retry_at' => date('Y-m-d H:i:s', time() + $tunda),
                'pesan'         => mb_substr(self::sebabGagal($res), 0, 500),
            ], 'id = ?', [$item['id']]);
            $gagal++;
        }

        return ['diproses' => count($antrian), 'sukses' => $sukses, 'gagal' => $gagal];
    }

    /**
     * Tarik order baru dari Khanza (mode pull).
     *
     * @return array{diambil:int,dibuat:int,gagal:int,pesan:string}
     */
    public static function tarikOrder(int $limit = 50): array
    {
        if (!self::aktif()) {
            return ['diambil' => 0, 'dibuat' => 0, 'gagal' => 0, 'pesan' => 'Integrasi Khanza nonaktif.'];
        }

        $url = self::baseUrl() . '/api/orders.php?limit=' . $limit;
        $res = Http::get($url, self::headers(), (int) Config::get('khanza.timeout', 15), (bool) Config::get('khanza.verify_ssl', true));

        self::catat('keluar', 'tarik_order', null, null, $url, null, $res);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'diambil' => 0, 'dibuat' => 0, 'gagal' => 0,
                'pesan'   => 'Gagal menarik order dari Khanza. ' . self::sebabGagal($res),
            ];
        }

        $orders = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : [];
        $dibuat = 0;
        $gagal  = 0;
        $diambil = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            try {
                $hasil = self::terimaOrder($order);
                if ($hasil['sukses']) {
                    $dibuat++;
                    $diambil[] = (string) ($order['noorder'] ?? '');
                } else {
                    $gagal++;
                    Logger::warning('Order Khanza ditolak', ['noorder' => $order['noorder'] ?? '?', 'pesan' => $hasil['pesan']]);
                }
            } catch (\Throwable $e) {
                $gagal++;
                Logger::exception($e);
            }
        }

        // Beri tahu konektor order mana yang sudah diambil.
        if ($diambil !== []) {
            $ackBody = ['noorder' => $diambil];
            Http::post(
                self::baseUrl() . '/api/orders.php?aksi=ack',
                $ackBody,
                self::headers((string) json_encode($ackBody, JSON_UNESCAPED_UNICODE)),
                (int) Config::get('khanza.timeout', 15),
                (bool) Config::get('khanza.verify_ssl', true)
            );
        }

        return [
            'diambil' => count($orders),
            'dibuat'  => $dibuat,
            'gagal'   => $gagal,
            'pesan'   => sprintf('%d order diterima, %d dibuat, %d gagal.', count($orders), $dibuat, $gagal),
        ];
    }

    /**
     * Impor master template laboratorium Khanza untuk keperluan pemetaan.
     *
     * @return array{sukses:bool,jumlah:int,pesan:string}
     */
    public static function imporTemplate(): array
    {
        if (!self::aktif()) {
            return ['sukses' => false, 'jumlah' => 0, 'pesan' => 'Integrasi Khanza nonaktif.'];
        }

        // -------------------------------------------------------------
        // Diambil BERHALAMAN sampai habis.
        //
        // Sebelumnya LIS memanggil /api/templates.php sekali tanpa
        // parameter apa pun, dan konektor membalas paling banyak 5000
        // baris — lalu keduanya melaporkan keberhasilan. Laboratorium
        // dengan template lebih banyak kehilangan sisanya diam-diam:
        // pemeriksaan yang tidak terimpor tidak pernah muncul di layar
        // pemetaan, sehingga order Khanza yang memakainya selalu ditolak
        // dengan "tidak dipetakan" tanpa sebab yang terlihat.
        //
        // Kini LIS terus meminta halaman berikutnya selama konektor
        // menyatakan masih ada sisa.
        // -------------------------------------------------------------
        $perHalaman = 2000;
        $offset     = 0;
        $jumlah     = 0;
        $dilewati   = [];
        $total      = null;
        $putaran    = 0;

        do {
            $url = self::baseUrl() . '/api/templates.php?limit=' . $perHalaman . '&offset=' . $offset;
            $res = Http::get($url, self::headers(), 60, (bool) Config::get('khanza.verify_ssl', true));

            self::catat('keluar', 'template', null, null, $url, null, $res);

            if (!$res['ok'] || !is_array($res['data'])) {
                // Kegagalan di tengah tidak boleh dilaporkan sebagai sukses
                // sebagian tanpa keterangan.
                return [
                    'sukses' => false,
                    'jumlah' => $jumlah,
                    'pesan'  => sprintf(
                        'Gagal mengambil template dari Khanza pada halaman ke-%d (offset %d). %s'
                        . ' %d template sempat tersimpan sebelum kegagalan.',
                        $putaran + 1,
                        $offset,
                        self::sebabGagal($res),
                        $jumlah
                    ),
                ];
            }

            $rows = is_array($res['data']['data'] ?? null) ? $res['data']['data'] : [];
            $meta = is_array($res['data']['meta'] ?? null) ? $res['data']['meta'] : [];

            if (isset($meta['total'])) {
                $total = (int) $meta['total'];
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $dilewati[] = 'baris bukan objek';
                    continue;
                }

                $kd  = trim((string) ($row['kd_jenis_prw'] ?? ''));
                $idt = isset($row['id_template']) && $row['id_template'] !== null
                    ? (int) $row['id_template'] : null;

                if ($kd === '' || $idt === null) {
                    // Dicatat, bukan dibuang diam-diam.
                    $dilewati[] = sprintf(
                        'kd_jenis_prw "%s" / id_template %s tidak lengkap',
                        $kd === '' ? '(kosong)' : $kd,
                        $idt === null ? '(kosong)' : (string) $idt
                    );
                    continue;
                }

                try {
                    Database::execute(
                        'INSERT INTO khanza_templates
                            (kd_jenis_prw, nm_perawatan, id_template, pemeriksaan, satuan,
                             nilai_rujukan_ld, nilai_rujukan_la, nilai_rujukan_pd, nilai_rujukan_pa, synced_at)
                         VALUES (?,?,?,?,?,?,?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE
                            nm_perawatan     = VALUES(nm_perawatan),
                            pemeriksaan      = VALUES(pemeriksaan),
                            satuan           = VALUES(satuan),
                            nilai_rujukan_ld = VALUES(nilai_rujukan_ld),
                            nilai_rujukan_la = VALUES(nilai_rujukan_la),
                            nilai_rujukan_pd = VALUES(nilai_rujukan_pd),
                            nilai_rujukan_pa = VALUES(nilai_rujukan_pa),
                            synced_at        = NOW()',
                        [
                            $kd,
                            self::ambil($row, ['nm_perawatan', 'nama_perawatan']),
                            $idt,
                            self::ambil($row, ['Pemeriksaan', 'pemeriksaan']),
                            self::ambil($row, ['satuan']),
                            self::ambil($row, ['nilai_rujukan_ld', 'nilai_rujukan_LD']),
                            self::ambil($row, ['nilai_rujukan_la', 'nilai_rujukan_LA']),
                            self::ambil($row, ['nilai_rujukan_pd', 'nilai_rujukan_PD']),
                            self::ambil($row, ['nilai_rujukan_pa', 'nilai_rujukan_PA']),
                        ]
                    );
                    $jumlah++;
                } catch (\Throwable $e) {
                    // Satu baris bermasalah — mis. teks melebihi panjang kolom —
                    // dahulu melempar keluar dan menghentikan SELURUH impor di
                    // tengah jalan. Kini barisnya saja yang dilewati, dan
                    // sebabnya ikut dilaporkan.
                    $dilewati[] = sprintf('%s/%d: %s', $kd, $idt, $e->getMessage());
                    Logger::warning('Template Khanza gagal disimpan', [
                        'kd_jenis_prw' => $kd,
                        'id_template'  => $idt,
                        'pesan'        => $e->getMessage(),
                    ]);
                }
            }

            $diterima = count($rows);
            $offset  += $diterima;
            $putaran++;

            $sisa = array_key_exists('sisa', $meta) ? $meta['sisa'] : null;

            // Berhenti bila konektor bilang habis, halaman kosong, atau
            // konektor lama yang belum mengirim meta (satu putaran saja).
            $lanjut = $diterima > 0
                && $sisa !== null
                && (int) $sisa > 0
                && $putaran < 50;   // pagar pengaman agar tidak berputar selamanya
        } while ($lanjut);

        $otomatis = self::cocokkanOtomatis();

        $pesan = sprintf('%d template diimpor, %d dipetakan otomatis berdasarkan nama.', $jumlah, $otomatis);

        if ($total !== null && $jumlah < $total) {
            $pesan .= sprintf(' PERHATIAN: Khanza memiliki %d baris, %d tidak tersimpan.', $total, $total - $jumlah);
        }

        if ($dilewati !== []) {
            $contoh = array_slice(array_unique($dilewati), 0, 5);
            $pesan .= sprintf(
                ' %d baris dilewati (%s%s).',
                count($dilewati),
                implode('; ', $contoh),
                count($dilewati) > count($contoh) ? '; …' : ''
            );

            Logger::warning('Impor template Khanza melewati sebagian baris', [
                'jumlah_dilewati' => count($dilewati),
                'contoh'          => $contoh,
            ]);
        }

        return [
            'sukses'   => true,
            'jumlah'   => $jumlah,
            'total'    => $total,
            'dilewati' => count($dilewati),
            'pesan'    => $pesan,
        ];
    }

    /**
     * Cocokkan template Khanza yang belum terpetakan dengan master LIS
     * berdasarkan kesamaan nama (setelah normalisasi).
     */
    public static function cocokkanOtomatis(): int
    {
        $templates = Database::select(
            'SELECT id, pemeriksaan FROM khanza_templates WHERE test_id IS NULL AND pemeriksaan IS NOT NULL'
        );
        $tests = Database::select('SELECT id, kode, nama, nama_singkat FROM tests WHERE aktif = 1');

        $indeks = [];
        foreach ($tests as $t) {
            foreach ([$t['nama'], $t['nama_singkat'], $t['kode']] as $kandidat) {
                $norm = self::normalisasi((string) $kandidat);
                if ($norm !== '' && !isset($indeks[$norm])) {
                    $indeks[$norm] = (int) $t['id'];
                }
            }
        }

        $cocok = 0;
        foreach ($templates as $tpl) {
            $norm = self::normalisasi((string) $tpl['pemeriksaan']);
            if ($norm === '' || !isset($indeks[$norm])) {
                continue;
            }
            Database::update('khanza_templates', ['test_id' => $indeks[$norm]], 'id = ?', [$tpl['id']]);
            $cocok++;
        }

        return $cocok;
    }

    private static function normalisasi(string $teks): string
    {
        $teks = strtolower(trim($teks));
        $teks = preg_replace('/\(.*?\)/', '', $teks) ?? $teks;
        $teks = preg_replace('/[^a-z0-9]+/', '', $teks) ?? $teks;

        return $teks;
    }

    /** @param array<string,mixed> $row @param array<int,string> $kunci */
    private static function ambil(array $row, array $kunci): ?string
    {
        foreach ($kunci as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return (string) $row[$k];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Log
    // -----------------------------------------------------------------

    /**
     * @param array<mixed>|null $payload
     * @param array{ok:bool,status:int,body:string,error:?string}|null $res
     */
    private static function catat(
        string $arah,
        string $jenis,
        ?string $refType,
        ?string $refId,
        ?string $endpoint,
        ?array $payload,
        ?array $res
    ): int {
        try {
            return Database::insert('khanza_sync_log', [
                'arah'      => $arah,
                'jenis'     => $jenis,
                'ref_type'  => $refType,
                'ref_id'    => $refId,
                'endpoint'  => $endpoint === null ? null : mb_substr($endpoint, 0, 255),
                'payload'   => $payload === null ? null : mb_substr((string) json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 60000),
                'response'  => $res === null ? null : mb_substr($res['body'], 0, 60000),
                'http_code' => $res['status'] ?? null,
                'status'    => ($res === null || $res['ok']) ? 'sukses' : 'gagal',
                'pesan'     => $res === null ? null : ($res['error'] === null ? null : mb_substr($res['error'], 0, 500)),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Gagal menulis khanza_sync_log: ' . $e->getMessage());

            return 0;
        }
    }
}
