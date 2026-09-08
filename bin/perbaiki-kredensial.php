<?php
declare(strict_types=1);

/**
 * Perbaiki secret_enc kredensial API yang tidak lagi dapat didekripsi.
 *
 * MASALAH YANG DIATASI
 * --------------------
 * secret_enc menyimpan secret klien terenkripsi AES-256-GCM dengan kunci
 * yang diturunkan dari security.app_key. Bila app_key diganti setelah
 * kredensial dibuat, seluruh secret lama menjadi sampah: ia masih ada,
 * masih 104 byte base64, tetapi tidak dapat dibuka lagi. ApiAuth lalu
 * menolak setiap klien yang mengirim X-Signature dengan 401 — tanpa ada
 * yang salah di sisi klien.
 *
 * Yang tidak ikut rusak adalah secret_hash (bcrypt), karena bcrypt tidak
 * memakai app_key. Jadi bila secret aslinya masih dipegang — di
 * setting/database.xml milik Khanza, di middleware/.env — kebenarannya
 * dapat dibuktikan lebih dulu dengan bcrypt, baru dienkripsi ulang.
 * Skrip ini tidak pernah menulis secret yang tidak lolos pembuktian itu.
 *
 * HANYA CLI. Tidak disediakan lewat HTTP: ini menulis kredensial.
 *
 * PEMAKAIAN
 * ---------
 *   php bin/perbaiki-kredensial.php
 *       Laporan saja. Tidak menulis apa pun.
 *
 *   php bin/perbaiki-kredensial.php --perbaiki 6:<secret> 5:<secret>
 *       Enkripsi ulang secret milik kredensial #6 dan #5 dengan app_key
 *       yang berlaku sekarang.
 *
 *   php bin/perbaiki-kredensial.php --rotasi-app-key --perbaiki 6:<s> 5:<s>
 *       Ganti security.app_key dengan nilai acak baru, lalu tulis ulang
 *       seluruh secret di atas memakai kunci baru itu.
 *
 *       Rotasi DITOLAK bila ada kredensial yang secret-nya masih dapat
 *       dibuka sekarang namun tidak Anda sertakan — memutar kunci akan
 *       mematikannya, dan kerusakan semacam itu tidak boleh terjadi diam-diam.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;

// ---------------------------------------------------------------------
// Muat konfigurasi persis seperti App::bootConfig()
// ---------------------------------------------------------------------
$berkasConfig = BASE_PATH . '/config/config.php';
if (!is_file($berkasConfig)) {
    exit("config/config.php tidak ditemukan di " . BASE_PATH . "/config/\n");
}
Config::load(require $berkasConfig);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

if (Config::get('db.host') === null) {
    exit("config/config.php termuat tetapi tidak berisi kunci db.host. Berhenti.\n");
}

// ---------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------
$argumen  = array_slice($argv, 1);
$rotasi   = in_array('--rotasi-app-key', $argumen, true);
$perbaiki = [];           // id => secret plaintext
$kumpul   = false;

foreach ($argumen as $a) {
    if ($a === '--perbaiki') {
        $kumpul = true;
        continue;
    }
    if (str_starts_with($a, '--')) {
        $kumpul = false;
        continue;
    }
    if ($kumpul) {
        [$id, $secret] = array_pad(explode(':', $a, 2), 2, '');
        if ($secret === '' || !ctype_digit($id)) {
            exit("Argumen tidak sah: {$a}. Bentuknya <id>:<secret>.\n");
        }
        $perbaiki[(int) $id] = $secret;
    }
}

$menulis = $rotasi || $perbaiki !== [];

function samar(string $v, int $depan = 6): string
{
    $n = strlen($v);

    return $n <= $depan + 4
        ? str_repeat('*', $n)
        : substr($v, 0, $depan) . '......' . substr($v, -4) . " [{$n}]";
}

echo "=====================================================================\n";
echo " PERBAIKAN KREDENSIAL API LIS   " . date('Y-m-d H:i:s T') . "\n";
echo " mode: " . ($menulis ? ($rotasi ? 'ROTASI app_key + tulis ulang secret' : 'tulis ulang secret') : 'LAPORAN SAJA') . "\n";
echo "=====================================================================\n\n";

$appKey = (string) Config::get('security.app_key', '');
echo "security.app_key : " . ($appKey === '' ? '(kosong)' : samar($appKey)) . "\n";
echo "Crypto::tersedia : " . (Crypto::tersedia() ? 'ya' : 'TIDAK') . "\n";

if (!Crypto::tersedia()) {
    exit("\nEnkripsi tidak aktif. Isi security.app_key lebih dulu.\n");
}

// Uji bolak-balik: membuktikan kunci yang berlaku memang bekerja.
$cobaan = 'uji-' . bin2hex(random_bytes(8));
if (Crypto::dekripsi(Crypto::enkripsi($cobaan)) !== $cobaan) {
    exit("\nEnkripsi tidak lulus uji bolak-balik. Berhenti sebelum menulis apa pun.\n");
}
echo "uji bolak-balik  : BERHASIL\n";

// ---------------------------------------------------------------------
// Keadaan sekarang
// ---------------------------------------------------------------------
$semua = Database::select(
    'SELECT id, nama, api_key, scopes, aktif, secret_enc, secret_hash FROM api_clients ORDER BY id'
);

echo "\nKeadaan kredensial\n";
echo "---------------------------------------------------------------------\n";

$dapatDibuka = [];   // id kredensial yang secret-nya masih terbaca sekarang

foreach ($semua as $c) {
    $id  = (int) $c['id'];
    $enc = $c['secret_enc'] ?? null;

    if ($enc === null || $enc === '') {
        $status = 'secret_enc NULL';
    } else {
        $buka = Crypto::dekripsi((string) $enc);
        if ($buka === null || $buka === '') {
            $status = 'TIDAK DAPAT DIDEKRIPSI';
        } else {
            $status          = 'terbaca (' . samar($buka) . ')';
            $dapatDibuka[]   = $id;
        }
    }

    printf(
        "  #%-2d %-32s %-9s %-22s %s\n",
        $id,
        substr((string) $c['nama'], 0, 32),
        $c['aktif'] ? 'aktif' : 'NONAKTIF',
        (string) $c['scopes'],
        $status
    );
}

// ---------------------------------------------------------------------
// Pembuktian bcrypt untuk setiap secret yang disodorkan
// ---------------------------------------------------------------------
$sah = [];

if ($perbaiki !== []) {
    echo "\nPembuktian secret yang Anda sertakan\n";
    echo "---------------------------------------------------------------------\n";

    $indeks = [];
    foreach ($semua as $c) {
        $indeks[(int) $c['id']] = $c;
    }

    foreach ($perbaiki as $id => $secret) {
        if (!isset($indeks[$id])) {
            echo "  #{$id} tidak ada di tabel api_clients. Dilewati.\n";
            continue;
        }
        $c    = $indeks[$id];
        $hash = (string) ($c['secret_hash'] ?? '');

        if ($hash === '') {
            echo "  #{$id} {$c['nama']}: secret_hash kosong, kebenarannya tidak dapat dibuktikan. Dilewati.\n";
            continue;
        }
        if (!password_verify($secret, $hash)) {
            echo "  #{$id} {$c['nama']}: bcrypt TIDAK COCOK. Bukan secret kredensial ini. Dilewati.\n";
            continue;
        }

        echo "  #{$id} {$c['nama']}: bcrypt COCOK.\n";
        $sah[$id] = $secret;
    }

    if ($sah === []) {
        exit("\nTidak ada secret yang lolos pembuktian. Tidak ada yang ditulis.\n");
    }
}

// ---------------------------------------------------------------------
// Penjaga rotasi: jangan mematikan kredensial yang sekarang masih hidup
// ---------------------------------------------------------------------
if ($rotasi) {
    $akanMati = array_diff($dapatDibuka, array_keys($sah));

    if ($akanMati !== []) {
        echo "\nROTASI DITOLAK\n";
        echo "---------------------------------------------------------------------\n";
        echo "  Kredensial berikut secret-nya masih terbaca dengan app_key sekarang,\n";
        echo "  tetapi Anda tidak menyertakan secret aslinya:\n\n";
        foreach ($akanMati as $id) {
            echo "     #{$id}\n";
        }
        echo "\n  Memutar app_key akan membuatnya tidak dapat dibuka lagi, persis\n";
        echo "  kerusakan yang sedang kita perbaiki. Sertakan secret-nya, atau\n";
        echo "  jalankan tanpa --rotasi-app-key.\n";
        exit(1);
    }
}

if (!$menulis) {
    echo "\nLaporan selesai. Tidak ada yang diubah.\n";
    echo "Untuk memperbaiki:  php bin/perbaiki-kredensial.php --perbaiki <id>:<secret> ...\n";
    exit;
}

// ---------------------------------------------------------------------
// Rotasi app_key
// ---------------------------------------------------------------------
if ($rotasi) {
    $appKeyBaru = bin2hex(random_bytes(32));
    $isi        = file_get_contents($berkasConfig);

    if ($isi === false) {
        exit("Tidak dapat membaca config/config.php.\n");
    }

    $pola  = "/('app_key'\s*=>\s*)'[^']*'/";
    $jadi  = preg_replace($pola, "$1'" . $appKeyBaru . "'", $isi, 1, $jumlah);

    if ($jumlah !== 1 || $jadi === null) {
        exit("Baris 'app_key' => '...' tidak ditemukan tepat satu kali di config/config.php. "
            . "Ubah manual lalu jalankan lagi tanpa --rotasi-app-key.\n");
    }

    $cadangan = $berkasConfig . '.bak-' . date('YmdHis');
    if (!copy($berkasConfig, $cadangan)) {
        exit("Gagal membuat cadangan config.php. Berhenti.\n");
    }
    if (file_put_contents($berkasConfig, $jadi) === false) {
        exit("Gagal menulis config.php. Cadangan ada di {$cadangan}\n");
    }

    // Pakai kunci baru untuk sisa proses ini.
    $konfigBaru                        = require $berkasConfig;
    Config::load($konfigBaru);

    echo "\napp_key diputar\n";
    echo "---------------------------------------------------------------------\n";
    echo "  nilai baru : " . samar($appKeyBaru) . "\n";
    echo "  cadangan   : " . basename($cadangan) . "\n";

    if (Crypto::dekripsi(Crypto::enkripsi($cobaan)) !== $cobaan) {
        echo "  !! kunci baru tidak lulus uji. Kembalikan dari cadangan.\n";
        exit(1);
    }
    echo "  uji kunci  : BERHASIL\n";
}

// ---------------------------------------------------------------------
// Tulis ulang secret_enc
// ---------------------------------------------------------------------
echo "\nMenulis secret_enc\n";
echo "---------------------------------------------------------------------\n";

foreach ($sah as $id => $secret) {
    $enc = Crypto::enkripsi($secret);

    // Baca kembali sebelum menyimpan. Menulis nilai yang belum terbukti
    // dapat dibuka hanya memindahkan kerusakan, tidak menghapusnya.
    if (Crypto::dekripsi($enc) !== $secret) {
        echo "  #{$id}: hasil enkripsi tidak dapat dibuka kembali. TIDAK disimpan.\n";
        continue;
    }

    Database::update('api_clients', ['secret_enc' => $enc], 'id = ?', [$id]);
    echo "  #{$id}: secret_enc ditulis ulang dan terbukti dapat dibuka.\n";
}

// ---------------------------------------------------------------------
// Pemeriksaan akhir dari database, bukan dari memori
// ---------------------------------------------------------------------
echo "\nPemeriksaan akhir (dibaca ulang dari database)\n";
echo "---------------------------------------------------------------------\n";

foreach (array_keys($sah) as $id) {
    $c    = Database::selectOne('SELECT id, nama, secret_enc FROM api_clients WHERE id = ?', [$id]);
    $buka = $c === null ? null : Crypto::dekripsi((string) $c['secret_enc']);

    printf(
        "  #%-2d %-32s %s\n",
        $id,
        substr((string) ($c['nama'] ?? '?'), 0, 32),
        ($buka !== null && hash_equals($buka, $sah[$id]))
            ? 'BERHASIL — X-Signature akan diterima'
            : 'MASIH GAGAL'
    );
}

if ($rotasi) {
    echo "\nCatatan: app_key berubah. Kredensial mana pun yang secret-nya tidak\n";
    echo "ikut ditulis ulang barusan sudah tidak dapat dipakai untuk X-Signature.\n";
}

echo "\nSelesai.\n";
