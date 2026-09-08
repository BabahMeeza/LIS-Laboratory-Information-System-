<?php
declare(strict_types=1);

/**
 * Pemasang LIS — dijalankan sekali saat instalasi.
 *
 *   php bin/install.php
 *
 * Melakukan:
 *   1. Memeriksa versi PHP dan ekstensi yang dibutuhkan
 *   2. Membuat config/config.php dari contoh, lengkap dengan app_key acak
 *   3. Menjalankan skema dan data master ke database
 *   4. Membuat akun administrator
 *   5. Menerbitkan kredensial API untuk middleware alat
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Pemasang hanya dapat dijalankan dari baris perintah.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;

$H = str_repeat('=', 66);

function tulis(string $t = ''): void
{
    echo $t . PHP_EOL;
}

function tanya(string $pertanyaan, string $bawaan = ''): string
{
    $petunjuk = $bawaan === '' ? '' : " [$bawaan]";
    echo $pertanyaan . $petunjuk . ': ';
    $jawab = trim((string) fgets(STDIN));

    return $jawab === '' ? $bawaan : $jawab;
}

function tanyaRahasia(string $pertanyaan): string
{
    echo $pertanyaan . ': ';

    // Matikan gema pada sistem mirip-Unix; Windows tidak mendukung ini.
    if (DIRECTORY_SEPARATOR !== '\\') {
        @shell_exec('stty -echo 2>/dev/null');
    }
    $jawab = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR !== '\\') {
        @shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    }

    return $jawab;
}

tulis();
tulis($H);
tulis('  LIS — Laboratory Information System');
tulis('  Pemasangan');
tulis($H);
tulis();

// ---------------------------------------------------------------------
// 1. Pemeriksaan lingkungan
// ---------------------------------------------------------------------

tulis('[1/5] Memeriksa lingkungan');

$masalah = [];

tulis('  PHP ' . PHP_VERSION . (PHP_VERSION_ID >= 80000 ? '  ✓' : '  ✗ (butuh 8.0+)'));
if (PHP_VERSION_ID < 80000) {
    $masalah[] = 'PHP 8.0 atau lebih baru dibutuhkan.';
}

foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'curl' => false] as $ext => $wajib) {
    $ada = extension_loaded($ext);
    tulis('  Ekstensi ' . str_pad($ext, 12) . ($ada ? '✓' : ($wajib ? '✗ WAJIB' : '– opsional')));
    if ($wajib && !$ada) {
        $masalah[] = "Ekstensi PHP '$ext' belum aktif. Aktifkan di php.ini.";
    }
}

foreach (['storage/logs', 'storage/tmp', 'storage/reports'] as $folder) {
    $path = BASE_PATH . '/' . $folder;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    $bisa = is_writable($path);
    tulis('  Folder ' . str_pad($folder, 18) . ($bisa ? '✓ dapat ditulis' : '✗ TIDAK dapat ditulis'));
    if (!$bisa) {
        $masalah[] = "Folder $folder harus dapat ditulis oleh web server.";
    }
}

if ($masalah !== []) {
    tulis();
    tulis('Pemasangan dihentikan:');
    foreach ($masalah as $m) {
        tulis('  • ' . $m);
    }
    exit(1);
}

tulis();

// ---------------------------------------------------------------------
// 2. Konfigurasi
// ---------------------------------------------------------------------

tulis('[2/5] Konfigurasi database');

$berkasKonfigurasi = BASE_PATH . '/config/config.php';

if (is_file($berkasKonfigurasi)) {
    tulis('  config/config.php sudah ada.');
    $ganti = strtolower(tanya('  Tulis ulang konfigurasi? (y/T)', 'T'));
    $buatKonfigurasi = ($ganti === 'y' || $ganti === 'ya');
} else {
    $buatKonfigurasi = true;
}

if ($buatKonfigurasi) {
    $dbHost = tanya('  Host MySQL', '127.0.0.1');
    $dbPort = tanya('  Port MySQL', '3306');
    $dbName = tanya('  Nama database', 'db_lis');
    $dbUser = tanya('  Pengguna MySQL', 'root');
    $dbPass = tanyaRahasia('  Kata sandi MySQL (kosongkan bila tidak ada)');
    $zona   = tanya('  Zona waktu', 'Asia/Jakarta');
    $baseUrl = tanya('  Base URL aplikasi (kosongkan untuk deteksi otomatis)', '');

    $appKey = bin2hex(random_bytes(32));

    $contoh = (string) file_get_contents(BASE_PATH . '/config/config.example.php');

    $isi = strtr($contoh, [
        "'host'    => '127.0.0.1',\n        'port'    => 3306,\n        'name'    => 'db_lis',\n        'user'    => 'root',\n        'pass'    => '',"
            => "'host'    => " . var_export($dbHost, true) . ",\n        'port'    => " . (int) $dbPort . ",\n        'name'    => " . var_export($dbName, true) . ",\n        'user'    => " . var_export($dbUser, true) . ",\n        'pass'    => " . var_export($dbPass, true) . ",",
        "'timezone'  => 'Asia/Pontianak'," => "'timezone'  => " . var_export($zona, true) . ",",
        "'base_url'  => ''," => "'base_url'  => " . var_export($baseUrl, true) . ",",
        "'app_key'          => 'ubah_dengan_string_acak_64_karakter'," => "'app_key'          => " . var_export($appKey, true) . ",",
    ]);

    if (file_put_contents($berkasKonfigurasi, $isi) === false) {
        tulis('  ✗ Gagal menulis config/config.php');
        exit(1);
    }

    @chmod($berkasKonfigurasi, 0640);
    tulis('  ✓ config/config.php dibuat, app_key acak dihasilkan.');
}

/** @var array<string,mixed> $konfigurasi */
$konfigurasi = require $berkasKonfigurasi;
Config::load($konfigurasi);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

