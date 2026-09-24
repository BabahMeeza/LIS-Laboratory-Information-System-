<?php
declare(strict_types=1);

/**
 * Periksa kelengkapan berkas aplikasi terhadap daftar rute.
 *
 * MENGAPA ADA BERKAS INI
 *
 * Pemasangan yang dilakukan sepotong-sepotong meninggalkan lubang yang
 * tidak terlihat sampai ada orang membuka halamannya. Gejalanya selalu
 * sama — 500 tanpa keterangan — dan satu-satunya cara menemukannya
 * selama ini adalah menunggu petugas melapor:
 *
 *   RuntimeException: Controller tidak ditemukan:
 *   App\Controllers\CriticalController
 *
 * Padahal seluruh daftarnya sudah ada di routes.php sejak awal. Berkas
 * ini membacanya, lalu memeriksa satu per satu: kelasnya ada? metodenya
 * ada? berkas tampilan yang dipanggilnya ada?
 *
 * Jalankan setiap selesai menyalin berkas ke server. Sepuluh detik di
 * sini menggantikan satu putaran laporan-dan-perbaikan.
 *
 * HANYA CLI. Tidak mengubah apa pun.
 *
 *   php bin/cek-berkas.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/app/bootstrap.php';

$rute = BASE_PATH . '/app/routes.php';
if (!is_file($rute)) {
    exit("app/routes.php tidak ditemukan. Jalankan dari folder aplikasi.\n");
}

$isi = (string) file_get_contents($rute);

// Handler ditulis sebagai 'App\Controllers\XxxController@metode'.
preg_match_all(
    "/'(App\\\\\\\\?[A-Za-z0-9_\\\\\\\\]+)@([A-Za-z0-9_]+)'/",
    $isi,
    $cocok,
    PREG_SET_ORDER
);

$garis = str_repeat('=', 72);
echo "$garis\n KELENGKAPAN BERKAS APLIKASI\n $rute\n$garis\n";

$dilihat   = [];
$hilang    = [];
$metodeHil = [];
$okKelas   = 0;

foreach ($cocok as $m) {
    $kelas  = str_replace('\\\\', '\\', $m[1]);
    $metode = $m[2];
    $kunci  = $kelas . '@' . $metode;

    if (isset($dilihat[$kunci])) {
        continue;
    }
    $dilihat[$kunci] = true;

    // App\Controllers\Foo  ->  app/Controllers/Foo.php
    $relatif = str_replace('\\', '/', $kelas);
    $relatif = preg_replace('#^App/#', 'app/', $relatif) . '.php';
    $berkas  = BASE_PATH . '/' . $relatif;

    if (!is_file($berkas)) {
        $hilang[$relatif][] = $metode;
        continue;
    }

    if (!class_exists($kelas)) {
        $hilang[$relatif][] = $metode . '  (berkas ada, kelas tidak termuat)';
        continue;
    }

    if (!method_exists($kelas, $metode)) {
        $metodeHil[] = $relatif . '  ->  ' . $metode . '()';
        continue;
    }

    $okKelas++;
}

// -----------------------------------------------------------------
// Tampilan yang dipanggil controller
//
// Controller yang lengkap tetap menghasilkan 500 bila berkas tampilan
// yang dipanggilnya tidak ikut tersalin. Karena itu ikut diperiksa.
// -----------------------------------------------------------------

$viewHilang = [];
foreach (glob(BASE_PATH . '/app/Controllers/*.php') ?: [] as $c) {
    $src = (string) file_get_contents($c);
    preg_match_all("/->view\(\s*'([a-zA-Z0-9_\/\.-]+)'/", $src, $v);
    foreach (array_unique($v[1] ?? []) as $nama) {
        $vb = BASE_PATH . '/app/Views/' . $nama . '.php';
        if (!is_file($vb)) {
            $viewHilang[] = basename($c) . '  ->  app/Views/' . $nama . '.php';
        }
    }
}

// -----------------------------------------------------------------
// Laporan
// -----------------------------------------------------------------

printf("\nRute diperiksa      : %d\n", count($dilihat));
printf("Handler lengkap     : %d\n", $okKelas);

if ($hilang !== []) {
    echo "\n" . str_repeat('-', 72) . "\n";
    echo " BERKAS HILANG (" . count($hilang) . ") — inilah penyebab 500\n";
    echo str_repeat('-', 72) . "\n";
    foreach ($hilang as $berkas => $metode) {
        echo "  $berkas\n";
        echo '      dipakai rute: ' . implode(', ', array_unique($metode)) . "\n";
    }
}

if ($metodeHil !== []) {
    echo "\n" . str_repeat('-', 72) . "\n";
    echo " METODE HILANG (" . count($metodeHil) . ") — berkasnya ada tapi versinya lama\n";
    echo str_repeat('-', 72) . "\n";
    foreach ($metodeHil as $x) {
        echo "  $x\n";
    }
}

if ($viewHilang !== []) {
    echo "\n" . str_repeat('-', 72) . "\n";
    echo " TAMPILAN HILANG (" . count($viewHilang) . ")\n";
    echo str_repeat('-', 72) . "\n";
    foreach ($viewHilang as $x) {
        echo "  $x\n";
    }
}

echo "\n$garis\n";

if ($hilang === [] && $metodeHil === [] && $viewHilang === []) {
    echo " Lengkap. Semua handler dan tampilan yang dirujuk routes.php ada.\n";
    echo " Bila masih ada halaman yang 500, sebabnya bukan berkas yang hilang —\n";
    echo " lihat storage/logs untuk galat sebenarnya.\n";
    echo "$garis\n";
    exit(0);
}

echo " Salin berkas yang disebut di atas ke server, lalu jalankan ulang\n";
echo " perintah ini sampai berbunyi \"Lengkap\".\n";
echo "$garis\n";
exit(1);
