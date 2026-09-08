<?php
declare(strict_types=1);

/**
 * Bootstrap: autoloader PSR-4 sederhana dan pemeriksaan lingkungan.
 * Dipakai oleh front controller web maupun skrip CLI di bin/.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('LIS_VERSI')) {
    define('LIS_VERSI', '1.0.0');
}

/**
 * Kegagalan lingkungan dilaporkan dengan pesan yang menyebutkan langkah
 * perbaikannya, bukan halaman 500 kosong. Ini penting karena PHP untuk CLI
 * dan PHP untuk Apache memuat php.ini yang berbeda: ekstensi bisa ada saat
 * `php bin/selftest.php` dijalankan namun hilang di peramban, dan galatnya
 * baru muncul di tengah halaman sebagai "Call to undefined function".
 */
$gagalLingkungan = static function (string $judul, array $langkah): never {
    $cli = PHP_SAPI === 'cli';
    if (!$cli && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    $baris = ['LIS tidak dapat dijalankan.', '', $judul, ''];
    foreach ($langkah as $l) {
        $baris[] = $l === '' ? '' : '  - ' . $l;
    }
    $baris[] = '';
    $baris[] = 'php.ini yang sedang dipakai (' . PHP_SAPI . '): '
        . (php_ini_loaded_file() ?: 'tidak diketahui');

    exit(implode(PHP_EOL, $baris) . PHP_EOL);
};

if (PHP_VERSION_ID < 80000) {
    $gagalLingkungan(
        'Versi PHP terlalu lama: ' . PHP_VERSION . ' (diperlukan 8.0 atau lebih baru).',
        ['Perbarui XAMPP ke versi dengan PHP 8.0+.']
    );
}

// Ekstensi wajib. Tanpa pemeriksaan ini, ekstensi yang hilang baru terasa
// jauh di dalam aplikasi: mbstring muncul sebagai "Call to undefined
// function mb_strlen()", openssl sebagai kredensial API yang gagal
// didekripsi, pdo_mysql sebagai "could not find driver".
$ekstensiWajib = [
    'pdo'       => 'lapisan database',
    'pdo_mysql' => 'koneksi ke MySQL/MariaDB',
    'mbstring'  => 'pemotongan teks aman UTF-8 (nama pasien, komentar hasil)',
    'json'      => 'API dan komunikasi dengan middleware',
    'openssl'   => 'enkripsi secret kredensial API',
];

$hilang = [];
foreach ($ekstensiWajib as $ext => $untuk) {
    if (!extension_loaded($ext)) {
        $hilang[] = $ext . ' — dibutuhkan untuk ' . $untuk;
    }
}

if ($hilang !== []) {
    $gagalLingkungan(
        'Ekstensi PHP berikut belum aktif:',
        array_merge($hilang, [
            '',
            'Perbaikan pada XAMPP: buka php.ini yang disebut di bawah,',
            'hapus tanda titik-koma di depan baris extension=<nama>,',
            'lalu jalankan ulang Apache dari panel XAMPP.',
        ])
    );
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// Pastikan folder penyimpanan tersedia.
foreach (['storage/logs', 'storage/tmp', 'storage/reports'] as $dir) {
    $path = BASE_PATH . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}
