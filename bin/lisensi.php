<?php
declare(strict_types=1);

/**
 * Terbitkan dan periksa lisensi aplikasi.
 *
 * config/lisensi.php memuat identitas aplikasi - nama, instansi,
 * pengembang, nomor lisensi - beserta sidik sha256 berkas yang
 * menampilkannya, dan satu tanda tangan RSA-SHA256 atas keduanya.
 * App\Core\Lisensi memeriksanya pada setiap permintaan dengan kunci
 * PUBLIK yang tertanam di kode. Bila tidak sah, aplikasi terkunci dan
 * seluruh kredensial API berhenti melayani.
 *
 * KUNCI PRIVAT TIDAK BOLEH ADA DI SERVER
 *
 * Menandatangani menuntut kunci privat; memeriksa cukup dengan kunci
 * publik. Simpan kunci privat di komputer pemegang lisensi, di luar folder
 * aplikasi, dan jangan pernah ikut disalin saat memasang. Selama kunci itu
 * hanya ada pada Anda, tidak seorang pun yang memegang server dapat
 * menerbitkan lisensi yang dianggap sah - berapa pun berkas yang bisa
 * mereka baca di sana.
 *
 * ALUR KERJANYA
 *
 *   Sekali saja, di komputer Anda:
 *     php bin/lisensi.php --buat-kunci=/jalur/aman/lis-privat.pem
 *     lalu tempel kunci publiknya ke app/Core/Lisensi.php
 *
 *   Setiap menerbitkan lisensi, di komputer Anda:
 *     php bin/lisensi.php --terbitkan --kunci-privat=/jalur/aman/lis-privat.pem \
 *        --set=instansi="RSUD Hanau" --set=pengembang="Nama"
 *     lalu kirim config/lisensi.php yang dihasilkan ke server.
 *
 *   Di server, tanpa kunci privat:
 *     php bin/lisensi.php            -- hanya memeriksa keadaan
 *
 * SETELAH MEMASANG PEMBARUAN, TERBITKAN ULANG.
 * Berkas yang diawasi berubah sidiknya setiap kali diperbarui, jadi
 * pembaruan yang sah pun mengunci aplikasi sampai ditandatangani ulang.
 * Ini disengaja: yang membedakan pembaruan sah dari penyuntingan diam-diam
 * adalah adanya pemegang lisensi yang menerbitkan ulang dengan sadar.
 *
 * BATAS KEMAMPUANNYA
 * Tanda tangan tidak dapat dipalsukan tanpa kunci privat. Yang masih
 * terbuka bagi orang dengan akses berkas adalah menyunting kode PHP
 * pemeriksanya sendiri, atau menukar kunci publik yang tertanam. Menutup
 * celah itu menuntut penyandian kode (ionCube, SourceGuardian) atau
 * ekstensi terkompilasi - di luar jangkauan PHP yang sumbernya ikut
 * terpasang.
 *
 * HANYA CLI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('LIS_VERSI')) {
    define('LIS_VERSI', '1.0.0');
}
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Lisensi;

$berkasConfig = BASE_PATH . '/config/config.php';
if (!is_file($berkasConfig)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkasConfig);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

$berkasLisensi = BASE_PATH . '/config/lisensi.php';

$terbitkan  = false;
$set        = [];
$buatKunci  = '';
$kunciPriv  = '';

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terbitkan') {
        $terbitkan = true;
        continue;
    }
    if (str_starts_with($a, '--buat-kunci=')) {
        $buatKunci = substr($a, 13);
        continue;
    }
    if (str_starts_with($a, '--kunci-privat=')) {
        $kunciPriv = substr($a, 15);
        continue;
    }
    if (str_starts_with($a, '--set=')) {
        [$k, $v] = array_pad(explode('=', substr($a, 6), 2), 2, '');
        $k = trim($k);
        if ($k !== '') {
            $set[$k] = trim($v, " \t\"'");
        }
    }
}

echo "=====================================================================\n";
echo " LISENSI APLIKASI   " . date('Y-m-d H:i:s T') . "\n";
echo "=====================================================================\n";

// ---------------------------------------------------------------------
// Kunci privat pemegang lisensi
//
// TIDAK DIBACA DARI config/config.php, dan tidak boleh disimpan di dalam
// folder aplikasi. Yang terpasang di server hanyalah kunci publik, yang
// cukup untuk memeriksa dan tidak dapat dipakai menandatangani.
// ---------------------------------------------------------------------
if ($buatKunci !== '') {
    buatPasanganKunci($buatKunci);
    exit(0);
}

// ---------------------------------------------------------------------
// Data identitas
// ---------------------------------------------------------------------
$bawaan = [
    'aplikasi'    => 'LIS RSUD Hanau',
    'versi'       => LIS_VERSI,
    'instansi'    => 'RSUD Hanau',
    'unit'        => 'Instalasi Laboratorium',
    'pengembang'  => '',
    'kontak'      => '',
    'lisensi'     => 'Lisensi tunggal untuk instansi tersebut di atas',
    'no_lisensi'  => '',
    'diterbitkan' => date('Y-m-d'),
    'berlaku'     => 'Tanpa batas waktu',

    // Dukungan sukarela. Kosong = bagian donasi tidak ditampilkan sama
    // sekali di halaman Tentang. Nilainya ikut ditandatangani, sehingga
    // nomor rekening tidak dapat ditukar tanpa mengunci aplikasi.
    'donasi_bank'      => '',
    'donasi_rekening'  => '',
    'donasi_atas_nama' => '',
    'donasi_qris'      => '',
    'donasi_catatan'   => '',
];

$lama = is_file($berkasLisensi) ? @include $berkasLisensi : null;
$data = is_array($lama['data'] ?? null) ? $lama['data'] : $bawaan;

// Lengkapi medan yang belum ada tanpa menimpa yang sudah diisi.
foreach ($bawaan as $k => $v) {
    $data[$k] ??= $v;
}
foreach ($set as $k => $v) {
    $data[$k] = $v;
}

// ---------------------------------------------------------------------
// Periksa keadaan sekarang
// ---------------------------------------------------------------------
if (!$terbitkan) {
    $keadaan = Lisensi::keadaan();

    echo "\nKEADAAN\n" . str_repeat('-', 69) . "\n";
    echo '  status : ' . ($keadaan['terkunci'] ? 'TERKUNCI' : 'SAH') . "\n";

    if ($keadaan['terkunci']) {
        echo '  sebab  : ' . $keadaan['sebab'] . "\n";
        foreach ($keadaan['rincian'] as $r) {
            echo '           ' . $r . "\n";
        }
    }

    echo "\nIDENTITAS YANG AKAN DITERBITKAN\n" . str_repeat('-', 69) . "\n";
    foreach ($data as $k => $v) {
        printf("  %-13s %s\n", $k, (string) $v === '' ? '(kosong)' : (string) $v);
    }

    echo "\nBERKAS YANG DIAWASI\n" . str_repeat('-', 69) . "\n";
    foreach (Lisensi::DIAWASI as $b) {
        $s = Lisensi::sidikBerkas($b);
        printf("  %-28s %s\n", $b, $s === null ? 'HILANG' : substr($s, 0, 16) . '…');
    }

    echo "\n" . str_repeat('=', 69) . "\n";
    echo " Tidak ada yang diubah. Tambahkan --terbitkan untuk menandatangani.\n";
    exit($keadaan['terkunci'] ? 1 : 0);
}

// ---------------------------------------------------------------------
// Terbitkan
// ---------------------------------------------------------------------
$berkas = [];
$hilang = [];

foreach (Lisensi::DIAWASI as $b) {
    $s = Lisensi::sidikBerkas($b);
    if ($s === null) {
        $hilang[] = $b;
        continue;
    }
    $berkas[$b] = $s;
}

if ($hilang !== []) {
    echo "\nBerkas yang diawasi tidak ditemukan:\n";
    foreach ($hilang as $h) {
        echo '  ' . $h . "\n";
    }
    exit("\nTidak diterbitkan. Lengkapi berkasnya lebih dulu.\n");
}

if ($kunciPriv === '') {
    exit("\nMenerbitkan lisensi menuntut kunci privat pemegang lisensi.\n"
       . "  php bin/lisensi.php --terbitkan --kunci-privat=/jalur/aman/lis-privat.pem\n\n"
       . "Belum punya? Buat sekali saja, di komputer Anda sendiri:\n"
       . "  php bin/lisensi.php --buat-kunci=/jalur/aman/lis-privat.pem\n");
}
if (!is_file($kunciPriv)) {
    exit("\nKunci privat tidak ditemukan: {$kunciPriv}\n");
}

$pem  = (string) file_get_contents($kunciPriv);
$priv = @openssl_pkey_get_private($pem);
if ($priv === false) {
    exit("\nBerkas kunci privat tidak dapat dibaca sebagai kunci PEM yang sah.\n");
}

// Kunci privat harus berpasangan dengan kunci publik yang tertanam.
// Tanpa pemeriksaan ini, lisensi dapat terbit rapi lalu ditolak server,
// dan sebabnya baru ketahuan setelah aplikasi terlanjur terkunci.
$rinci  = openssl_pkey_get_details($priv);
$publikDariPriv = trim((string) ($rinci['key'] ?? ''));
$publikTertanam = Lisensi::kunciPublik();

if ($publikTertanam === '') {
    echo "\nPeringatan: KUNCI_PUBLIK pada app/Core/Lisensi.php masih kosong.\n";
    echo "Tempel kunci publik di bawah ke sana, lalu terbitkan lagi.\n\n";
    echo $publikDariPriv . "\n";
    exit(1);
}
if ($publikDariPriv !== $publikTertanam) {
    exit("\nKunci privat ini BUKAN pasangan dari KUNCI_PUBLIK yang tertanam di\n"
       . "app/Core/Lisensi.php. Lisensinya akan ditolak. Tidak diterbitkan.\n");
}

$manifes = ['data' => $data, 'berkas' => $berkas];
$tanda   = '';

if (!openssl_sign(Lisensi::bahanTandaTangan($manifes), $tanda, $priv, OPENSSL_ALGO_SHA256)) {
    exit("\nGagal menandatangani manifes.\n");
}
$tanda = base64_encode($tanda);

$isi  = "<?php\n";
$isi .= "declare(strict_types=1);\n\n";
$isi .= "/**\n";
$isi .= " * IDENTITAS APLIKASI — BERTANDA TANGAN.\n";
$isi .= " *\n";
$isi .= " * Jangan menyunting berkas ini dengan tangan. Nilai tanda_tangan di\n";
$isi .= " * bawah dihitung dari seluruh isi data dan berkas; satu huruf yang\n";
$isi .= " * berubah membuatnya tidak cocok, aplikasi terkunci, dan seluruh\n";
$isi .= " * kredensial API berhenti melayani.\n";
$isi .= " *\n";
$isi .= " * Tanda tangan RSA-SHA256. Hanya pemegang kunci privat yang dapat\n";
$isi .= " * membuatnya; kunci itu tidak tersimpan di server ini.\n";
$isi .= " *\n";
$isi .= " * Diterbitkan: " . date('Y-m-d H:i:s T') . "\n";
$isi .= " */\n\n";
$isi .= "return [\n";
$isi .= "    'data' => [\n";
foreach ($data as $k => $v) {
    $isi .= sprintf("        %-15s => %s,\n", var_export((string) $k, true), var_export((string) $v, true));
}
$isi .= "    ],\n";
$isi .= "    'berkas' => [\n";
foreach ($berkas as $k => $v) {
    $isi .= sprintf("        %-32s => %s,\n", var_export((string) $k, true), var_export((string) $v, true));
}
$isi .= "    ],\n";
$isi .= "    'tanda_tangan' => " . var_export($tanda, true) . ",\n";
$isi .= "];\n";

