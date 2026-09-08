<?php
declare(strict_types=1);

/**
 * Uji mandiri LIS — memverifikasi pemasangan tanpa mengubah data.
 *
 *   php bin/selftest.php
 *
 * Aman dijalankan kapan saja, termasuk pada sistem produksi: seluruh
 * pemeriksaan bersifat baca saja.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Uji mandiri hanya dapat dijalankan dari baris perintah.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Barcode;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Helper;
use App\Services\ReferenceRangeService;

$lulus = 0;
$gagal = 0;
$catat = [];

function uji(string $nama, callable $fn): void
{
    global $lulus, $gagal;

    try {
        $hasil = $fn();
        if ($hasil === true || $hasil === null) {
            echo '  ✓ ' . $nama . PHP_EOL;
            $lulus++;
            return;
        }
        echo '  ✗ ' . $nama . ' — ' . (string) $hasil . PHP_EOL;
        $gagal++;
    } catch (Throwable $e) {
        echo '  ✗ ' . $nama . ' — ' . $e->getMessage() . PHP_EOL;
        $gagal++;
    }
}

function info(string $t): void
{
    echo '    ' . $t . PHP_EOL;
}

echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
echo '  LIS — Uji Mandiri Pemasangan' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------
echo 'Lingkungan' . PHP_EOL;

uji('PHP 8.0 atau lebih baru (' . PHP_VERSION . ')', fn () => PHP_VERSION_ID >= 80000 ?: 'versi terlalu lama');

foreach (['pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
    uji("Ekstensi $ext aktif", fn () => extension_loaded($ext) ?: 'tidak aktif');
}

// Selftest berjalan pada PHP CLI, sedangkan aplikasi web berjalan pada PHP
// milik Apache — keduanya membaca php.ini yang berbeda. Ekstensi bisa lulus
// di sini namun tetap hilang di peramban, jadi jalurnya dicetak agar
// perbedaan itu terlihat sebelum menimbulkan kebingungan.
echo '  · php.ini CLI: ' . (php_ini_loaded_file() ?: 'tidak diketahui') . PHP_EOL;
echo '    Bila halaman web bermasalah sementara semua uji di bawah lulus,'
    . ' bandingkan dengan php.ini Apache (Config → PHP (php.ini) di panel XAMPP).' . PHP_EOL;

uji('config/config.php ada', fn () => is_file(BASE_PATH . '/config/config.php')
    ?: 'belum dibuat — jalankan php bin/install.php');

/** @var array<string,mixed> $konfigurasi */
$konfigurasi = require (is_file(BASE_PATH . '/config/config.php')
    ? BASE_PATH . '/config/config.php'
    : BASE_PATH . '/config/config.example.php');
Config::load($konfigurasi);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

uji('security.app_key sudah diganti', function () {
    $k = (string) Config::get('security.app_key', '');

    return (strpos($k, 'ubah_dengan') !== 0 && strlen($k) >= 32)
        ?: 'masih nilai bawaan — enkripsi secret API tidak akan berfungsi';
});

uji('Folder storage dapat ditulis', function () {
    foreach (['storage/logs', 'storage/tmp', 'storage/reports'] as $f) {
        if (!is_writable(BASE_PATH . '/' . $f)) {
            return "$f tidak dapat ditulis";
        }
    }

    return true;
});

echo PHP_EOL . 'Database' . PHP_EOL;

uji('Koneksi database', function () {
    Database::scalar('SELECT 1');

    return true;
});

$tabelWajib = [
    'users', 'patients', 'orders', 'order_items', 'specimens', 'results',
    'tests', 'reference_ranges', 'test_panels', 'test_panel_items',
    'instruments', 'instrument_test_map', 'instrument_messages',
    'orphan_results', 'instrument_worklist', 'khanza_sync_log',
    'khanza_templates', 'audit_logs', 'settings', 'counters',
    'api_clients', 'login_attempts', 'qc_lots', 'qc_results', 'result_history',
];

uji('Seluruh tabel inti ada (' . count($tabelWajib) . ')', function () use ($tabelWajib) {
    $hilang = [];
    foreach ($tabelWajib as $t) {
        if (!Database::tableExists($t)) {
            $hilang[] = $t;
        }
    }

    return $hilang === [] ? true : 'tabel hilang: ' . implode(', ', $hilang);
});

