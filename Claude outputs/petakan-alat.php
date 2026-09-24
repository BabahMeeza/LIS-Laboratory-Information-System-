<?php
declare(strict_types=1);

/**
 * Petakan kode parameter alat ke master pemeriksaan LIS, dari pesan yang
 * benar-benar dikirim alat.
 *
 * MENGAPA PEMETAAN OTOMATIS BERBAHAYA BILA TIDAK DIBATASI
 * ------------------------------------------------------
 * "Leukosit" ada di tiga tempat yang berbeda arti:
 *
 *   WBC          Leukosit                    darah    (hematologi)
 *   U_LEU        Urine - Leukosit Esterase   urine    (carik celup)
 *   U_SED_LEU    Sedimen - Leukosit          urine    (mikroskopik)
 *
 * Pencocokan nama tanpa pembatas akan memasangkan leukosit urine ke
 * pemeriksaan leukosit DARAH. Angkanya tetap masuk akal, nilai rujukannya
 * ikut yang darah, dan tidak ada yang menyadarinya sampai ada klinisi yang
 * mengambil keputusan dari angka itu. Ini kesalahan yang membahayakan
 * pasien, bukan sekadar kesalahan data.
 *
 * Dua pembatas dipakai di sini:
 *
 *   1. KATEGORI ALAT. Calon pemeriksaan dibatasi hanya pada kategori alat
 *      yang bersangkutan. Alat urinalisa tidak akan pernah bisa menunjuk
 *      pemeriksaan hematologi, apa pun kemiripan namanya.
 *
 *   2. DAFTAR PADANAN yang ditulis tangan. Bahkan di dalam satu kategori,
 *      kemiripan nama tidak cukup: "LEU" pada carik celup adalah leukosit
 *      esterase, sedangkan "WBC" pada sedimen adalah hitung leukosit
 *      mikroskopik. Keduanya berbunyi "leukosit" dan keduanya urine.
 *      Hanya daftar padanan yang bisa membedakannya.
 *
 * Selebihnya dilaporkan sebagai calon dan dibiarkan diputuskan manusia.
 *
 * DARI MANA KODENYA DIAMBIL
 * -------------------------
 * Dari instrument_messages — pesan mentah yang sungguh dikirim alat, bukan
 * dari manual dan bukan dari tebakan. Karena itu alat harus sudah pernah
 * mengirim minimal satu sampel sebelum skrip ini berguna.
 *
 * Pada HL7, OBX-3 berbentuk "KODE^NAMA^SISTEM". Yang menjadi identitas
 * adalah KODE, tetapi NAMA-lah yang memungkinkan pencocokan otomatis —
 * dan nama itu tidak disimpan di tabel pemetaan, sehingga harus dipungut
 * kembali dari pesan mentahnya.
 *
 * HANYA CLI. Bawaannya hanya melapor; tidak ada yang ditulis.
 *
 *   php bin/petakan-alat.php --alat=URIN-02
 *   php bin/petakan-alat.php --alat=URIN-02 --terapkan
 *   php bin/petakan-alat.php --alat=URIN-02 --batas=200
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

// ---------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------

function arg(string $nama, string $bawaan = ''): string
{
    foreach ($GLOBALS['argv'] as $a) {
        if (str_starts_with($a, '--' . $nama . '=')) {
            return substr($a, strlen($nama) + 3);
        }
    }

    return $bawaan;
}

$kodeAlat  = arg('alat');
$terapkan  = in_array('--terapkan', $argv, true);
$batas     = (int) arg('batas', '100');

if ($kodeAlat === '') {
    exit("Sebutkan alatnya: php bin/petakan-alat.php --alat=URIN-02\n");
}

// ---------------------------------------------------------------------
// Daftar padanan, per kategori
//
// Kuncinya nama atau kode ternormalkan yang dikirim alat; nilainya kode
// pemeriksaan LIS. Sengaja dipisah per kategori supaya satu singkatan
// boleh berarti lain di kategori lain — "WBC" di urine bukan "WBC" di
// darah, dan daftar global akan menyatukan keduanya.
// ---------------------------------------------------------------------

const PADANAN = [
    // --- 3 = Urinalisa -----------------------------------------------
    3 => [
        // Sedimen (mikroskopik)
        'wbc'            => 'U_SED_LEU',
        'leukosit'       => 'U_SED_LEU',
        'leu#'           => 'U_SED_LEU',
        'rbc'            => 'U_SED_ERI',
        'eritrosit'      => 'U_SED_ERI',
        'bact'           => 'U_SED_BAKT',
        'bacteria'       => 'U_SED_BAKT',
        'bakteri'        => 'U_SED_BAKT',
        'epi'            => 'U_SED_EPI',
        'epithelial'     => 'U_SED_EPI',
        'epithelialcell' => 'U_SED_EPI',
        'epitel'         => 'U_SED_EPI',
        'cast'           => 'U_SED_SIL',
        'silinder'       => 'U_SED_SIL',
        'cry'            => 'U_SED_KRIS',
        'crystal'        => 'U_SED_KRIS',
        'kristal'        => 'U_SED_KRIS',

        // Carik celup (kimia kering)
        //
        // PERHATIAN: 'leu' TANPA tanda pagar adalah leukosit ESTERASE,
        // bukan hitung sedimen. Dua-duanya berbunyi "leukosit" dan
        // dua-duanya urine — hanya daftar ini yang membedakannya.
        'leu'            => 'U_LEU',
        'leukocyte'      => 'U_LEU',
        'leukocyteester' => 'U_LEU',
        'nit'            => 'U_NIT',
        'nitrite'        => 'U_NIT',
        'nitrit'         => 'U_NIT',
        'uro'            => 'U_URO',
        'urobilinogen'   => 'U_URO',
        'pro'            => 'U_PROT',
        'prot'           => 'U_PROT',
        'protein'        => 'U_PROT',
        'ph'             => 'U_PH',
        'bld'            => 'U_BLD',
        'blood'          => 'U_BLD',
        'occultblood'    => 'U_BLD',
        'darahsamar'     => 'U_BLD',
        'sg'             => 'U_BJ',
        'spgr'           => 'U_BJ',
        'specificgravit' => 'U_BJ',
        'beratjenis'     => 'U_BJ',
        'ket'            => 'U_KET',
        'ketone'         => 'U_KET',
        'keton'          => 'U_KET',
        'bil'            => 'U_BIL',
        'bilirubin'      => 'U_BIL',
        'glu'            => 'U_GLU',
        'glucose'        => 'U_GLU',
        'glukosa'        => 'U_GLU',
        'vc'             => null,          // Vitamin C — pengganggu, bukan hasil
        'ascorbicacid'   => null,

        // Fisik
        'color'          => 'U_WARNA',
        'colour'         => 'U_WARNA',
        'warna'          => 'U_WARNA',
        'clarity'        => 'U_KEJERNIHAN',
        'turbidity'      => 'U_KEJERNIHAN',
        'kejernihan'     => 'U_KEJERNIHAN',
    ],
];

/** Normalkan nama supaya ejaan dan tanda baca tidak menghalangi pencocokan. */
function normal(string $s): string
{
    $s = strtolower(trim($s));
    $s = str_replace(['%', '(', ')', '.', ',', '-', '_', '/', '\\', "'", '*'], ' ', $s);

    return preg_replace('/\s+/', '', $s) ?? '';
}

