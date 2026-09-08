<?php
declare(strict_types=1);

/**
 * Sinkronisasi terjadwal dengan SIMRS Khanza.
 *
 *   php bin/sync_khanza.php            proses antrian kirim + tarik order
 *   php bin/sync_khanza.php --antrian  hanya proses antrian pengiriman hasil
 *   php bin/sync_khanza.php --tarik    hanya tarik order baru
 *   php bin/sync_khanza.php --bersih   bersihkan log lama
 *
 * Pasang pada penjadwal, misalnya setiap 2 menit:
 *
 *   Windows :  C:\xampp\php\php.exe C:\xampp\htdocs\LIS\bin\sync_khanza.php
 *   Linux   :  *\/2 * * * * /usr/bin/php /var/www/LIS/bin/sync_khanza.php
 *   macOS   :  *\/2 * * * * /Applications/XAMPP/xamppfiles/bin/php \
 *                /Applications/XAMPP/xamppfiles/htdocs/LIS/bin/sync_khanza.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari baris perintah.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\KhanzaService;

$berkasKonfigurasi = BASE_PATH . '/config/config.php';
if (!is_file($berkasKonfigurasi)) {
    exit("config/config.php belum ada. Jalankan php bin/install.php terlebih dahulu.\n");
}

/** @var array<string,mixed> $konfigurasi */
$konfigurasi = require $berkasKonfigurasi;
Config::load($konfigurasi);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

$argumen  = array_slice($argv, 1);
$hanyaAntrian = in_array('--antrian', $argumen, true);
$hanyaTarik   = in_array('--tarik', $argumen, true);
$bersihkan    = in_array('--bersih', $argumen, true);
$semua        = !$hanyaAntrian && !$hanyaTarik && !$bersihkan;

function tulis(string $t): void
{
    echo '[' . date('H:i:s') . '] ' . $t . PHP_EOL;
}

// Cegah dua proses berjalan bersamaan — cron tiap 2 menit bisa bertumpuk
// bila konektor sedang lambat merespons.
$berkasKunci = BASE_PATH . '/storage/tmp/sync_khanza.lock';
$kunci = @fopen($berkasKunci, 'c');

if ($kunci === false) {
    tulis('Tidak dapat membuat berkas kunci, dilanjutkan tanpa penguncian.');
} elseif (!flock($kunci, LOCK_EX | LOCK_NB)) {
    tulis('Proses sinkronisasi lain masih berjalan. Keluar.');
    exit(0);
}

$mulai = microtime(true);

// ---------------------------------------------------------------------

if ($bersihkan) {
    tulis('Membersihkan data lama…');

    $retensiRaw = Config::settingInt('middleware.simpan_raw_hari', 90);

    $pesan = Database::execute(
        'DELETE FROM instrument_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
        [max(7, $retensiRaw)]
    );
    tulis("  $pesan pesan alat lama dihapus (retensi $retensiRaw hari)");

    $sync = Database::execute(
        "DELETE FROM khanza_sync_log
         WHERE status = 'sukses' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    tulis("  $sync log sinkronisasi lama dihapus");

    $login = Database::execute(
        'DELETE FROM login_attempts WHERE terakhir_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    tulis("  $login catatan percobaan login lama dihapus");

    // Jejak audit sengaja TIDAK dihapus: catatan ini adalah bukti telusur
    // yang diminta saat penilaian akreditasi laboratorium.
    tulis('  Jejak audit dipertahankan (bukti telusur akreditasi).');

    tulis(sprintf('Selesai dalam %d ms.', round((microtime(true) - $mulai) * 1000)));
    exit(0);
}

if (!KhanzaService::aktif()) {
    tulis('Integrasi Khanza nonaktif pada Pengaturan Sistem. Tidak ada yang dikerjakan.');
    exit(0);
}

// ---------------------------------------------------------------------
// Antrian pengiriman hasil
// ---------------------------------------------------------------------

if ($semua || $hanyaAntrian) {
    $antri = (int) Database::scalar(
        "SELECT COUNT(*) FROM khanza_sync_log WHERE status = 'antri' AND arah = 'keluar'"
    );

    if ($antri === 0) {
        tulis('Antrian pengiriman hasil kosong.');
    } else {
        tulis("Memproses antrian pengiriman: $antri entri menunggu…");

        try {
            $hasil = KhanzaService::prosesAntrian(100);
            tulis(sprintf(
                '  %d diproses, %d sukses, %d masih gagal',
                $hasil['diproses'],
                $hasil['sukses'],
                $hasil['gagal']
            ));
        } catch (Throwable $e) {
            tulis('  GAGAL: ' . $e->getMessage());
            Logger::exception($e);
        }
    }

    // Order yang sudah terverifikasi tetapi belum pernah masuk antrian —
    // misalnya karena LIS sempat mati tepat setelah verifikasi.
    $tertinggal = Database::select(
        "SELECT o.id, o.no_order FROM orders o
         WHERE o.khanza_noorder IS NOT NULL
           AND o.status IN ('verified','released')
           AND o.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND NOT EXISTS (
             SELECT 1 FROM khanza_sync_log k
             WHERE k.ref_type = 'order' AND k.ref_id = CAST(o.id AS CHAR)
               AND k.jenis = 'hasil')
         LIMIT 25"
    );

    if ($tertinggal !== []) {
        tulis('Menemukan ' . count($tertinggal) . ' hasil terverifikasi yang belum pernah dikirim:');

        foreach ($tertinggal as $o) {
            try {
                $r = KhanzaService::kirimHasil((int) $o['id']);
                tulis(sprintf('  %s %s', $r['sukses'] ? '✓' : '✗', (string) $o['no_order']));
            } catch (Throwable $e) {
                tulis('  ✗ ' . (string) $o['no_order'] . ' — ' . $e->getMessage());
                Logger::exception($e);
            }
        }
    }
}

// ---------------------------------------------------------------------
// Tarik order baru (mode pull)
// ---------------------------------------------------------------------

if ($semua || $hanyaTarik) {
    $mode = Config::setting('khanza.mode_order', 'push');

    if ($mode !== 'pull' && !$hanyaTarik) {
        tulis('Mode order = push; penarikan dilewati (Khanza yang mengirim ke LIS).');
    } else {
        tulis('Menarik order baru dari Khanza…');

        try {
            $hasil = KhanzaService::tarikOrder(50);
            tulis('  ' . $hasil['pesan']);
        } catch (Throwable $e) {
            tulis('  GAGAL: ' . $e->getMessage());
            Logger::exception($e);
        }
    }
}

tulis(sprintf('Selesai dalam %d ms.', round((microtime(true) - $mulai) * 1000)));

if ($kunci !== false) {
    flock($kunci, LOCK_UN);
    fclose($kunci);
}

exit(0);
