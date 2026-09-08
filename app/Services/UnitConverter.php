<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Penyelaras satuan antara analyzer dan master pemeriksaan LIS.
 *
 * MENGAPA INI ADA
 *
 * Analyzer sering mengirim nilai dalam satuan SI, sedangkan master
 * pemeriksaan laboratorium di Indonesia umumnya memakai satuan
 * konvensional. Mindray BC-5000, misalnya, mengirim hemoglobin dalam g/L:
 *
 *     Hb 64 g/L  =  6,4 g/dL   → anemia berat, mengancam jiwa
 *
 * Bila angka 64 itu dibandingkan langsung dengan rujukan 13,2–17,3 g/dL,
 * hasilnya ditandai "kritis TINGGI" — persis kebalikan dari keadaan pasien.
 * Klinisi yang membaca tanda itu tidak akan berpikir transfusi.
 *
 * Kelas ini menutup celah tersebut dengan dua aturan:
 *
 *   1. Satuan yang konversinya pasti dan tidak bergantung analit
 *      (g/L ↔ g/dL, 10⁹/L ↔ 10³/µL) dikonversi otomatis.
 *
 *   2. Satuan yang berbeda namun konversinya TIDAK pasti — mis. mmol/L ke
 *      mg/dL yang memerlukan berat molekul tiap analit — tidak ditebak.
 *      Nilainya tetap disimpan, tetapi penilaian flag dibatalkan dan
 *      hasilnya ditandai perlu ditinjau manusia.
 *
 * Menahan penilaian jauh lebih aman daripada menghasilkan flag yang salah
 * arah dengan penuh keyakinan.
 */
final class UnitConverter
{
    /**
     * Faktor konversi untuk pasangan satuan yang tidak bergantung analit.
     * Kunci: "dari|ke" dalam bentuk yang sudah dinormalkan.
     *
     * @var array<string,float>
     */
    private const FAKTOR = [
        // Konsentrasi massa
        'g/l|g/dl'      => 0.1,
        'g/dl|g/l'      => 10.0,
        'mg/l|mg/dl'    => 0.1,
        'mg/dl|mg/l'    => 10.0,
        'ug/l|ug/dl'    => 0.1,
        'ug/dl|ug/l'    => 10.0,
        'g/l|mg/dl'     => 100.0,
        'mg/dl|g/l'     => 0.01,

        // Hitung sel — hanya beda notasi, nilainya identik
        '10*9/l|10*3/ul'   => 1.0,
        '10*3/ul|10*9/l'   => 1.0,
        '10*12/l|10*6/ul'  => 1.0,
        '10*6/ul|10*12/l'  => 1.0,
        '10*9/l|10*9/l'    => 1.0,

        // Hitung sel absolut
        '/ul|10*3/ul'   => 0.001,
        '10*3/ul|/ul'   => 1000.0,

        // Volume
        'fl|um3'        => 1.0,
        'um3|fl'        => 1.0,

        // Waktu
        'detik|s'       => 1.0,
        's|detik'       => 1.0,
    ];

    /**
     * Samakan bentuk penulisan satuan agar dapat dibandingkan.
     *
     * Analyzer menulis satuan yang sama dengan banyak cara: "10^3/uL",
     * "10*3/uL", "10E3/µL", "K/uL". Perbandingan mentah akan menganggap
     * semuanya berbeda dan memicu penahanan flag yang tidak perlu.
     */
    public static function normal(?string $satuan): string
    {
        $s = strtolower(trim((string) $satuan));
        if ($s === '') {
            return '';
        }

        // Buang spasi dan samakan karakter mikro.
        $s = str_replace([' ', 'µ', 'μ'], ['', 'u', 'u'], $s);

        // Samakan notasi pangkat: 10^9, 10E9, 10e9 → 10*9
        $s = preg_replace('/10\s*[\^e]\s*/i', '10*', $s) ?? $s;

        // Singkatan yang lazim dipakai analyzer.
        $alias = [
            'k/ul'   => '10*3/ul',
            'm/ul'   => '10*6/ul',
            'g/dl'   => 'g/dl',
            'gm/dl'  => 'g/dl',
            'gr/dl'  => 'g/dl',
            'gr/l'   => 'g/l',
            'x10e3/ul' => '10*3/ul',
            'x10e9/l'  => '10*9/l',
            'cells/ul' => '/ul',
            'sel/ul'   => '/ul',
        ];

        return $alias[$s] ?? $s;
    }

    /** Apakah kedua satuan pada dasarnya sama? */
    public static function setara(?string $a, ?string $b): bool
    {
        return self::normal($a) === self::normal($b);
    }

    /**
     * Faktor pengali dari satuan alat ke satuan master LIS.
     *
     * @return float|null null bila konversinya tidak pasti — pemanggil
     *                    WAJIB menahan penilaian flag dalam hal ini.
     */
    public static function faktor(?string $dariSatuan, ?string $keSatuan): ?float
    {
        $dari = self::normal($dariSatuan);
        $ke   = self::normal($keSatuan);

        // Salah satu tidak menyebut satuan: tidak ada yang bisa dibandingkan,
        // jadi anggap sudah sepadan dan biarkan penilaian berjalan. Ini
        // perilaku lama yang tetap dipertahankan agar pemeriksaan tanpa
        // satuan (mis. rasio, kualitatif) tidak ikut tertahan.
        if ($dari === '' || $ke === '') {
            return 1.0;
        }

        if ($dari === $ke) {
            return 1.0;
        }

        return self::FAKTOR[$dari . '|' . $ke] ?? null;
    }

    /**
     * Penjelasan singkat untuk log dan layar, mis.
     * "g/L → g/dL (×0.1)" atau "mmol/L → mg/dL (tidak pasti)".
     */
    public static function jelaskan(?string $dari, ?string $ke): string
    {
        $f = self::faktor($dari, $ke);

        return sprintf(
            '%s → %s (%s)',
            (string) $dari !== '' ? (string) $dari : '(kosong)',
            (string) $ke !== '' ? (string) $ke : '(kosong)',
            $f === null ? 'konversi tidak pasti' : '×' . rtrim(rtrim(sprintf('%.4f', $f), '0'), '.')
        );
    }
}