/**
 * Pungut pasangan kode -> nama dari satu pesan mentah.
 *
 * @return array<string,array{nama:string,satuan:string}>
 */
function kodeDariPesan(string $raw, string $protokol): array
{
    $hasil = [];
    $baris = preg_split('/[\r\n]+/', $raw) ?: [];

    foreach ($baris as $b) {
        $b = trim($b);
        if ($b === '') {
            continue;
        }

        if ($protokol === 'hl7' && str_starts_with($b, 'OBX')) {
            // OBX|1|NM|KODE^NAMA^SISTEM||NILAI|SATUAN|...
            $f = explode('|', $b);
            $id = $f[3] ?? '';
            if ($id === '') {
                continue;
            }
            $k = explode('^', $id);
            $kode = trim($k[0]);
            if ($kode === '') {
                continue;
            }
            $hasil[$kode] = [
                'nama'   => trim($k[1] ?? ''),
                'satuan' => trim($f[6] ?? ''),
                // OBX-2 = tipe nilai. "ED" adalah data terlampir — pada alat
                // urinalisa isinya gambar sedimen ber-base64. Tanpa ditandai,
                // "Sediment Image" akan ditawarkan sebagai calon "Sedimen -
                // Epitel" karena namanya memang mirip 69%.
                'tipe'   => strtoupper(trim($f[2] ?? '')),
            ];
            continue;
        }

        // ASTM: baris R diawali nomor frame, mis. "2R|1|^^^WBC|5.2|10*9/L"
        //
        // Nomor frame itu pernah membuat pencocokan gagal diam-diam karena
        // polanya ditulis '^R\|' — karena itu nomor di depan ikut diterima.
        if ($protokol === 'astm' && preg_match('/^\d?R\|/', $b) === 1) {
            $f = explode('|', $b);
            $id = $f[2] ?? '';
            $k  = array_values(array_filter(explode('^', $id), static fn($x) => trim($x) !== ''));
            if ($k === []) {
                continue;
            }
            $kode = trim($k[0]);
            $hasil[$kode] = [
                // ASTM tidak membawa nama parameter, hanya kodenya. Pemetaan
                // otomatis karena itu jauh lebih terbatas untuk alat ASTM,
                // dan itu memang sifat protokolnya — bukan kekurangan skrip.
                'nama'   => trim($k[1] ?? ''),
                'satuan' => trim($f[4] ?? ''),
                'tipe'   => '',
            ];
        }
    }

    return $hasil;
}

