<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Pelaporan nilai kritis — CLSI GP47.
 *
 * MENGAPA INI ADA
 *
 * Tabel results sudah memiliki kolom kritis_dilapor_ke / _at / _by, dan
 * kolom itu memang cukup untuk MENCATAT bahwa pelaporan sudah terjadi.
 * Yang tidak dapat dilakukannya adalah menjawab pertanyaan yang justru
 * paling penting:
 *
 *   "Nilai kritis mana yang sampai sekarang BELUM dilaporkan?"
 *
 * Kolom kosong tidak berbunyi. Nilai kalium 7,2 mmol/L yang terlewat
 * pada pergantian shift tidak akan muncul di mana pun sampai seseorang
 * kebetulan membuka hasilnya. GP47 karena itu menuntut tiga hal yang
 * tidak dapat diwakili satu kolom tanggal:
 *
 *   1. Tenggat waktu terukur sejak nilai terdeteksi, bukan sejak
 *      seseorang ingat.
 *   2. Antrean tertunggak yang aktif ditampilkan sampai dituntaskan.
 *   3. Pembacaan ulang (read-back): penerima mengulang angka yang
 *      didengarnya, dan kecocokannya dicatat. Salah dengar di telepon
 *      adalah moda kegagalan yang nyata — "seven point two" terdengar
 *      seperti "seventy two".
 *
 * Kelas ini menyimpan proses itu; kolom lama pada results tetap diisi
 * agar lembar hasil dan laporan yang sudah ada tidak berubah perilaku.
 */
final class CriticalValueService
{
    /**
     * Membuka catatan pelaporan untuk satu hasil kritis.
     *
     * Aman dipanggil berulang: satu hasil hanya punya satu catatan
     * (dijamin indeks unik uq_critnotif_result).
     */
    public static function buka(int $resultId): ?int
    {
        $r = Database::selectOne(
            'SELECT r.id, r.order_id, r.test_id, r.nilai, r.satuan, r.flag,
                    r.is_kritis, r.entered_at,
                    o.patient_id,
                    t.kritis_batas_menit
               FROM results r
               JOIN orders o ON o.id = r.order_id
               JOIN tests  t ON t.id = r.test_id
              WHERE r.id = ? LIMIT 1',
            [$resultId]
        );

        if ($r === null || (int) $r['is_kritis'] !== 1) {
            return null;
        }

        $ada = Database::selectOne(
            'SELECT id FROM critical_notifications WHERE result_id = ? LIMIT 1',
            [$resultId]
        );
        if ($ada !== null) {
            return (int) $ada['id'];
        }

        // Batas per pemeriksaan lebih diutamakan daripada batas global:
        // troponin dan kalium tidak pantas diberi tenggat yang sama
        // dengan hemoglobin.
        $batas = (int) ($r['kritis_batas_menit'] ?? 0);
        if ($batas <= 0) {
            $batas = Config::settingInt('kritis.batas_menit', 30);
        }

        $terdeteksi = (string) ($r['entered_at'] ?? date('Y-m-d H:i:s'));
        $tempo      = date('Y-m-d H:i:s', strtotime($terdeteksi) + $batas * 60);

        try {
            $id = Database::insert('critical_notifications', [
                'result_id'      => $resultId,
                'order_id'       => (int) $r['order_id'],
                'patient_id'     => (int) $r['patient_id'],
                'test_id'        => (int) $r['test_id'],
                'nilai'          => $r['nilai'],
                'satuan'         => $r['satuan'],
                'flag'           => $r['flag'],
                'terdeteksi_at'  => $terdeteksi,
                'batas_menit'    => $batas,
                'jatuh_tempo_at' => $tempo,
                'status'         => 'menunggu',
            ]);
        } catch (\Throwable $e) {
            // Perlombaan dua proses yang menyimpan hasil yang sama.
            $ada = Database::selectOne(
                'SELECT id FROM critical_notifications WHERE result_id = ? LIMIT 1',
                [$resultId]
            );
            if ($ada !== null) {
                return (int) $ada['id'];
            }
            Logger::warning('Gagal membuka catatan nilai kritis: ' . $e->getMessage());
            return null;
        }

        Logger::warning('Nilai kritis terdeteksi — menunggu pelaporan', [
            'result_id'      => $resultId,
            'jatuh_tempo_at' => $tempo,
            'batas_menit'    => $batas,
        ]);

        return $id;
    }

