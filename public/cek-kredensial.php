<?php
declare(strict_types=1);

/**
 * DIAGNOSTIK KREDENSIAL API — SEMENTARA.
 *
 * REVISI 2. Versi pertama berkas ini SALAH dan harus diabaikan seluruh
 * keluarannya pada bagian app_key dan dekripsi.
 *
 * Sebabnya: app/bootstrap.php hanya memasang autoloader. Yang memuat
 * config/config.php ke dalam Config adalah App::bootConfig(), dan skrip
 * mandiri ini tidak pernah memanggilnya. Akibatnya Config::get() memulangkan
 * default untuk segalanya, security.app_key terbaca kosong, Crypto::kunci()
 * melempar, dan setiap secret dilaporkan "DEKRIPSI GAGAL" — padahal yang
 * gagal adalah alat ukurnya, bukan yang diukur.
 *
 * Karena itu versi ini memuat konfigurasi persis seperti App::bootConfig(),
 * lalu MEMBUKTIKAN bahwa pemuatan itu berhasil sebelum menyimpulkan apa pun.
 * Bila pembuktian gagal, skrip berhenti dan berkata begitu — bukan
 * melanjutkan dengan angka yang menyesatkan.
 *
 * HAPUS BERKAS INI SETELAH SELESAI.
 *
 * Pemakaian:
 *   .../cek-kredensial.php?token=cek-lis-hanau
 *   .../cek-kredensial.php?token=cek-lis-hanau&secret=<secret_klien>
 */