// ---------------------------------------------------------------------
// Alat
// ---------------------------------------------------------------------

echo "=====================================================================\n";
echo " PEMETAAN PARAMETER ALAT -> MASTER LIS   " . date('Y-m-d H:i:s T') . "\n";
echo " alat : {$kodeAlat}\n";
echo " mode : " . ($terapkan ? 'TERAPKAN' : 'LAPORAN SAJA') . "\n";
echo "=====================================================================\n";

$alat = Database::selectOne(
    'SELECT id, kode, nama, protokol, category_id FROM instruments WHERE kode = ? LIMIT 1',
    [$kodeAlat]
);

if ($alat === null) {
    exit("\nAlat \"{$kodeAlat}\" tidak terdaftar. Periksa menu Alat Laboratorium.\n");
}

$idAlat   = (int) $alat['id'];
$protokol = (string) $alat['protokol'];
$kategori = $alat['category_id'] === null ? 0 : (int) $alat['category_id'];

if ($kategori === 0) {
    echo "\nBERHENTI: alat ini belum punya kategori.\n";
    echo "Kategori adalah satu-satunya yang mencegah leukosit urine dipetakan\n";
    echo "ke leukosit darah. Tanpa itu pemetaan otomatis tidak dijalankan.\n";
    echo "Isi kolom Kategori pada menu Alat Laboratorium lebih dulu.\n";
    exit(1);
}

$namaKategori = Database::selectOne('SELECT nama FROM test_categories WHERE id = ?', [$kategori]);
echo "\nKategori alat : " . ($namaKategori['nama'] ?? $kategori) . "\n";
echo "Protokol      : {$protokol}\n";

// ---------------------------------------------------------------------
// Calon pemeriksaan — HANYA dari kategori alat ini
// ---------------------------------------------------------------------

$calonTest = Database::select(
    'SELECT id, kode, nama, nama_singkat FROM tests WHERE category_id = ? AND aktif = 1 ORDER BY kode',
    [$kategori]
);

if ($calonTest === []) {
    exit("\nTidak ada pemeriksaan aktif pada kategori ini. Periksa Master Pemeriksaan.\n");
}

$olehKode = [];
$olehNama = [];
foreach ($calonTest as $t) {
    $olehKode[strtoupper((string) $t['kode'])] = $t;
    $olehNama[normal((string) $t['nama'])] = $t;
    if (($t['nama_singkat'] ?? '') !== '') {
        $olehNama[normal((string) $t['nama_singkat'])] ??= $t;
    }
}

