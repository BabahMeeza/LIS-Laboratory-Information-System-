<?php
declare(strict_types=1);

/**
 * Petakan template pemeriksaan Khanza ke master pemeriksaan LIS.
 *
 * INI AKAR MASALAH "TIDAK SEMUA PEMERIKSAAN MASUK"
 * ------------------------------------------------
 * Khanza mengirim satu baris per parameter: satu paket "Hematologi Darah
 * Rutin" berisi Hemoglobin, Leukosit, Trombosit, MCV, dan seterusnya —
 * masing-masing dengan kd_jenis_prw yang sama tetapi id_template berbeda.
 *
 * KhanzaService::cariTest() memetakan tiap baris lewat khanza_templates
 * (kd_jenis_prw + id_template -> test_id). Selama test_id masih kosong,
 * baris itu tidak menemukan pasangan dan DIBUANG dari order. Ordernya
 * tetap terbentuk dengan sisanya, jadi gejalanya bukan kegagalan yang
 * kentara melainkan permintaan yang isinya berkurang diam-diam.
 *
 * Jalan pintas yang berbahaya: memetakan lewat kolom tests.khanza_kd_jenis_prw
 * saja, tanpa id_template. Seluruh parameter dalam satu paket punya
 * kd_jenis_prw yang sama, sehingga semuanya menunjuk SATU pemeriksaan LIS,
 * lalu OrderService menyatukannya dengan array_unique menjadi satu item.
 * Paket 16 parameter menjadi 1. Pemetaan harus per id_template.
 *
 * ATURAN PENCOCOKAN
 * -----------------
 * Otomatis HANYA bila nama ternormalkan cocok persis, atau lewat daftar
 * padanan yang ditulis tangan di bawah. Selebihnya ditampilkan beserta
 * calon terdekat dan dibiarkan untuk diputuskan manusia.
 *
 * Ini bukan sikap hati-hati yang berlebihan. Memetakan "Mid%" ke "Monosit"
 * terlihat masuk akal dan salah: Mid pada penghitung 3-part adalah monosit
 * + eosinofil + basofil. Hasilnya akan tersimpan dengan nama yang keliru,
 * dengan nilai rujukan yang keliru, dan tidak ada yang menyadarinya karena
 * angkanya tetap tampak wajar.
 *
 * HANYA CLI. Bawaannya hanya melapor.
 *
 *   php bin/petakan-khanza.php                 -- laporan
 *   php bin/petakan-khanza.php --terapkan      -- tulis yang cocok pasti
 *   php bin/petakan-khanza.php --calon         -- tampilkan calon untuk sisanya
 *   php bin/petakan-khanza.php --kd=LK001      -- batasi pada satu kd_jenis_prw
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$berkasConfig = BASE_PATH . '/config/config.php';
if (!is_file($berkasConfig)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkasConfig);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

if (Config::get('db.host') === null) {
    exit("config/config.php termuat tetapi db.host tidak terbaca. Berhenti.\n");
}

$terapkan = false;
$calon    = false;
$kdFilter = '';

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terapkan') { $terapkan = true; continue; }
    if ($a === '--calon')    { $calon = true;    continue; }
    if (str_starts_with($a, '--kd=')) { $kdFilter = substr($a, 5); }
}

/**
 * Padanan yang ditulis tangan.
 *
 * Kunci: nama template Khanza yang sudah dinormalkan.
 * Nilai: kode pemeriksaan LIS.
 *
 * Hanya berisi padanan yang benar-benar merujuk besaran yang sama.
 * Yang sengaja TIDAK ada di sini, dan alasannya:
 *   gran%     - granulosit pada 3-part = neutrofil + eosinofil + basofil
 *   mid%      - monosit + eosinofil + basofil
 *   diffcount - bukan satu besaran, melainkan sekumpulan
 *   sdt       - sediaan darah tepi, pembacaan mikroskopis, bukan angka alat
 */
