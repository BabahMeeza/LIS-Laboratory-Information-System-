<?php
declare(strict_types=1);

/**
 * Uji regresi pembangkit barcode Code 128.
 *
 * MENGAPA BERKAS INI ADA
 * ----------------------
 * Tabel pola Code 128 di App\Core\Barcode pernah rusak: pola STOP tidak
 * sengaja tersisip pada indeks 78, sehingga seluruh nilai 79-102 bergeser
 * satu posisi. Di antara yang bergeser ada nilai 99 (CODE C) dan 100
 * (CODE B) - dua simbol yang dipakai berpindah subset.
 *
 * Akibatnya barcode yang mencampur huruf dengan deret angka panjang -
 * yaitu setiap barcode bernomor Khanza seperti PK202609040001 - menghasilkan
 * check digit yang salah dan tidak terbaca scanner mana pun. Barcode berisi
 * angka saja tidak pernah berpindah subset, jadi kerusakan ini tak terlihat
 * selama nomor spesimen masih murni angka. Ia baru muncul berbulan-bulan
 * kemudian, dan gejalanya - "barcode tidak bisa discan" - tidak menunjuk
 * ke mana pun.
 *
 * Pemeriksaan struktur saja TIDAK menangkapnya: pola yang bergeser tetap
 * 11 modul, tetap 3 bar 3 spasi, tetap unik. Karena itu berkas ini menyimpan
 * salinan tabel rujukan yang berdiri sendiri, lalu membandingkannya nilai
 * per nilai, dan membaca ulang setiap barcode yang dihasilkan.
 *
 *   php bin/uji-barcode.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Barcode;

/**
 * Tabel Code 128 nilai 0-102, disalin dari sumber di luar LIS.
 * Sengaja ditulis ulang di sini, bukan diambil dari Barcode::PATTERNS,
 * supaya pergeseran pada salah satunya langsung terlihat.
 */
const RUJUKAN = [
        '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
        '10001001100', '10011001000', '10011000100', '10001100100', '11001001000',
        '11001000100', '11000100100', '10110011100', '10011011100', '10011001110',
        '10111001100', '10011101100', '10011100110', '11001110010', '11001011100',
        '11001001110', '11011100100', '11001110100', '11101101110', '11101001100',
        '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
        '11011011000', '11011000110', '11000110110', '10100011000', '10001011000',
        '10001000110', '10110001000', '10001101000', '10001100010', '11010001000',
        '11000101000', '11000100010', '10110111000', '10110001110', '10001101110',
        '10111011000', '10111000110', '10001110110', '11101110110', '11010001110',
        '11000101110', '11011101000', '11011100010', '11011101110', '11101011000',
        '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
        '11101111010', '11001000010', '11110001010', '10100110000', '10100001100',
        '10010110000', '10010000110', '10000101100', '10000100110', '10110010000',
        '10110000100', '10011010000', '10011000010', '10000110100', '10000110010',
        '11000010010', '11001010000', '11110111010', '11000010100', '10001111010',
        '10100111100', '10010111100', '10010011110', '10111100100', '10011110100',
        '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
        '11011110110', '11110110110', '10101111000', '10100011110', '10001011110',
        '10111101000', '10111100010', '11110101000', '11110100010', '10111011110',
        '10111101110', '11101011110', '11110101110',
];

const RUJUKAN_START_A = '11010000100';
const RUJUKAN_START_B = '11010010000';
const RUJUKAN_START_C = '11010011100';
const RUJUKAN_STOP    = '1100011101011';

$gagal = 0;
$lapor = static function (string $nama, bool $ok, string $ket = '') use (&$gagal): void {
    if (!$ok) {
        $gagal++;
    }
    printf("  %-52s %s%s\n", $nama, $ok ? 'LULUS' : 'GAGAL', $ket === '' ? '' : '  — ' . $ket);
};

echo "UJI BARCODE CODE 128\n" . str_repeat('=', 69) . "\n";

// ---------------------------------------------------------------------
// 1. Tabel pola, nilai per nilai
// ---------------------------------------------------------------------
echo "\n1. Tabel pola dibanding rujukan\n";

$r      = new ReflectionClass(Barcode::class);
$pola   = $r->getConstant('PATTERNS');
$beda   = [];

$lapor('jumlah pola 103', is_array($pola) && count($pola) === 103, is_array($pola) ? (string) count($pola) : 'bukan array');