echo "Calon pemeriksaan: " . count($calonTest) . " (dibatasi pada kategori ini saja)\n";

// ---------------------------------------------------------------------
// Kode yang benar-benar dikirim alat
// ---------------------------------------------------------------------

$pesan = Database::select(
    'SELECT raw FROM instrument_messages
      WHERE instrument_id = ? AND arah = ? AND raw IS NOT NULL
      ORDER BY id DESC LIMIT ' . max(1, $batas),
    [$idAlat, 'in']
);

$diamati = [];
foreach ($pesan as $p) {
    foreach (kodeDariPesan((string) $p['raw'], $protokol) as $kode => $info) {
        if (!isset($diamati[$kode]) || ($diamati[$kode]['nama'] === '' && $info['nama'] !== '')) {
            $diamati[$kode] = $info;
        }
    }
}

echo "Pesan diperiksa  : " . count($pesan) . "\n";
echo "Kode ditemukan   : " . count($diamati) . "\n";

if ($diamati === []) {
    echo "\nBelum ada kode parameter yang bisa dibaca dari pesan alat ini.\n";
    echo "Jalankan satu sampel sungguhan lebih dulu, dan pastikan simpan_raw = 1\n";
    echo "pada menu Alat Laboratorium.\n";
    exit(0);
}

// Pemetaan yang sudah ada — tidak boleh ditimpa.
$sudah = [];
foreach (Database::select(
    'SELECT kode_alat, test_id, abaikan FROM instrument_test_map WHERE instrument_id = ?',
    [$idAlat]
) as $m) {
    $sudah[(string) $m['kode_alat']] = $m;
}

// ---------------------------------------------------------------------
// Cocokkan
// ---------------------------------------------------------------------

$pasti = [];
$calon = [];
$gelap = [];
$lewat = [];

foreach ($diamati as $kode => $info) {
    // PHP mengubah kunci array yang berupa angka menjadi int — "900" jadi
    // 900, sementara "001" tetap string. Kode parameter harus selalu
    // diperlakukan sebagai teks, kalau tidak "900" dan "0900" berbeda nasib.
    $kode   = (string) $kode;
    $adaMap = $sudah[$kode] ?? null;

    // Gambar bukan hasil pemeriksaan. hl7.js sudah melewatinya saat
    // memproses; di sini ia ditandai supaya tidak ikut ditawarkan.
    if (($info['tipe'] ?? '') === 'ED') {
        $gelap[] = [$kode, $info, 'gambar terlampir (OBX tipe ED) — tandai abaikan'];
        continue;
    }

    if ($adaMap !== null && ($adaMap['test_id'] !== null || (int) $adaMap['abaikan'] === 1)) {
        $lewat[] = $kode;   // sudah diputuskan manusia atau sudah terpetakan
        continue;
    }

    $kunci = [normal($info['nama']), normal($kode)];
    $ketemu = null;
    $lewatSengaja = false;

    // 1. Daftar padanan kategori ini
    foreach ($kunci as $k) {
        if ($k === '') {
            continue;
        }
        if (array_key_exists($k, PADANAN[$kategori] ?? [])) {
            $tujuan = PADANAN[$kategori][$k];
            if ($tujuan === null) {
                $lewatSengaja = true;
                break;
            }
            if (isset($olehKode[$tujuan])) {
                $ketemu = [$olehKode[$tujuan], 'padanan'];
                break;
            }
        }
    }

    if ($lewatSengaja) {
        $gelap[] = [$kode, $info, 'bukan hasil pemeriksaan (pengganggu) — tandai abaikan'];
        continue;
    }

    // 2. Kode alat sama persis dengan kode LIS, di kategori ini
    if ($ketemu === null && isset($olehKode[strtoupper($kode)])) {
        $ketemu = [$olehKode[strtoupper($kode)], 'kode sama'];
    }

    // 3. Nama ternormalkan cocok persis
    if ($ketemu === null && $info['nama'] !== '' && isset($olehNama[normal($info['nama'])])) {
        $ketemu = [$olehNama[normal($info['nama'])], 'nama sama'];
    }

    if ($ketemu !== null) {
        $pasti[] = [$kode, $info, $ketemu[0], $ketemu[1]];
        continue;
    }

    // 4. Mirip — dilaporkan, tidak pernah diterapkan otomatis
    $terbaik = null;
    $nilai   = 0.0;
    $bahan   = $info['nama'] !== '' ? normal($info['nama']) : normal($kode);

    foreach ($calonTest as $t) {
        similar_text($bahan, normal((string) $t['nama']), $persen);
        if ($persen > $nilai) {
            $nilai   = $persen;
            $terbaik = $t;
        }
    }

    if ($terbaik !== null && $nilai >= 65) {
        $calon[] = [$kode, $info, $terbaik, round($nilai)];
    } else {
        $gelap[] = [$kode, $info, 'tidak ada calon yang meyakinkan'];
    }
}