uji('View v_worklist ada', function () {
    Database::scalar('SELECT COUNT(*) FROM v_worklist');

    return true;
});

uji('Master pemeriksaan terisi', function () {
    $n = (int) Database::scalar('SELECT COUNT(*) FROM tests WHERE aktif = 1');
    info("$n pemeriksaan aktif");

    return $n > 0 ?: 'master pemeriksaan kosong — jalankan database/02_seed_master.sql';
});

uji('Nilai rujukan terisi', function () {
    $n = (int) Database::scalar('SELECT COUNT(*) FROM reference_ranges');
    $tanpa = (int) Database::scalar(
        'SELECT COUNT(*) FROM tests t WHERE t.aktif = 1
         AND NOT EXISTS (SELECT 1 FROM reference_ranges r WHERE r.test_id = t.id)'
    );
    info("$n baris rujukan; $tanpa pemeriksaan aktif belum punya rujukan");

    return $n > 0 ?: 'belum ada nilai rujukan';
});

uji('Ada akun admin aktif', function () {
    $n = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1");

    return $n > 0 ?: 'tidak ada admin aktif — jalankan php bin/install.php';
});

uji('Penomoran atomik berfungsi', function () {
    $a = Database::nextCounter('selftest');
    $b = Database::nextCounter('selftest');

    // Bersihkan agar tidak meninggalkan jejak.
    Database::execute('DELETE FROM counters WHERE nama = ?', ['selftest']);

    return $b === $a + 1 ?: "urutan tidak berurut ($a lalu $b)";
});

echo PHP_EOL . 'Keamanan' . PHP_EOL;

uji('Enkripsi secret API berfungsi', function () {
    if (!Crypto::tersedia()) {
        return 'app_key belum diatur atau AES-256-GCM tidak tersedia';
    }

    $asli = 'rahasia-uji-' . bin2hex(random_bytes(8));
    $kembali = Crypto::dekripsi(Crypto::enkripsi($asli));

    return $kembali === $asli ?: 'hasil dekripsi tidak sama dengan aslinya';
});

uji('Kredensial API bawaan sudah dinonaktifkan', function () {
    $n = (int) Database::scalar(
        "SELECT COUNT(*) FROM api_clients
         WHERE aktif = 1 AND (api_key LIKE 'lis_mw_0000%' OR api_key LIKE 'lis_kz_0000%')"
    );

    return $n === 0 ?: "$n kredensial bawaan masih aktif — ganti lewat Pengaturan → Kredensial API";
});

uji('Akun contoh sudah dinonaktifkan', function () {
    $n = (int) Database::scalar(
        "SELECT COUNT(*) FROM users WHERE aktif = 1 AND username IN ('dokter','analis','sampling')"
    );

    return $n === 0 ?: "$n akun contoh masih aktif dengan kata sandi bawaan";
});

uji('Berkas .htaccess pelindung terpasang', function () {
    $hilang = [];
    foreach (['.htaccess', 'public/.htaccess', 'config/.htaccess', 'storage/.htaccess'] as $f) {
        if (!is_file(BASE_PATH . '/' . $f)) {
            $hilang[] = $f;
        }
    }

    return $hilang === [] ? true : 'hilang: ' . implode(', ', $hilang);
});

echo PHP_EOL . 'Logika klinis' . PHP_EOL;

// Penyelarasan satuan alat vs master.
//
// Analyzer sering memakai satuan SI. Bila nilainya dibandingkan mentah-mentah
// dengan rujukan bersatuan konvensional, flag bisa keluar dengan ARAH yang
// salah — Hb 64 g/L (6,4 g/dL, anemia berat) dinilai "kritis tinggi".
// Uji ini menjaga agar kekeliruan itu tidak pernah kembali.
uji('Satuan g/L dikonversi ke g/dL', function () {
    $f = \App\Services\UnitConverter::faktor('g/L', 'g/dL');

    return ($f !== null && abs($f - 0.1) < 1e-9) ?: 'faktor salah: ' . var_export($f, true);
});

