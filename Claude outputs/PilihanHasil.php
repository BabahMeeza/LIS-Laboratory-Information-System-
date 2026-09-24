<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Penyelaras nilai alat dengan daftar pilihan pemeriksaan.
 *
 * MENGAPA INI ADA
 *
 * Pemeriksaan bertipe "pilihan" (carik celup urine, dsb.) disimpan sebagai
 * teks yang harus SAMA PERSIS dengan salah satu pilihan di master. Alat
 * menulis nilai yang sama dengan ejaan lain:
 *
 *     alat      master
 *     -         Negatif   (Urobilinogen: Normal)
 *     +-        Trace
 *     +1        1+
 *     ++        2+
 *
 * Tanpa penyelarasan, dropdown entri hasil tampil "— pilih —" meskipun
 * hasilnya sudah masuk, flag teks tidak dapat dinilai, dan — lebih buruk —
 * "+1" lolos is_numeric() sehingga tersimpan sebagai angka 1.
 *
 * Bila nilai tidak dapat dicocokkan dengan pasti, nilai ASLI dikembalikan
 * apa adanya. Menebak pilihan untuk hasil pasien lebih berbahaya daripada
 * membiarkan petugas memilih sendiri.
 */
final class PilihanHasil
{
    /**
     * @param list<string> $opsi daftar pilihan dari master
     */
    public static function cocokkan(string $nilai, array $opsi): string
    {
        $asli = trim($nilai);
        if ($asli === '' || $opsi === []) {
            return $asli;
        }

        // Peta huruf kecil -> ejaan master.
        $peta = [];
        foreach ($opsi as $o) {
            $o = trim((string) $o);
            if ($o !== '') {
                $peta[mb_strtolower($o)] = $o;
            }
        }

        $t = mb_strtolower(preg_replace('/\s+/u', '', $asli) ?? $asli);

        // 1. Sudah sama (abaikan besar-kecil huruf dan spasi).
        foreach ($peta as $kecil => $o) {
            if (preg_replace('/\s+/u', '', $kecil) === $t) {
                return $o;
            }
        }

        // 1b. Istilah bahasa Inggris dari alat (warna & kejernihan urine).
        //     MediGo mengirim "Light yellow", "Clear"; master memakai
        //     "Kuning muda", "Jernih". Hanya dipakai bila padanannya memang
        //     ada di daftar pilihan pemeriksaan itu.
        $padanan = [
            'lightyellow' => 'kuning muda', 'paleyellow' => 'kuning muda', 'strawyellow' => 'kuning muda',
            'yellow'      => 'kuning',      'darkyellow' => 'kuning tua',  'amber'       => 'kuning tua',
            'orange'      => 'kuning tua',  'red'        => 'merah',       'brown'       => 'coklat',
            'colorless'   => 'jernih',      'colourless' => 'jernih',
            'clear'       => 'jernih',      'slightlycloudy' => 'agak keruh', 'slightlyturbid' => 'agak keruh',
            'slightturbid' => 'agak keruh', 'cloudy'     => 'keruh',       'turbid'      => 'keruh',
        ];
        if (isset($padanan[$t], $peta[$padanan[$t]])) {
            return $peta[$padanan[$t]];
        }

        // 2. Negatif. Urobilinogen memakai "Normal" sebagai nilai dasarnya.
        if (in_array($t, ['-', '(-)', 'neg', 'negative', 'negatif', 'nil'], true)) {
            return $peta['negatif'] ?? $peta['normal'] ?? $peta['negative'] ?? $asli;
        }

        // 3. Trace / samar.
        if (in_array($t, ['+-', '-+', '±', '+/-', '(+-)', '(±)', 'tr', 'trc', 'trace', 'samar'], true)) {
            return $peta['trace'] ?? $peta['samar'] ?? $asli;
        }

        // 4. Tingkat positif: +1, 1+, (+1), ++, +++ ...
        $tingkat = null;
        //    Angka polos "1" juga diterima: hasil lama yang masuk sebelum
        //    penyelaras ini ada tersimpan begitu karena "+1" dibaca sebagai
        //    angka. Hanya dipakai bila master memang punya pilihan "1+".
        if (preg_match('/^\(?\+?([1-4])\+?\)?$/', $t, $m) === 1) {
            $tingkat = (int) $m[1];
        } elseif (preg_match('/^\(?(\+{1,4})\)?$/', $t, $m) === 1) {
            $tingkat = strlen($m[1]);
        }

        if ($tingkat !== null) {
            if (isset($peta[$tingkat . '+'])) {
                return $peta[$tingkat . '+'];
            }
            // Angka polos tanpa tanda "+" tidak cukup yakin untuk dijadikan
            // "Positif" — bisa saja memang angka. Biarkan petugas memilih.
            if (ctype_digit($t)) {
                return $asli;
            }
            // Pemeriksaan dua pilihan (mis. Nitrit: Negatif/Positif).
            return $peta['positif'] ?? $peta['positive'] ?? $asli;
        }

        // 5. Positif tanpa tingkat.
        if (in_array($t, ['pos', 'positive', 'positif', '(+)'], true)) {
            return $peta['positif'] ?? $peta['positive'] ?? $asli;
        }

        return $asli;
    }

    /**
     * Pecah kolom tests.pilihan ("Negatif,Trace,1+") menjadi daftar.
     *
     * @return list<string>
     */
    public static function daftar(?string $pilihan): array
    {
        if ($pilihan === null || trim($pilihan) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $pilihan)),
            static fn (string $x): bool => $x !== ''
        ));
    }
}