// ---------------------------------------------------------------------
// Laporan
// ---------------------------------------------------------------------

$baris = static function (string $kode, array $info): string {
    $n = $info['nama'] !== '' ? $info['nama'] : '(tanpa nama)';
    $s = $info['satuan'] !== '' ? ' [' . $info['satuan'] . ']' : '';

    return str_pad($kode, 12) . str_pad($n . $s, 28);
};

echo "\n---------------------------------------------------------------------\n";
echo " COCOK PASTI (" . count($pasti) . ")\n";
echo "---------------------------------------------------------------------\n";
foreach ($pasti as [$kode, $info, $t, $sebab]) {
    echo '  ' . $baris($kode, $info) . '-> ' . str_pad((string) $t['kode'], 14)
        . $t['nama'] . '   (' . $sebab . ")\n";
}
if ($pasti === []) {
    echo "  (tidak ada)\n";
}

echo "\n---------------------------------------------------------------------\n";
echo " PERLU DIPUTUSKAN MANUSIA (" . count($calon) . ")\n";
echo "---------------------------------------------------------------------\n";
foreach ($calon as [$kode, $info, $t, $persen]) {
    echo '  ' . $baris($kode, $info) . '?  ' . str_pad((string) $t['kode'], 14)
        . $t['nama'] . "   (mirip {$persen}%)\n";
}
if ($calon === []) {
    echo "  (tidak ada)\n";
}

echo "\n---------------------------------------------------------------------\n";
echo " TIDAK DIKENALI (" . count($gelap) . ")\n";
echo "---------------------------------------------------------------------\n";
foreach ($gelap as [$kode, $info, $sebab]) {
    echo '  ' . $baris($kode, $info) . $sebab . "\n";
}
if ($gelap === []) {
    echo "  (tidak ada)\n";
}

if ($lewat !== []) {
    echo "\n" . count($lewat) . " kode dilewati karena sudah dipetakan atau sudah ditandai abaikan.\n";
}

// ---------------------------------------------------------------------
// Terapkan
// ---------------------------------------------------------------------

if (!$terapkan) {
    echo "\nTidak ada yang ditulis. Jalankan ulang dengan --terapkan untuk menyimpan\n";
    echo "bagian COCOK PASTI saja. Sisanya tetap diputuskan lewat menu Pemetaan.\n";
    exit(0);
}

$ditulis = 0;
foreach ($pasti as [$kode, $info, $t, $sebab]) {
    Database::execute(
        'INSERT INTO instrument_test_map (instrument_id, kode_alat, test_id, satuan_alat, faktor, offset_nilai, abaikan)
         VALUES (?, ?, ?, ?, 1, 0, 0)
         ON DUPLICATE KEY UPDATE test_id = VALUES(test_id), satuan_alat = VALUES(satuan_alat)',
        [$idAlat, $kode, (int) $t['id'], $info['satuan'] !== '' ? $info['satuan'] : null]
    );
    $ditulis++;
}

echo "\n{$ditulis} pemetaan ditulis.\n";
echo "Periksa hasilnya di Alat Laboratorium -> {$kodeAlat} -> Pemetaan sebelum\n";
echo "mengaktifkan alat. Pemetaan otomatis mempercepat pekerjaan, tetapi yang\n";
echo "bertanggung jawab atas benar-tidaknya tetap manusia.\n";