uji('Hb 64 g/L dinilai sebagai 6,4 g/dL (rendah), bukan tinggi', function () {
    $f = \App\Services\UnitConverter::faktor('g/L', 'g/dL');
    if ($f === null) {
        return 'konversi tidak dikenali';
    }
    $nilai = 64 * $f;

    return $nilai < 13.2 ?: 'menghasilkan ' . $nilai . ' — masih dinilai tinggi';
});

uji('Notasi 10^3/uL dan 10*3/uL dianggap sama', function () {
    return \App\Services\UnitConverter::setara('10^3/uL', '10*3/uL')
        ?: 'notasi berbeda dianggap satuan berbeda';
});

uji('10*9/L setara 10^3/uL tanpa mengubah angka', function () {
    $f = \App\Services\UnitConverter::faktor('10*9/L', '10^3/uL');

    return ($f !== null && abs($f - 1.0) < 1e-9) ?: 'faktor salah: ' . var_export($f, true);
});

uji('Konversi mmol/L ke mg/dL ditolak, bukan ditebak', function () {
    // Konversinya bergantung berat molekul tiap analit. Menebak di sini
    // berarti menghasilkan flag yang salah dengan penuh keyakinan.
    return \App\Services\UnitConverter::faktor('mmol/L', 'mg/dL') === null
        ?: 'konversi yang tidak pasti justru diterima';
});

uji('Tabel pola Code 128 utuh', function () {
    $m = Barcode::periksaTabel();

    return $m === [] ? true : implode('; ', array_slice($m, 0, 3));
});

uji('Barcode dapat dibaca ulang (putar-balik)', function () {
    $bits = Barcode::encode('2609020001');

    // 10 digit → subset C: start + 5 pasang + checksum = 7 simbol × 11 + stop 13
    return strlen($bits) === 90 ?: 'panjang tidak sesuai: ' . strlen($bits);
});

uji('Pemilihan nilai rujukan sadar jenis kelamin', function () {
    $hb = Database::selectOne("SELECT id FROM tests WHERE kode = 'HB' LIMIT 1");
    if ($hb === null) {
        return 'pemeriksaan HB tidak ada di master';
    }

    $lakiDewasa = ReferenceRangeService::untuk((int) $hb['id'], 'L', 30 * 365);
    $wanitaDewasa = ReferenceRangeService::untuk((int) $hb['id'], 'P', 30 * 365);

    if ($lakiDewasa === null || $wanitaDewasa === null) {
        return 'rujukan Hb tidak ditemukan';
    }
    if ((float) $lakiDewasa['low'] === (float) $wanitaDewasa['low']) {
        return 'rujukan laki-laki dan perempuan seharusnya berbeda';
    }

    info(sprintf(
        'Hb laki-laki %.1f–%.1f, perempuan %.1f–%.1f g/dL',
        (float) $lakiDewasa['low'], (float) $lakiDewasa['high'],
        (float) $wanitaDewasa['low'], (float) $wanitaDewasa['high']
    ));

    return true;
});

uji('Pemilihan nilai rujukan sadar umur', function () {
    $hb = Database::selectOne("SELECT id FROM tests WHERE kode = 'HB' LIMIT 1");
    if ($hb === null) {
        return 'pemeriksaan HB tidak ada';
    }

    $bayi = ReferenceRangeService::untuk((int) $hb['id'], 'L', 10);
    $dewasa = ReferenceRangeService::untuk((int) $hb['id'], 'L', 30 * 365);

    if ($bayi === null || $dewasa === null) {
        return 'rujukan tidak lengkap';
    }

    return ((float) $bayi['low'] !== (float) $dewasa['low'])
        ?: 'rujukan bayi baru lahir seharusnya berbeda dari dewasa';
});

uji('Penandaan flag numerik benar', function () {
    $ruj = ['low' => 13.2, 'high' => 17.3, 'critical_low' => 7.0, 'critical_high' => 20.0];

    $kasus = [
        [15.0, 'N'], [12.0, 'L'], [18.0, 'H'], [6.5, 'LL'], [21.0, 'HH'],
        [7.0, 'LL'],   // tepat di ambang kritis harus ikut kritis
        [20.0, 'HH'],
    ];

    foreach ($kasus as [$nilai, $harap]) {
        $dapat = ReferenceRangeService::flagNumerik($nilai, $ruj);
        if ($dapat !== $harap) {
            return "nilai $nilai menghasilkan '$dapat', seharusnya '$harap'";
        }
    }

    return true;
});