const TOKEN = 'cek-lis-hanau';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== TOKEN) {
    http_response_code(403);
    echo "Token tidak cocok.\n";
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;

// ---------------------------------------------------------------------
// 0. Muat konfigurasi — dan buktikan bahwa ia benar-benar termuat
// ---------------------------------------------------------------------
$berkasConfig = BASE_PATH . '/config/config.php';
$dipakai      = $berkasConfig;
if (!is_file($berkasConfig)) {
    $dipakai = BASE_PATH . '/config/config.example.php';
}

/** @var array<string,mixed> $config */
$config = require $dipakai;
Config::load($config);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

echo "=====================================================================\n";
echo " DIAGNOSTIK KREDENSIAL API LIS  (revisi 2)\n";
echo " " . date('Y-m-d H:i:s T') . "\n";
echo "=====================================================================\n\n";

echo "0. Pemuatan konfigurasi\n";
echo "---------------------------------------------------------------------\n";
echo "   berkas dipakai   : " . $dipakai . "\n";
echo "   config.php ada   : " . (is_file($berkasConfig) ? 'ya' : 'TIDAK — memakai config.example.php') . "\n";

// Kunci penanda: bila db.host terbaca, Config benar-benar terisi.
$dbHost = Config::get('db.host');
if ($dbHost === null) {
    echo "\n   !! Config TIDAK TERMUAT. db.host tidak terbaca.\n";
    echo "      Seluruh kesimpulan di bawah akan salah, jadi dihentikan di sini.\n";
    exit;
}
echo "   db.host terbaca  : ya (bukti Config terisi)\n";
echo "   app.timezone     : " . (string) Config::get('app.timezone', '(default)') . "\n";
echo "   jam sistem       : " . (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s T') . "\n";

function samar(?string $v, int $depan = 6): string
{
    if ($v === null || $v === '') {
        return '(kosong)';
    }
    $n = strlen($v);
    if ($n <= $depan + 4) {
        return str_repeat('*', $n) . " [panjang {$n}]";
    }

    return substr($v, 0, $depan) . str_repeat('.', 6) . substr($v, -4) . " [panjang {$n}]";
}

// ---------------------------------------------------------------------
// 1. Kunci enkripsi aplikasi
// ---------------------------------------------------------------------
echo "\n1. security.app_key\n";
echo "---------------------------------------------------------------------\n";

$appKey       = (string) Config::get('security.app_key', '');
$appKeyKosong = ($appKey === '' || str_starts_with($appKey, 'ubah_dengan'));

echo "   nilai            : " . samar($appKey) . "\n";
echo "   placeholder      : " . ($appKeyKosong ? 'YA — enkripsi mati' : 'tidak') . "\n";
echo "   openssl aes-gcm  : " . (in_array('aes-256-gcm', openssl_get_cipher_methods(), true) ? 'ada' : 'TIDAK ADA') . "\n";
echo "   Crypto::tersedia : " . (Crypto::tersedia() ? 'ya' : 'TIDAK') . "\n";

// Uji bolak-balik. Ini membuktikan enkripsi hidup tanpa menyentuh data nyata.
if (Crypto::tersedia()) {
    $uji  = 'uji-' . bin2hex(random_bytes(8));
    $bolak = null;
    try {
        $bolak = Crypto::dekripsi(Crypto::enkripsi($uji));
    } catch (\Throwable $e) {
        echo "   uji bolak-balik  : GALAT " . $e->getMessage() . "\n";
    }
    echo "   uji bolak-balik  : " . ($bolak === $uji ? 'BERHASIL — enkripsi berfungsi' : 'GAGAL') . "\n";
}

$semua = Database::select(
    'SELECT id, nama, api_key, scopes, aktif, ip_whitelist, secret_enc, secret_hash, last_used_at
       FROM api_clients ORDER BY id'
);

foreach ($semua as $c) {
    if ($appKey !== '' && hash_equals((string) $c['api_key'], $appKey)) {
        echo "\n   !! app_key SAMA PERSIS dengan api_key kredensial #{$c['id']} ({$c['nama']}).\n";
        echo "      Siapa pun yang memegang API key itu ikut memegang kunci enkripsi\n";
        echo "      seluruh secret yang tersimpan. app_key harus nilai acak tersendiri.\n";
    }
}

// ---------------------------------------------------------------------
// 2. Keadaan tiap kredensial
// ---------------------------------------------------------------------
echo "\n2. Kredensial terdaftar (" . count($semua) . ")\n";
echo "---------------------------------------------------------------------\n";

foreach ($semua as $c) {
    echo "\n   #{$c['id']}  {$c['nama']}\n";
    echo "      api_key      : " . samar((string) $c['api_key'], 10) . "\n";
    echo "      aktif        : " . ($c['aktif'] ? 'ya' : 'TIDAK — setiap permintaan dibalas 401') . "\n";
    echo "      scopes       : " . ((string) $c['scopes'] ?: '(kosong)') . "\n";
    echo "      ip_whitelist : " . ((string) $c['ip_whitelist'] ?: '(bebas)') . "\n";
    echo "      terakhir jadi: " . ((string) ($c['last_used_at'] ?? '') ?: 'belum pernah berhasil') . "\n";
    echo "      secret_hash  : " . ($c['secret_hash'] ? 'ada' : 'TIDAK ADA') . "\n";

    if ($c['secret_enc'] === null || $c['secret_enc'] === '') {
        echo "      secret_enc   : NULL — X-Signature dari klien ini akan ditolak 401\n";
        continue;
    }

    echo "      secret_enc   : ada (" . strlen((string) $c['secret_enc']) . " byte base64)\n";

    $terbuka = null;
    try {
        $terbuka = Crypto::dekripsi((string) $c['secret_enc']);
    } catch (\Throwable $e) {
        echo "                     -> galat: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }

    if ($terbuka === null || $terbuka === '') {
        echo "                     -> DEKRIPSI GAGAL — X-Signature ditolak 401.\n";
        echo "                        app_key sekarang berbeda dari saat kredensial dibuat.\n";
    } else {
        echo "                     -> dekripsi BERHASIL: " . samar($terbuka, 6) . "\n";
    }
}

// ---------------------------------------------------------------------
// 3. Uji satu secret terhadap seluruh kredensial
// ---------------------------------------------------------------------
$uji = (string) ($_GET['secret'] ?? '');

echo "\n3. Uji secret yang dipakai klien\n";
echo "---------------------------------------------------------------------\n";

if ($uji === '') {
    echo "   Dilewati. Tambahkan &secret=<nilai> pada URL untuk mengujinya.\n";
} else {
    echo "   secret diuji     : " . samar($uji, 6) . "\n\n";

    foreach ($semua as $c) {
        // bcrypt tidak bergantung pada app_key: inilah pembanding yang
        // tetap sahih walau enkripsi sedang mati.
        $viaHash = $c['secret_hash'] && password_verify($uji, (string) $c['secret_hash']);

        $viaEnc = false;
        try {
            $t      = Crypto::dekripsi($c['secret_enc'] ?? null);
            $viaEnc = ($t !== null && hash_equals($t, $uji));
        } catch (\Throwable) {
            // biarkan false
        }

        printf(
            "   #%-3s %-34s bcrypt=%-6s enkripsi=%s\n",
            $c['id'],
            substr((string) $c['nama'], 0, 34),
            $viaHash ? 'COCOK' : 'beda',
            $viaEnc ? 'COCOK' : 'beda'
        );
    }

    echo "\n   bcrypt COCOK  = secret ini memang milik kredensial itu.\n";
    echo "   enkripsi beda = LIS tidak bisa memakainya untuk memeriksa X-Signature.\n";
}

echo "\n=====================================================================\n";
echo " Selesai. HAPUS public/cek-kredensial.php setelah dibaca.\n";
echo "=====================================================================\n";
