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

/**
 * Padanan yang HANYA berlaku di dalam satu kategori.
 *
 * INI YANG MENJAGA "LEUKOSIT" TIDAK SALAH TEMPAT
 *
 * Nama parameter di Khanza tidak menyebutkan spesimennya. Paket "Urine
 * Lengkap" berisi baris bernama "Leukosit", dan paket "Darah Rutin" juga
 * berisi baris bernama "Leukosit". Keduanya identik sebagai teks, dan
 * keduanya tertangkap PADANAN global sebagai 'leukosit' => 'WBC'.
 *
 * Tanpa pembatas, hitung leukosit SEDIMEN URINE tersimpan sebagai hitung
 * leukosit DARAH. Satuannya berbeda (/LPB lawan 10^3/uL), nilai rujukannya
 * berbeda, dan angkanya tetap terlihat wajar. Tidak ada yang menyadarinya
 * sampai ada klinisi mengambil keputusan dari angka itu.
 *
 * Yang membedakan adalah PAKET tempat baris itu berada — nm_perawatan.
 * Daftar di bawah dipakai lebih dulu bila kategori paketnya dikenali;
 * PADANAN global hanya dipakai bila tidak bertentangan dengan kategori itu.
 */
const PADANAN_KONTEKS = [
    // 3 = Urinalisa
    3 => [
        'leukosit'         => 'U_SED_LEU',
        'lekosit'          => 'U_SED_LEU',
        'leucosit'         => 'U_SED_LEU',
        'wbc'              => 'U_SED_LEU',
        'eritrosit'        => 'U_SED_ERI',
        'erytrosit'        => 'U_SED_ERI',
        'eritrocit'        => 'U_SED_ERI',
        'rbc'              => 'U_SED_ERI',
        'epitel'           => 'U_SED_EPI',
        'selepitel'        => 'U_SED_EPI',
        'silinder'         => 'U_SED_SIL',
        'cast'             => 'U_SED_SIL',
        'kristal'          => 'U_SED_KRIS',
        'bakteri'          => 'U_SED_BAKT',
        'ph'               => 'U_PH',
        'bj'               => 'U_BJ',
        'beratjenis'       => 'U_BJ',
        'protein'          => 'U_PROT',
        'albumin'          => 'U_PROT',   // carik celup menyebutnya albumin
        'reduksi'          => 'U_GLU',
        'glukosa'          => 'U_GLU',
        'gula'             => 'U_GLU',
        'keton'            => 'U_KET',
        'bendaketon'       => 'U_KET',
        'bilirubin'        => 'U_BIL',
        'urobilinogen'     => 'U_URO',
        'urobilin'         => 'U_URO',
        'nitrit'           => 'U_NIT',
        'darahsamar'       => 'U_BLD',
        'blood'            => 'U_BLD',
        'leukositesterase' => 'U_LEU',
        'esterase'         => 'U_LEU',
        'warna'            => 'U_WARNA',
        'kejernihan'       => 'U_KEJERNIHAN',
        'kekeruhan'        => 'U_KEJERNIHAN',
    ],

    // 6 = Feses
    6 => [
        'makroskopis'      => 'F_MAKRO',
        'makroskopik'      => 'F_MAKRO',
        'warna'            => 'F_MAKRO',
        'konsistensi'      => 'F_MAKRO',
        'telurcacing'      => 'F_TELUR',
        'telorcacing'      => 'F_TELUR',
        'cacing'           => 'F_TELUR',
        'darahsamar'       => 'F_DARAH',
        'benzidin'         => 'F_DARAH',
        'occultblood'      => 'F_DARAH',
    ],
];

/**
 * Tebak kategori dari NAMA PAKET Khanza.
 *
 * Hanya dipakai untuk membedakan padanan yang bertabrakan, tidak pernah
 * untuk memaksakan pemetaan. Bila paketnya tidak dikenali hasilnya 0, dan
 * baris yang ambigu dilempar ke daftar "perlu diputuskan" — bukan ditebak.
 */
