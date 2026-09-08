<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembangkit barcode Code 128 dalam bentuk SVG murni.
 *
 * Tidak memerlukan ekstensi GD maupun pustaka pihak ketiga sehingga
 * label spesimen dapat dicetak dari XAMPP standar. Code 128 dipilih
 * karena diterima hampir semua analyzer dan scanner laboratorium.
 *
 * Implementasi memakai subset otomatis:
 *   - Subset C untuk deretan angka genap (padat, 2 digit per simbol)
 *   - Subset B untuk sisanya (ASCII 32–126)
 */
final class Barcode
{
    /** Pola 11 modul untuk nilai 0–102 (1 = bar, 0 = spasi). */
    /**
     * Pola 11 modul untuk nilai 0-102 (1 = bar, 0 = spasi).
     *
     * Tabel ini pernah salah: pola STOP (11000111010) tidak sengaja
     * tersisip pada indeks 78, sehingga seluruh nilai 79-102 bergeser
     * satu posisi. Akibatnya nilai 99 (CODE C) dan 100 (CODE B) - dua
     * simbol perpindahan subset - dicetak dengan pola milik nilai lain,
     * dan setiap barcode yang mencampur huruf dengan deret angka panjang
     * menjadi tidak terbaca scanner. Barcode berisi angka saja tidak
     * pernah berpindah subset, jadi kerusakan ini tidak terlihat selama
     * nomor spesimen masih murni angka.
     *
     * Nilai di bawah sudah dicocokkan satu per satu dengan tabel Code 128
     * pada implementasi lain; bin/uji-barcode.php mengulang pembandingan
     * itu terhadap seluruh 103 nilai.
     */
    private const PATTERNS = [
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

    private const START_B = '11010010000';
    private const START_C = '11010011100';
    private const STOP     = '1100011101011';

    private const VAL_START_B = 104;
    private const VAL_START_C = 105;
    private const VAL_CODE_B  = 100;
    private const VAL_CODE_C  = 99;

    /**
     * Hasilkan SVG barcode.
     *
     * @param string $data       Isi barcode (mis. nomor spesimen)
     * @param int    $tinggi     Tinggi bar dalam piksel
     * @param float  $lebarModul Lebar satu modul dalam piksel
     * @param bool   $tampilTeks Cetak teks di bawah barcode
     */
    public static function svg(
        string $data,
        int $tinggi = 50,
        float $lebarModul = 1.6,
        bool $tampilTeks = true
    ): string {
        $bits = self::encode($data);
        if ($bits === '') {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
        }

        $quiet       = 10;                       // zona tenang 10 modul di kiri & kanan
        $totalModul  = strlen($bits) + $quiet * 2;
        $lebarTotal  = $totalModul * $lebarModul;
        $tinggiTeks  = $tampilTeks ? 14 : 0;
        $tinggiTotal = $tinggi + $tinggiTeks + 2;

        $rects = '';
        $x     = $quiet * $lebarModul;
        $i     = 0;
        $n     = strlen($bits);

        while ($i < $n) {
            if ($bits[$i] === '1') {
                $lebar = 0;
                while ($i < $n && $bits[$i] === '1') {
                    $lebar++;
                    $i++;
                }
                $rects .= sprintf(
                    '<rect x="%.3f" y="0" width="%.3f" height="%d" fill="#000"/>',
                    $x,
                    $lebar * $lebarModul,
                    $tinggi
                );
                $x += $lebar * $lebarModul;
            } else {
                $lebar = 0;
                while ($i < $n && $bits[$i] === '0') {
                    $lebar++;
                    $i++;
                }
                $x += $lebar * $lebarModul;
            }
        }

        $teks = '';
        if ($tampilTeks) {
            $teks = sprintf(
                '<text x="%.3f" y="%d" text-anchor="middle" font-family="monospace" font-size="11" fill="#000">%s</text>',
                $lebarTotal / 2,
                $tinggi + 12,
                htmlspecialchars($data, ENT_QUOTES)
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%.0f" height="%d" viewBox="0 0 %.3f %d" shape-rendering="crispEdges">'
            . '<rect width="100%%" height="100%%" fill="#fff"/>%s%s</svg>',
            $lebarTotal,
            $tinggiTotal,
            $lebarTotal,
            $tinggiTotal,
            $rects,
            $teks
        );
    }

    /** SVG dalam bentuk data URI, siap dipakai pada atribut src. */
    public static function dataUri(string $data, int $tinggi = 50, float $lebarModul = 1.6, bool $tampilTeks = true): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $tinggi, $lebarModul, $tampilTeks));
    }

    /**
     * Ubah data menjadi deretan modul biner Code 128 lengkap
     * (start + data + check digit + stop).
     */
    public static function encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        // Code 128 subset B hanya mendukung ASCII 32–126.
        $data = preg_replace('/[^\x20-\x7E]/', '', $data) ?? '';
        if ($data === '') {
            return '';
        }

        $codes  = [];
        $pos    = 0;
        $len    = strlen($data);
        $subset = self::digitRun($data, 0) >= 4 ? 'C' : 'B';

        $codes[] = $subset === 'C' ? self::VAL_START_C : self::VAL_START_B;

        while ($pos < $len) {
            if ($subset === 'C') {
                $run = self::digitRun($data, $pos);
                if ($run >= 2) {
                    $pasangan = intdiv($run, 2);
                    for ($i = 0; $i < $pasangan; $i++) {
                        $codes[] = (int) substr($data, $pos, 2);
                        $pos    += 2;
                    }
                    continue;
                }
                $codes[] = self::VAL_CODE_B;
                $subset  = 'B';
                continue;
            }

            // Subset B — beralih ke C bila menemui deret angka panjang.
            $run = self::digitRun($data, $pos);
            if ($run >= 6 && $run % 2 === 0) {
                $codes[] = self::VAL_CODE_C;
                $subset  = 'C';
                continue;
            }

            $codes[] = ord($data[$pos]) - 32;
            $pos++;
        }

        // Check digit: (start + Σ posisi × nilai) mod 103
        $checksum = $codes[0];
        for ($i = 1, $c = count($codes); $i < $c; $i++) {
            $checksum += $codes[$i] * $i;
        }
        $codes[] = $checksum % 103;

        $bits = '';
        foreach ($codes as $index => $code) {
            if ($index === 0) {
                $bits .= $code === self::VAL_START_C ? self::START_C : self::START_B;
                continue;
            }
            $bits .= self::PATTERNS[$code] ?? self::PATTERNS[0];
        }

        return $bits . self::STOP;
    }

    /** Panjang deret digit berturut-turut mulai dari posisi tertentu. */
    private static function digitRun(string $data, int $pos): int
    {
        $n = 0;
        $c = strlen($data);
        while ($pos + $n < $c && ctype_digit($data[$pos + $n])) {
            $n++;
        }

        return $n;
    }

    /**
     * Pemeriksaan integritas tabel pola — dipakai oleh bin/selftest.php.
     * Setiap simbol Code 128 terdiri atas 3 bar dan 3 spasi, total 11 modul,
     * dengan jumlah modul bar selalu genap.
     *
     * @return array<int,string> Daftar masalah yang ditemukan (kosong = sehat)
     */
    public static function periksaTabel(): array
    {
        $masalah = [];

        foreach (self::PATTERNS as $index => $pattern) {
            if (strlen($pattern) !== 11) {
                $masalah[] = "Pola #$index panjangnya " . strlen($pattern) . ', seharusnya 11.';
                continue;
            }
            if ($pattern[0] !== '1') {
                $masalah[] = "Pola #$index tidak diawali bar.";
            }
            if (substr_count($pattern, '1') % 2 !== 0) {
                $masalah[] = "Pola #$index memiliki jumlah modul bar ganjil.";
            }

            // Hitung jumlah elemen (pergantian bar/spasi) — harus tepat 6.
            $elemen = 1;
            for ($i = 1; $i < 11; $i++) {
                if ($pattern[$i] !== $pattern[$i - 1]) {
                    $elemen++;
                }
            }
            // Pola diakhiri spasi implisit, sehingga 5 atau 6 pergantian sah.
            if ($elemen < 5 || $elemen > 6) {
                $masalah[] = "Pola #$index memiliki $elemen elemen, seharusnya 6.";
            }
        }

        if (count(self::PATTERNS) !== 103) {
            $masalah[] = 'Tabel pola berisi ' . count(self::PATTERNS) . ' entri, seharusnya 103.';
        }

        return $masalah;
    }
}