    /**
     * Mencatat bahwa nilai kritis sudah dilaporkan.
     *
     * @param array{
     *   penerima_nama:string, penerima_peran?:string, cara?:string,
     *   bacaan_ulang?:string, catatan?:string
     * } $data
     * @return array{ok:bool, pesan:string}
     */
    public static function lapor(int $notifId, int $userId, array $data): array
    {
        $n = Database::selectOne(
            'SELECT cn.*, r.nilai AS nilai_hasil
               FROM critical_notifications cn
               JOIN results r ON r.id = cn.result_id
              WHERE cn.id = ? LIMIT 1',
            [$notifId]
        );

        if ($n === null) {
            return ['ok' => false, 'pesan' => 'Catatan nilai kritis tidak ditemukan.'];
        }

        if ((string) $n['status'] === 'terlapor') {
            return ['ok' => false, 'pesan' => 'Nilai kritis ini sudah dilaporkan.'];
        }

        $penerima = trim((string) ($data['penerima_nama'] ?? ''));
        if ($penerima === '') {
            return ['ok' => false, 'pesan' => 'Nama penerima laporan wajib diisi.'];
        }

        $wajibBaca = Config::settingBool('kritis.wajib_baca_ulang', true);
        $bacaan    = trim((string) ($data['bacaan_ulang'] ?? ''));
        $cocok     = null;

        if ($bacaan !== '') {
            $cocok = self::samaSecaraAngka($bacaan, (string) $n['nilai_hasil']);
        }

        if ($wajibBaca) {
            if ($bacaan === '') {
                return [
                    'ok'    => false,
                    'pesan' => 'Pembacaan ulang oleh penerima wajib dicatat. '
                        . 'Mintalah penerima mengulang nilainya, lalu ketik apa yang ia sebutkan.',
                ];
            }
            if ($cocok !== true) {
                return [
                    'ok'    => false,
                    'pesan' => sprintf(
                        'Pembacaan ulang "%s" tidak sama dengan nilai hasil "%s". '
                        . 'Ulangi penyampaian sampai penerima menyebut angka yang benar.',
                        $bacaan,
                        (string) $n['nilai_hasil']
                    ),
                ];
            }
        }

        $sekarang  = date('Y-m-d H:i:s');
        $terlambat = (int) round(
            (strtotime($sekarang) - strtotime((string) $n['terdeteksi_at'])) / 60
        );
        $lewat = $terlambat > (int) $n['batas_menit'];

        Database::transaction(static function () use (
            $notifId, $userId, $data, $penerima, $bacaan, $cocok, $sekarang, $terlambat, $lewat, $n
        ): void {
            Database::update('critical_notifications', [
                'status'          => 'terlapor',
                'dilapor_at'      => $sekarang,
                'dilapor_by'      => $userId,
                'cara'            => in_array((string) ($data['cara'] ?? ''), ['telepon','langsung','wa','sistem','lainnya'], true)
                    ? (string) $data['cara'] : 'telepon',
                'penerima_nama'   => mb_substr($penerima, 0, 100),
                'penerima_peran'  => mb_substr(trim((string) ($data['penerima_peran'] ?? '')), 0, 60),
                'bacaan_ulang'    => $bacaan !== '' ? mb_substr($bacaan, 0, 60) : null,
                'bacaan_cocok'    => $cocok === null ? null : ($cocok ? 1 : 0),
                'terlambat_menit' => $lewat ? $terlambat - (int) $n['batas_menit'] : 0,
                'catatan'         => mb_substr(trim((string) ($data['catatan'] ?? '')), 0, 255),
            ], 'id = ?', [$notifId]);

            // Kolom lama pada results tetap diisi agar lembar hasil dan
            // laporan yang sudah berjalan tidak perlu diubah.
            Database::update('results', [
                'kritis_dilapor_ke' => mb_substr($penerima, 0, 100),
                'kritis_dilapor_at' => $sekarang,
                'kritis_dilapor_by' => $userId,
            ], 'id = ?', [(int) $n['result_id']]);
        });

        return [
            'ok'    => true,
            'pesan' => $lewat
                ? sprintf('Pelaporan tercatat, namun melewati tenggat %d menit.', $terlambat - (int) $n['batas_menit'])
                : sprintf('Pelaporan tercatat dalam %d menit.', $terlambat),
        ];
    }

    /**
     * Menandai catatan yang sudah lewat tenggat.
     * Dipanggil saat membuka daftar tertunggak dan oleh tugas terjadwal.
     */
    public static function tandaiTerlambat(): int
    {
        return Database::execute(
            "UPDATE critical_notifications
                SET status = 'terlambat'
              WHERE status = 'menunggu'
                AND jatuh_tempo_at < NOW()"
        );
    }

    /**
     * Daftar nilai kritis yang belum tuntas, terlama lebih dulu.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function tertunggak(int $limit = 100): array
    {
        self::tandaiTerlambat();

        return Database::select(
            'SELECT * FROM v_kritis_tertunggak ORDER BY terdeteksi_at ASC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /** Berapa banyak yang belum dilaporkan — untuk lencana pada dasbor. */
    public static function jumlahTertunggak(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM critical_notifications WHERE status IN ('menunggu','terlambat')"
        );
    }

    /**
     * Membatalkan catatan, mis. bila hasil dikoreksi dan ternyata tidak
     * kritis, atau order dibatalkan.
     */
    public static function batalkan(int $resultId, string $alasan): void
    {
        Database::execute(
            "UPDATE critical_notifications
                SET status = 'dibatalkan', catatan = ?
              WHERE result_id = ? AND status IN ('menunggu','terlambat')",
            [mb_substr($alasan, 0, 255), $resultId]
        );
    }

    /**
     * Membandingkan pembacaan ulang dengan nilai hasil.
     *
     * Perbandingan sengaja longgar pada bentuk penulisan namun ketat pada
     * angkanya: "7,2" dan "7.2" sama, tetapi "72" tidak sama dengan "7.2".
     * Justru perbedaan seperti itulah yang hendak ditangkap read-back.
     */
    public static function samaSecaraAngka(string $a, string $b): bool
    {
        $bersih = static function (string $s): string {
            $s = trim(mb_strtolower($s));
            $s = str_replace([',', ' '], ['.', ''], $s);
            return $s;
        };

        $x = $bersih($a);
        $y = $bersih($b);

        if ($x === $y && $x !== '') {
            return true;
        }

        if (is_numeric($x) && is_numeric($y)) {
            // Toleransi hanya untuk pembulatan penulisan, bukan untuk
            // perbedaan besaran.
            return abs((float) $x - (float) $y) < 1e-9;
        }

        return false;
    }
}