function kategoriPaket(string $namaPaket): int
{
    $p = strtolower($namaPaket);

    $petunjuk = [
        3 => ['urin', 'urine', 'urinalisa', 'urinalisis'],
        6 => ['feses', 'faeces', 'tinja', 'fecal'],
        5 => ['sputum', 'kultur', 'mikrobiologi', 'pewarnaan gram', 'bta', 'tcm'],
        4 => ['widal', 'serologi', 'imuno', 'hbsag', 'hiv', 'sifilis', 'tpha', 'dengue', 'ns1', 'hepatitis'],
        1 => ['darah rutin', 'darah lengkap', 'hematologi', 'cbc', 'hitung jenis', 'golongan darah'],
        2 => ['kimia', 'fungsi hati', 'fungsi ginjal', 'lemak', 'lipid', 'elektrolit', 'gula darah', 'glukosa darah'],
    ];

    foreach ($petunjuk as $kategori => $kata) {
        foreach ($kata as $k) {
            if (str_contains($p, $k)) {
                return $kategori;
            }
        }
    }

    return 0;
}

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
$tests = Database::select('SELECT id, kode, nama, nama_singkat, aktif, category_id FROM tests');

if ($tests === []) {
    exit("\nMaster pemeriksaan LIS kosong. Jalankan database/02_seed_master.sql lebih dulu.\n");
}