const PADANAN = [
    'hb'                  => 'HB',
    'haemoglobin'         => 'HB',
    'hemoglobin'          => 'HB',
    'ht'                  => 'HT',
    'hct'                 => 'HT',
    'hematokrit'          => 'HT',
    'haematokrit'         => 'HT',
    'erytrosit'           => 'RBC',
    'eritrosit'           => 'RBC',
    'eritrocit'           => 'RBC',
    'rbc'                 => 'RBC',
    'leukosit'            => 'WBC',
    'lekosit'             => 'WBC',
    'leucosit'            => 'WBC',
    'wbc'                 => 'WBC',
    'trombosit'           => 'PLT',
    'trombocit'           => 'PLT',
    'platelet'            => 'PLT',
    'plt'                 => 'PLT',
    'lymph'               => 'LYMPH',
    'limfosit'            => 'LYMPH',
    'lymphosit'           => 'LYMPH',
    'limposit'            => 'LYMPH',
    'neutrofil'           => 'NEUT',
    'netrofil'            => 'NEUT',
    'monosit'             => 'MONO',
    'eosinofil'           => 'EO',
    'eosinophil'          => 'EO',
    'basofil'             => 'BASO',
    'basophil'            => 'BASO',
    'led'                 => 'LED',
    'lajuendapdarah'      => 'LED',
    'bsr'                 => 'LED',
    'golongandarah'       => 'GOLDA',
    'goldarah'            => 'GOLDA',
    'golda'               => 'GOLDA',
    'rhesus'              => 'RHESUS',
    'rh'                  => 'RHESUS',
    'gds'                 => 'GDS',
    'glukosadarahsewaktu' => 'GDS',
    'gdp'                 => 'GDP',
    'ureum'               => 'UREUM',
    'creatinin'           => 'KREAT',
    'kreatinin'           => 'KREAT',
    'asamurat'            => 'UA',
    'sgot'                => 'SGOT',
    'sgpt'                => 'SGPT',
    'cholesteroltotal'    => 'CHOL',
    'kolesteroltotal'     => 'CHOL',
    'trigliserida'        => 'TG',
    'triglyserida'        => 'TG',
    'albumin'             => 'ALB',
    'natrium'             => 'NA',
    'kalium'              => 'K',
    'klorida'             => 'CL',
    'chlorida'            => 'CL',
    'hbsag'               => 'HBSAG',
    'antihiv'             => 'ANTIHIV',
    'antihcv'             => 'ANTIHCV',
    'teskehamilan'        => 'HCG',
    'planotest'           => 'HCG',
    'hcg'                 => 'HCG',
    'bta'                 => 'BTA',
];

/** Normalkan nama supaya ejaan dan tanda baca tidak menghalangi pencocokan. */
function normal(string $s): string
{
    $s = strtolower(trim($s));
    $s = str_replace(['%', '(', ')', '.', ',', '-', '_', '/', '\\', "'"], ' ', $s);
    $s = preg_replace('/\s+/', '', $s) ?? '';

    return $s;
}

echo "=====================================================================\n";
echo " PEMETAAN TEMPLATE KHANZA -> MASTER LIS   " . date('Y-m-d H:i:s T') . "\n";
echo " mode: " . ($terapkan ? 'TERAPKAN' : 'LAPORAN SAJA') . "\n";
echo "=====================================================================\n";

// ---------------------------------------------------------------------
// Master LIS
// ---------------------------------------------------------------------
$tests = Database::select('SELECT id, kode, nama, nama_singkat, aktif FROM tests');

if ($tests === []) {
    exit("\nMaster pemeriksaan LIS kosong. Jalankan database/02_seed_master.sql lebih dulu.\n");
}

$olehKode  = [];
$olehNama  = [];
foreach ($tests as $t) {
    $olehKode[strtoupper((string) $t['kode'])] = $t;
    $olehNama[normal((string) $t['nama'])]     = $t;
    if (($t['nama_singkat'] ?? '') !== '') {
        $olehNama[normal((string) $t['nama_singkat'])] ??= $t;
    }
}

printf("\nmaster LIS   : %d pemeriksaan\n", count($tests));

// ---------------------------------------------------------------------
// Template Khanza yang belum terpetakan
// ---------------------------------------------------------------------
$sql = 'SELECT id, kd_jenis_prw, nm_perawatan, id_template, pemeriksaan, test_id FROM khanza_templates';
$par = [];
if ($kdFilter !== '') {
    $sql .= ' WHERE kd_jenis_prw = ?';
    $par[] = $kdFilter;
}
$sql .= ' ORDER BY kd_jenis_prw, id_template';

$templates = Database::select($sql, $par);

if ($templates === []) {
    exit("\nkhanza_templates kosong. Jalankan Impor Template dari menu Integrasi lebih dulu.\n");
}

$sudah = 0;
$belum = [];
foreach ($templates as $t) {
    if (($t['test_id'] ?? null) !== null) {
        $sudah++;
    } else {
        $belum[] = $t;
    }
}

printf("template     : %d  (sudah terpetakan %d, belum %d)\n\n", count($templates), $sudah, count($belum));

if ($belum === []) {
    exit("Semua template sudah terpetakan.\n");
}