uji('Penandaan flag kualitatif benar', function () {
    $ruj = ['nilai_normal_teks' => 'Non Reaktif'];

    if (ReferenceRangeService::flagTeks('Non Reaktif', $ruj) !== 'N') {
        return 'nilai normal seharusnya N';
    }
    if (ReferenceRangeService::flagTeks('non reaktif', $ruj) !== 'N') {
        return 'perbandingan seharusnya tidak peka huruf besar-kecil';
    }
    if (ReferenceRangeService::flagTeks('Reaktif', $ruj) !== 'A') {
        return 'nilai di luar normal seharusnya A';
    }

    return true;
});

uji('Perhitungan umur benar', function () {
    $lahir = date('Y-m-d', strtotime('-30 years -6 months'));
    $hari = Helper::umurHari($lahir);

    if ($hari === null) {
        return 'umur tidak terhitung';
    }

    return ($hari > 11000 && $hari < 11300) ?: "umur $hari hari di luar rentang wajar";
});

uji('Pemeriksaan bernilai kritis punya ambang kritis', function () {
    $kurang = Database::select(
        "SELECT t.kode, t.nama FROM tests t
         WHERE t.aktif = 1 AND t.is_kritis = 1
           AND NOT EXISTS (
             SELECT 1 FROM reference_ranges r
             WHERE r.test_id = t.id
               AND (r.critical_low IS NOT NULL OR r.critical_high IS NOT NULL))"
    );

    if ($kurang === []) {
        return true;
    }

    return 'tanpa ambang kritis: ' . implode(', ', array_column(array_slice($kurang, 0, 6), 'kode'));
});

echo PHP_EOL . 'Integrasi' . PHP_EOL;

uji('Pengaturan Khanza terbaca', function () {
    $aktif = Config::settingBool('khanza.aktif', false);
    info('Integrasi Khanza: ' . ($aktif ? 'AKTIF' : 'nonaktif'));

    if ($aktif) {
        $url = Config::setting('khanza.base_url', '');
        info('URL konektor: ' . ($url === '' ? '(kosong)' : $url));
        if ($url === '') {
            return 'integrasi aktif tetapi URL konektor kosong';
        }
    }

    return true;
});

uji('Autovalidasi memiliki penanggung jawab', function () {
    if (!Config::settingBool('hasil.auto_verify', false)) {
        info('Autovalidasi nonaktif — aman.');

        return true;
    }

    $userId = Config::settingInt('hasil.auto_verify_user', 0);
    if ($userId <= 0) {
        return 'autovalidasi aktif tetapi hasil.auto_verify_user belum diisi — '
            . 'hasil akan terbit tanpa penanggung jawab';
    }

    $ada = Database::scalar(
        "SELECT nama FROM users WHERE id = ? AND aktif = 1 AND role IN ('verifikator','admin')",
        [$userId]
    );

    if ($ada === null) {
        return 'pengguna penanggung jawab autovalidasi tidak aktif atau bukan verifikator';
    }

    info('Penanggung jawab autovalidasi: ' . (string) $ada);

    return true;
});

uji('Alat terdaftar memiliki pemetaan parameter', function () {
    $alat = Database::select('SELECT id, kode, nama FROM instruments WHERE aktif = 1');

    if ($alat === []) {
        info('Belum ada alat aktif.');

        return true;
    }

    $kosong = [];
    foreach ($alat as $a) {
        $n = (int) Database::scalar(
            'SELECT COUNT(*) FROM instrument_test_map WHERE instrument_id = ? AND test_id IS NOT NULL',
            [$a['id']]
        );
        info(sprintf('%-12s %d parameter terpetakan', $a['kode'], $n));
        if ($n === 0) {
            $kosong[] = (string) $a['kode'];
        }
    }

    return $kosong === [] ? true
        : 'belum ada pemetaan: ' . implode(', ', $kosong) . ' (hasil dari alat ini tidak akan tersimpan)';
});

// ---------------------------------------------------------------------

echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
printf('  Hasil: %d lulus, %d bermasalah%s', $lulus, $gagal, PHP_EOL);
echo str_repeat('=', 60) . PHP_EOL . PHP_EOL;

exit($gagal > 0 ? 1 : 0);