// $olehNama menyimpan SEMUA pemeriksaan yang berbagi satu nama ternormalkan,
// bukan yang pertama saja.
//
// Versi sebelumnya menyimpan satu nilai per nama, sehingga bila dua
// pemeriksaan bernama sama, yang menang adalah yang kebetulan lebih dulu
// dibaca dari database. Tabrakan seperti itu tidak menimbulkan galat dan
// tidak tercatat di mana pun — ia hanya menghasilkan pemetaan yang salah.
// Dengan menyimpan daftar, tabrakan menjadi terlihat dan dapat ditangani.
$olehKode  = [];
$olehNama  = [];
foreach ($tests as $t) {
    $olehKode[strtoupper((string) $t['kode'])] = $t;
    $olehNama[normal((string) $t['nama'])][]   = $t;
    if (($t['nama_singkat'] ?? '') !== '') {
        $olehNama[normal((string) $t['nama_singkat'])][] = $t;
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

// Kata yang artinya berubah menurut spesimen.
//
// Dihitung, bukan ditulis tangan: setiap kata yang muncul di PADANAN
// global DAN di PADANAN_KONTEKS dengan tujuan yang berbeda adalah kata
// yang tidak boleh dipetakan tanpa tahu paketnya. "Leukosit" masuk ke
// sini karena global menunjuk WBC sedangkan konteks urine menunjuk
// U_SED_LEU. Menghitungnya berarti daftar ini ikut terbarui sendiri
// setiap kali ada padanan baru ditambahkan.
$kataBertabrakan = [];
foreach (PADANAN_KONTEKS as $daftarKategori) {
    foreach ($daftarKategori as $kata => $kodeTujuan) {
        if (isset(PADANAN[$kata]) && PADANAN[$kata] !== $kodeTujuan) {
            $kataBertabrakan[$kata] = true;
        }
    }
}

foreach ($belum as $t) {
    $nama = trim((string) ($t['pemeriksaan'] ?? ''));
    if ($nama === '') {
        $ragu[] = [$t, null, 'nama pemeriksaan kosong di Khanza'];
        continue;
    }

    $n = normal($nama);

    // Kategori paket — satu-satunya petunjuk spesimen yang dibawa Khanza.
    $kat = kategoriPaket((string) ($t['nm_perawatan'] ?? ''));

    // 1. Padanan khusus kategori. Dipakai LEBIH DULU daripada padanan
    //    global, karena justru inilah yang membedakan leukosit urine dari
    //    leukosit darah.
    if ($kat !== 0 && isset(PADANAN_KONTEKS[$kat][$n]) && isset($olehKode[PADANAN_KONTEKS[$kat][$n]])) {
        $pasti[] = [$t, $olehKode[PADANAN_KONTEKS[$kat][$n]], 'padanan ' . $kat];
        continue;
    }

    // 1b. Paket tidak menunjukkan spesimen, dan katanya termasuk yang
    //     artinya berubah menurut spesimen. Di sini menebak berarti
    //     memilih antara darah dan urine dengan melempar koin.
    if ($kat === 0 && isset($kataBertabrakan[$n])) {
        $ragu[] = [$t, $olehKode[PADANAN[$n]] ?? null, sprintf(
            'nama paket "%s" tidak menunjukkan spesimen, sedangkan "%s" berbeda arti '
            . 'di darah dan di urine — putuskan manual',
            mb_substr((string) ($t['nm_perawatan'] ?? ''), 0, 30),
            $nama
        )];
        continue;
    }

    // 2. Padanan global — tetapi TIDAK bila hasilnya bertentangan dengan
    //    kategori paketnya. "Leukosit" di paket urine tidak boleh mendarat
    //    di WBC hematologi hanya karena daftar global mengatakan begitu.
    if (isset(PADANAN[$n]) && isset($olehKode[PADANAN[$n]])) {
        $c = $olehKode[PADANAN[$n]];
        if ($kat === 0 || (int) ($c['category_id'] ?? 0) === $kat) {
            $pasti[] = [$t, $c, 'padanan'];
            continue;
        }

        $ragu[] = [$t, $c, sprintf(
            'padanan global menunjuk %s (kategori %d) padahal paketnya kategori %d — tolak, putuskan manual',
            (string) $c['kode'],
            (int) ($c['category_id'] ?? 0),
            $kat
        )];
        continue;
    }

    // 3. Nama ternormalkan cocok persis. Bila lebih dari satu pemeriksaan
    //    bernama sama, kategori paket dipakai untuk memilih — dan bila
    //    masih lebih dari satu, tidak ada yang dipilih.
    if (isset($olehNama[$n])) {
        $kandidat = $olehNama[$n];

        if ($kat !== 0 && count($kandidat) > 1) {
            $sesuai = array_values(array_filter(
                $kandidat,
                static fn($c) => (int) ($c['category_id'] ?? 0) === $kat
            ));
            if (count($sesuai) === 1) {
                $pasti[] = [$t, $sesuai[0], 'nama sama + kategori paket'];
                continue;
            }
            $kandidat = $sesuai !== [] ? $sesuai : $kandidat;
        }

        if (count($kandidat) === 1) {
            $c = $kandidat[0];
            if ($kat === 0 || (int) ($c['category_id'] ?? 0) === $kat) {
                $pasti[] = [$t, $c, 'nama sama'];
                continue;
            }
            $ragu[] = [$t, $c, sprintf(
                'nama sama tetapi kategorinya beda (%d vs paket %d)',
                (int) ($c['category_id'] ?? 0),
                $kat
            )];
            continue;
        }

        $ragu[] = [$t, $kandidat[0], 'AMBIGU — ' . count($kandidat) . ' pemeriksaan bernama sama: '
            . implode(', ', array_map(static fn($c) => (string) $c['kode'], $kandidat))];
        continue;
    }

    // 4. kode LIS ditulis apa adanya sebagai nama template
    if (isset($olehKode[strtoupper($nama)])) {
        $c = $olehKode[strtoupper($nama)];
        if ($kat === 0 || (int) ($c['category_id'] ?? 0) === $kat) {
            $pasti[] = [$t, $c, 'kode sama'];
            continue;
        }
        $ragu[] = [$t, $c, sprintf(
            'kode sama tetapi kategorinya beda (%d vs paket %d)',
            (int) ($c['category_id'] ?? 0),
            $kat
        )];
        continue;
    }

    // Tidak pasti — cari calon terdekat, tetapi jangan dipakai sendiri.
    //
    // Calon pun disaring kategori bila paketnya dikenali: menawarkan
    // "Leukosit" darah sebagai calon untuk baris urine hanya mengundang
    // petugas menyetujui pemetaan yang salah.
    $dicari = $kat === 0
        ? $tests
        : array_values(array_filter($tests, static fn($c) => (int) ($c['category_id'] ?? 0) === $kat));
    if ($dicari === []) {
        $dicari = $tests;
    }

    $terbaik = null;
    $skor    = 0;
    foreach ($dicari as $c) {
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