if (is_file($berkasLisensi)) {
    $cadangan = $berkasLisensi . '.bak-' . date('YmdHis');
    copy($berkasLisensi, $cadangan);
    echo "\ncadangan : " . basename($cadangan) . "\n";
}

if (file_put_contents($berkasLisensi, $isi) === false) {
    exit("\nGagal menulis config/lisensi.php. Periksa izin berkas.\n");
}

echo "\nDITERBITKAN\n" . str_repeat('-', 69) . "\n";
foreach ($data as $k => $v) {
    printf("  %-13s %s\n", $k, (string) $v === '' ? '(kosong)' : (string) $v);
}
foreach ($berkas as $k => $v) {
    printf("  sidik %-22s %s\n", $k, substr($v, 0, 16) . '…');
}
printf("  %-13s %s\n", 'tanda tangan', substr($tanda, 0, 24) . '…');

// ---------------------------------------------------------------------
// Buktikan dengan membaca ulang, bukan dengan mengandaikan
// ---------------------------------------------------------------------
Lisensi::lupakan();
$keadaan = Lisensi::keadaan();

echo "\nPEMERIKSAAN ULANG\n" . str_repeat('-', 69) . "\n";

if ($keadaan['terkunci']) {
    echo "  MASIH TERKUNCI: " . $keadaan['sebab'] . "\n";
    foreach ($keadaan['rincian'] as $r) {
        echo '  ' . $r . "\n";
    }
    exit(1);
}