tulis();

// ---------------------------------------------------------------------
// 3. Skema database
// ---------------------------------------------------------------------

tulis('[3/5] Menyiapkan database');

$db = (array) Config::get('db');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    tulis('  ✗ Tidak dapat terhubung ke MySQL: ' . $e->getMessage());
    tulis('    Pastikan MySQL berjalan dan kredensial pada config/config.php benar.');
    exit(1);
}

tulis('  ✓ Terhubung ke MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

/**
 * Jalankan berkas SQL. Pemisahan pernyataan dilakukan sederhana pada
 * titik koma di akhir baris — cukup untuk berkas skema kita yang tidak
 * memuat trigger atau stored procedure.
 */
function jalankanSql(PDO $pdo, string $berkas): array
{
    $isi = (string) file_get_contents($berkas);
    $isi = preg_replace('/^\s*--.*$/m', '', $isi) ?? $isi;

    $pernyataan = array_filter(
        array_map('trim', explode(";\n", $isi . "\n")),
        static fn ($p) => $p !== '' && $p !== ';'
    );

    $ok = 0;
    $galat = [];

    foreach ($pernyataan as $p) {
        $p = rtrim($p, "; \n\r\t");
        if ($p === '') {
            continue;
        }
        try {
            $pdo->exec($p);
            $ok++;
        } catch (PDOException $e) {
            $galat[] = substr(preg_replace('/\s+/', ' ', $p) ?? '', 0, 70) . ' → ' . $e->getMessage();
        }
    }

    return [$ok, $galat];
}

[$ok, $galat] = jalankanSql($pdo, BASE_PATH . '/database/01_schema.sql');
tulis("  ✓ Skema: $ok pernyataan dijalankan" . ($galat === [] ? '' : ', ' . count($galat) . ' bermasalah'));
foreach (array_slice($galat, 0, 5) as $g) {
    tulis('    ! ' . $g);
}

[$ok2, $galat2] = jalankanSql($pdo, BASE_PATH . '/database/02_seed_master.sql');
tulis("  ✓ Data master: $ok2 pernyataan dijalankan" . ($galat2 === [] ? '' : ', ' . count($galat2) . ' bermasalah'));
foreach (array_slice($galat2, 0, 5) as $g) {
    tulis('    ! ' . $g);
}

$jumlahTes = (int) Database::scalar('SELECT COUNT(*) FROM tests');
$jumlahRuj = (int) Database::scalar('SELECT COUNT(*) FROM reference_ranges');
tulis("  ✓ Master pemeriksaan: $jumlahTes pemeriksaan, $jumlahRuj nilai rujukan");

tulis();

// ---------------------------------------------------------------------
// 4. Akun administrator
// ---------------------------------------------------------------------

tulis('[4/5] Akun administrator');

$adminAda = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'");
tulis("  Akun admin saat ini: $adminAda");

$buatAdmin = strtolower(tanya('  Buat akun administrator baru? (Y/t)', 'Y'));

if ($buatAdmin === 'y' || $buatAdmin === 'ya') {
    $username = tanya('  Nama pengguna', 'admin');

    $sudahAda = Database::selectOne('SELECT id FROM users WHERE username = ?', [$username]);

    $nama = tanya('  Nama lengkap', 'Administrator');
    $nip  = tanya('  NIP (opsional)', '');

    for (;;) {
        $sandi = tanyaRahasia('  Kata sandi (minimal 8 karakter)');
        if (mb_strlen($sandi) < 8) {
            tulis('  ✗ Terlalu pendek, ulangi.');
            continue;
        }
        $ulang = tanyaRahasia('  Ulangi kata sandi');
        if ($sandi !== $ulang) {
            tulis('  ✗ Tidak cocok, ulangi.');
            continue;
        }
        break;
    }

    $data = [
        'username'      => $username,
        'password_hash' => password_hash($sandi, PASSWORD_BCRYPT),
        'nama'          => $nama,
        'nip'           => $nip === '' ? null : $nip,
        'role'          => 'admin',
        'aktif'         => 1,
    ];

    if ($sudahAda !== null) {
        Database::update('users', $data, 'id = ?', [$sudahAda['id']]);
        tulis("  ✓ Akun '$username' diperbarui.");
    } else {
        Database::insert('users', $data);
        tulis("  ✓ Akun '$username' dibuat.");
    }
}

// Akun contoh dari data master wajib dinonaktifkan pada instalasi nyata.
$contohAktif = (int) Database::scalar(
    "SELECT COUNT(*) FROM users WHERE username IN ('dokter','analis','sampling') AND aktif = 1"
);

if ($contohAktif > 0) {
    tulis();
    tulis("  PERINGATAN: $contohAktif akun contoh (dokter/analis/sampling) masih aktif");
    tulis('  dengan kata sandi bawaan "Lis#2026".');
    $matikan = strtolower(tanya('  Nonaktifkan sekarang? (Y/t)', 'Y'));

    if ($matikan === 'y' || $matikan === 'ya') {
        Database::execute(
            "UPDATE users SET aktif = 0 WHERE username IN ('dokter','analis','sampling')"
        );
        tulis('  ✓ Akun contoh dinonaktifkan. Buat akun asli lewat menu Pengguna.');
    } else {
        tulis('  ! Ingat untuk mengganti kata sandinya sebelum dipakai melayani pasien.');
    }
}

tulis();

// ---------------------------------------------------------------------
// 5. Kredensial API
// ---------------------------------------------------------------------

tulis('[5/5] Kredensial API untuk middleware alat');

$buatKredensial = strtolower(tanya('  Terbitkan kredensial baru untuk middleware? (Y/t)', 'Y'));

if ($buatKredensial === 'y' || $buatKredensial === 'ya') {
    $apiKey = 'lis_' . bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(24));

    $secretEnc = null;
    try {
        $secretEnc = Crypto::enkripsi($secret);
    } catch (Throwable $e) {
        tulis('  ! Secret tidak dapat dienkripsi: ' . $e->getMessage());
    }

    Database::insert('api_clients', [
        'nama'        => 'Middleware Alat Laboratorium',
        'api_key'     => $apiKey,
        'secret_hash' => password_hash($secret, PASSWORD_BCRYPT),
        'secret_enc'  => $secretEnc,
        'scopes'      => 'instrument',
        'aktif'       => 1,
    ]);

    // Nonaktifkan kredensial bawaan yang tidak aman.
    Database::execute(
        "UPDATE api_clients SET aktif = 0 WHERE api_key LIKE 'lis_mw_0000%' OR api_key LIKE 'lis_kz_0000%'"
    );

    tulis('  ✓ Kredensial diterbitkan.');

    // Tuliskan langsung ke middleware/.env.
    //
    // Sebelumnya kunci hanya dicetak ke layar sementara .env.example tetap
    // memuat kunci bawaan yang baru saja dinonaktifkan di atas. Siapa pun
    // yang menyalin .env.example — persis seperti yang disarankan INSTALL.md
    // — akan mendapat kunci mati, dan middleware berhenti dengan
    // "API key tidak dikenali". Menulis berkasnya di sini menghapus langkah
    // salin-tempel yang menjadi sumber kesalahan itu.
    $berkasEnv = BASE_PATH . '/middleware/.env';
    $contohEnv = BASE_PATH . '/middleware/.env.example';

    $tulisEnv = true;
    if (is_file($berkasEnv)) {
        tulis();
        tulis('  middleware/.env sudah ada.');
        $jawab   = strtolower(tanya('  Perbarui kunci di dalamnya? (Y/t)', 'Y'));
        $tulisEnv = ($jawab === 'y' || $jawab === 'ya');
    }

    $envTertulis = false;

    if ($tulisEnv) {
        $isi = is_file($berkasEnv)
            ? (string) file_get_contents($berkasEnv)
            : (is_file($contohEnv) ? (string) file_get_contents($contohEnv) : '');

        if ($isi === '') {
            $isi = "LIS_BASE_URL=http://localhost/LIS/public\nLIS_API_KEY=\nLIS_API_SECRET=\n";
        }

        // Ganti bila barisnya ada, tambahkan bila belum.
        foreach (['LIS_API_KEY' => $apiKey, 'LIS_API_SECRET' => $secret] as $kunci => $nilai) {
            $pola = '/^' . preg_quote($kunci, '/') . '=.*$/m';
            if (preg_match($pola, $isi) === 1) {
                $isi = (string) preg_replace($pola, $kunci . '=' . $nilai, $isi);
            } else {
                $isi = rtrim($isi, "\n") . "\n" . $kunci . '=' . $nilai . "\n";
            }
        }

        if (is_dir(dirname($berkasEnv)) && @file_put_contents($berkasEnv, $isi) !== false) {
            @chmod($berkasEnv, 0600);
            $envTertulis = true;
            tulis('  ✓ Ditulis ke middleware/.env (izin 0600).');
        } else {
            tulis('  ! Gagal menulis middleware/.env — salin manual dari bawah.');
        }
    }

    if (!$envTertulis) {
        tulis();
        tulis('  Salin ke middleware/.env:');
        tulis();
        tulis('      LIS_API_KEY=' . $apiKey);
        tulis('      LIS_API_SECRET=' . $secret);
        tulis();
        tulis('  Secret ini tidak ditampilkan lagi. Simpan sekarang.');
    }

    tulis();
    tulis('  Bila kredensial ini hilang, terbitkan yang baru lewat');
    tulis('  Pengaturan → Kredensial API. Kunci lama tidak dapat dibaca kembali.');
}

tulis();
tulis($H);
tulis('  Pemasangan selesai.');
tulis($H);
tulis();
tulis('Langkah berikutnya:');
tulis('  1. Arahkan DocumentRoot atau Alias Apache ke folder public/');
tulis('     lalu buka LIS di peramban dan masuk dengan akun administrator.');
tulis('  2. Isi identitas fasilitas pada menu Pengaturan Sistem.');
tulis('  3. Daftarkan alat pada menu Alat Laboratorium — jangan lupa mencentang');
tulis('     "Alat aktif", karena middleware hanya membuka port untuk alat aktif.');
tulis('     Lalu jalankan middleware:');
tulis('        cd middleware && npm install && npm start');
tulis('     (middleware/.env sudah diisi kunci API pada langkah 5 di atas;');
tulis('      periksa LIS_BASE_URL di dalamnya bila LIS tidak di localhost)');
tulis('  4. Untuk integrasi Khanza, pasang folder khanza-connector/ di server');
tulis('     SIMRS lalu ikuti docs/03-integrasi-khanza.md');
tulis();
tulis('Verifikasi pemasangan kapan saja dengan:  php bin/selftest.php');
tulis();