if (is_array($pola)) {
    foreach (RUJUKAN as $i => $harus) {
        if (($pola[$i] ?? null) !== $harus) {
            $beda[] = sprintf('#%d: %s seharusnya %s', $i, (string) ($pola[$i] ?? 'kosong'), $harus);
        }
    }
}
$lapor('semua 103 pola cocok', $beda === [], $beda === [] ? '' : count($beda) . ' berbeda');
foreach (array_slice($beda, 0, 10) as $b) {
    echo "      " . $b . "\n";
}

$lapor('START B benar', $r->getConstant('START_B') === RUJUKAN_START_B);
$lapor('START C benar', $r->getConstant('START_C') === RUJUKAN_START_C);
$lapor('STOP benar',    $r->getConstant('STOP')    === RUJUKAN_STOP);

// Pola STOP tidak boleh muncul di dalam tabel data — persis kesalahan
// yang pernah terjadi.
$stopPendek = substr(RUJUKAN_STOP, 0, 11);
$lapor('pola STOP tidak tersisip di tabel data', !in_array($stopPendek, is_array($pola) ? $pola : [], true));

// ---------------------------------------------------------------------
// 2. Baca ulang setiap barcode yang dihasilkan
// ---------------------------------------------------------------------
echo "\n2. Encode lalu decode kembali\n";

/** Dekoder mandiri yang hanya memakai RUJUKAN. */
$dekode = static function (string $bits): ?string {
    if (!str_ends_with($bits, RUJUKAN_STOP)) {
        return null;
    }
    $isi = substr($bits, 0, -strlen(RUJUKAN_STOP));
    if ($isi === '' || strlen($isi) % 11 !== 0) {
        return null;
    }

    $nilai = [];
    foreach (str_split($isi, 11) as $simbol) {
        if ($simbol === RUJUKAN_START_A) { $nilai[] = 103; continue; }
        if ($simbol === RUJUKAN_START_B) { $nilai[] = 104; continue; }
        if ($simbol === RUJUKAN_START_C) { $nilai[] = 105; continue; }

        $k = array_search($simbol, RUJUKAN, true);
        if ($k === false) {
            return null;   // simbol yang tidak dikenal scanner mana pun
        }
        $nilai[] = $k;
    }

    if (count($nilai) < 3) {
        return null;
    }
    $start = array_shift($nilai);
    $check = array_pop($nilai);

    $jumlah = $start;
    foreach ($nilai as $i => $v) {
        $jumlah += $v * ($i + 1);
    }
    if ($jumlah % 103 !== $check) {
        return null;   // inilah yang membuat scanner diam
    }

    $mode = match ($start) { 103 => 'A', 104 => 'B', 105 => 'C', default => null };
    if ($mode === null) {
        return null;
    }

    $teks = '';
    foreach ($nilai as $v) {
        if ($v === 99 && $mode !== 'C') { $mode = 'C'; continue; }
        if ($v === 100 && $mode !== 'B') { $mode = 'B'; continue; }
        if ($v === 101 && $mode !== 'A') { $mode = 'A'; continue; }

        $teks .= $mode === 'C' ? str_pad((string) $v, 2, '0', STR_PAD_LEFT) : chr($v + 32);
    }

    return $teks;
};

$contoh = [
    'PK202609040001',        // nomor Khanza — huruf lalu deret angka genap
    'PK202609040001A',       // spesimen kedua
    'PK202609040001B',       // spesimen ketiga
    'PK2026090400012',       // deret angka ganjil
    'PK202604020000001',
    '2609040001',            // format lama, angka saja
    '260904000112345678',
    'ABC-123',
    'A1',
    'X',
    'lis_kecil-123',
    'PK20260904000123456789',
];

foreach ($contoh as $s) {
    $terbaca = $dekode(Barcode::encode($s));
    $lapor("terbaca kembali: {$s}", $terbaca === $s, $terbaca === null ? 'tidak terbaca' : ($terbaca === $s ? '' : 'terbaca "' . $terbaca . '"'));
}

// ---------------------------------------------------------------------
// 3. Seluruh nilai 0-102 dapat dicetak dan dibaca
// ---------------------------------------------------------------------
echo "\n3. Semua karakter subset B\n";

$semua = '';
for ($v = 0; $v <= 94; $v++) {
    $semua .= chr($v + 32);
}
$terbaca = $dekode(Barcode::encode($semua));
$lapor('95 karakter ASCII 32-126 utuh', $terbaca === $semua, $terbaca === null ? 'tidak terbaca' : '');

echo "\n" . str_repeat('=', 69) . "\n";
echo $gagal === 0 ? "SEMUA LULUS\n" : "{$gagal} GAGAL\n";
exit($gagal === 0 ? 0 : 1);