echo "  Lisensi sah. Aplikasi dan kredensial API aktif.\n";
echo "\n" . str_repeat('=', 69) . "\n";


/**
 * Buat sepasang kunci penandatangan lisensi.
 *
 * Kunci privat ditulis ke jalur yang Anda tentukan dengan izin 0600, dan
 * SENGAJA ditolak bila jalurnya berada di dalam folder aplikasi: kunci
 * privat yang ikut tersalin saat memasang membatalkan seluruh gunanya
 * memakai tanda tangan asimetris.
 */
function buatPasanganKunci(string $tujuan): void
{
    $nyata = realpath(dirname($tujuan));
    if ($nyata === false) {
        exit("\nFolder tujuan tidak ada: " . dirname($tujuan) . "\n");
    }

    $app = realpath(BASE_PATH);
    if ($app !== false && str_starts_with($nyata . DIRECTORY_SEPARATOR, $app . DIRECTORY_SEPARATOR)) {
        exit("\nJalur itu berada di dalam folder aplikasi:\n  {$nyata}\n\n"
           . "Kunci privat tidak boleh disimpan di sana - ia akan ikut tersalin\n"
           . "setiap kali aplikasi dipasang atau dicadangkan, dan siapa pun yang\n"
           . "memegang server dapat menerbitkan lisensinya sendiri.\n\n"
           . "Pilih tempat di luar aplikasi, mis. ~/kunci-lis/lis-privat.pem\n");
    }

    if (is_file($tujuan)) {
        exit("\nBerkas sudah ada: {$tujuan}\n"
           . "Menimpanya akan membuat SELURUH lisensi yang pernah diterbitkan\n"
           . "menjadi tidak sah. Hapus sendiri lebih dulu bila memang disengaja.\n");
    }

    $res = openssl_pkey_new([
        'private_key_bits' => 3072,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($res === false) {
        exit("\nGagal membuat kunci: " . (openssl_error_string() ?: 'sebab tidak diketahui') . "\n");
    }

    $priv = '';
    openssl_pkey_export($res, $priv);
    $rinci  = openssl_pkey_get_details($res);
    $publik = trim((string) ($rinci['key'] ?? ''));

    if (file_put_contents($tujuan, $priv) === false) {
        exit("\nGagal menulis kunci privat ke {$tujuan}\n");
    }
    @chmod($tujuan, 0600);

    echo "\nPASANGAN KUNCI DIBUAT\n" . str_repeat('-', 69) . "\n";
    echo "  kunci privat : {$tujuan}  (izin 0600)\n";
    echo "  ukuran       : RSA 3072 bit\n\n";
    echo "  SIMPAN KUNCI PRIVAT ITU. Kehilangannya berarti tidak ada lisensi\n";
    echo "  baru yang dapat diterbitkan, dan satu-satunya jalan adalah membuat\n";
    echo "  pasangan baru lalu mengganti kunci publik di seluruh pemasangan.\n";
    echo "  Jangan pernah menyalinnya ke server.\n\n";
    echo "LANGKAH BERIKUTNYA\n" . str_repeat('-', 69) . "\n";
    echo "  Tempel kunci publik di bawah ke app/Core/Lisensi.php, pada\n";
    echo "  konstanta KUNCI_PUBLIK, di antara <<<'PEM' dan PEM;\n\n";
    echo $publik . "\n\n";
}