// ---------------------------------------------------------------------
// Cocokkan
// ---------------------------------------------------------------------
$pasti  = [];
$ragu   = [];

foreach ($belum as $t) {
    $nama = trim((string) ($t['pemeriksaan'] ?? ''));
    if ($nama === '') {
        $ragu[] = [$t, null, 'nama pemeriksaan kosong di Khanza'];
        continue;
    }

    $n = normal($nama);

    // 1. padanan tulis tangan
    if (isset(PADANAN[$n]) && isset($olehKode[PADANAN[$n]])) {
        $pasti[] = [$t, $olehKode[PADANAN[$n]], 'padanan'];
        continue;
    }

    // 2. nama ternormalkan cocok persis
    if (isset($olehNama[$n])) {
        $pasti[] = [$t, $olehNama[$n], 'nama sama'];
        continue;
    }

    // 3. kode LIS ditulis apa adanya sebagai nama template
    if (isset($olehKode[strtoupper($nama)])) {
        $pasti[] = [$t, $olehKode[strtoupper($nama)], 'kode sama'];
        continue;
    }

    // Tidak pasti — cari calon terdekat, tetapi jangan dipakai sendiri.
    $terbaik = null;
    $skor    = 0;
    foreach ($tests as $c) {
        similar_text($n, normal((string) $c['nama']), $persen);
        if ($persen > $skor) {
            $skor    = $persen;
            $terbaik = $c;
        }
    }
    $ragu[] = [$t, $terbaik, sprintf('mirip %.0f%%', $skor)];
}

// ---------------------------------------------------------------------
// Terapkan yang pasti
// ---------------------------------------------------------------------
echo "COCOK PASTI (" . count($pasti) . ")\n" . str_repeat('-', 69) . "\n";

$nonaktif = 0;
foreach ($pasti as [$t, $c, $cara]) {
    $tandaMati = (int) $c['aktif'] === 1 ? '' : '  [PEMERIKSAAN NONAKTIF]';
    if ($tandaMati !== '') {
        $nonaktif++;
    }

    printf(
        "  %-8s %-5s %-28s -> %-8s %-24s (%s)%s\n",
        (string) $t['kd_jenis_prw'],
        (string) $t['id_template'],
        mb_substr((string) $t['pemeriksaan'], 0, 28),
        (string) $c['kode'],
        mb_substr((string) $c['nama'], 0, 24),
        $cara,
        $tandaMati
    );

    if ($terapkan) {
        Database::update('khanza_templates', ['test_id' => (int) $c['id']], 'id = ?', [(int) $t['id']]);
    }
}

if ($nonaktif > 0) {
    printf("\n  %d di antaranya menunjuk pemeriksaan NONAKTIF — aktifkan dulu di\n", $nonaktif);
    echo "  master, atau order tetap kehilangan baris itu.\n";
}

// ---------------------------------------------------------------------
// Sisanya
// ---------------------------------------------------------------------
echo "\nPERLU DIPUTUSKAN (" . count($ragu) . ")\n" . str_repeat('-', 69) . "\n";

if (!$calon) {
    echo "  Ringkas. Tambahkan --calon untuk melihat calon terdekat tiap baris.\n\n";
}

$perKd = [];
foreach ($ragu as $r) {
    $perKd[(string) $r[0]['kd_jenis_prw']][] = $r;
}

foreach ($perKd as $kd => $baris) {
    printf("  %s  (%s) — %d baris\n", $kd, mb_substr((string) $baris[0][0]['nm_perawatan'], 0, 40), count($baris));

    if (!$calon) {
        continue;
    }

    foreach ($baris as [$t, $c, $ket]) {
        printf(
            "     %-5s %-30s calon: %-8s %-22s %s\n",
            (string) $t['id_template'],
            mb_substr((string) $t['pemeriksaan'], 0, 30),
            $c === null ? '-' : (string) $c['kode'],
            $c === null ? '-' : mb_substr((string) $c['nama'], 0, 22),
            $ket
        );
    }
    echo "\n";
}

echo "\n" . str_repeat('=', 69) . "\n";

if ($terapkan) {
    printf(" %d template dipetakan. %d masih perlu diputuskan.\n", count($pasti), count($ragu));
    echo " Sisanya dipetakan lewat menu Integrasi > Pemetaan Pemeriksaan.\n";
} else {
    printf(" %d siap dipetakan, %d perlu diputuskan. Tidak ada yang diubah.\n", count($pasti), count($ragu));
    echo " Tambahkan --terapkan untuk menulis yang cocok pasti.\n";
}
