<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Helper;

/**
 * Pemilihan nilai rujukan dan penetapan flag hasil.
 *
 * Nilai rujukan dipilih berdasarkan jenis kelamin dan umur pasien
 * (dalam hari) pada saat spesimen diambil. Baris paling spesifik menang:
 * kecocokan jenis kelamin eksplisit (L/P) lebih diutamakan daripada 'A',
 * lalu rentang umur tersempit.
 */
final class ReferenceRangeService
{
    /** @var array<string,array<string,mixed>|null> Cache per proses */
    private static array $cache = [];

    /**
     * @return array<string,mixed>|null
     */
    public static function untuk(int $testId, ?string $jk, ?int $umurHari): ?array
    {
        $jk    = in_array($jk, ['L', 'P'], true) ? $jk : 'A';
        $umur  = $umurHari ?? 10950; // asumsi dewasa (30 th) bila umur tidak diketahui
        $kunci = $testId . '|' . $jk . '|' . $umur;

        if (array_key_exists($kunci, self::$cache)) {
            return self::$cache[$kunci];
        }

        $row = Database::selectOne(
            'SELECT * FROM reference_ranges
             WHERE test_id = ?
               AND (jk = ? OR jk = \'A\')
               AND ? BETWEEN umur_min_hari AND umur_max_hari
             ORDER BY (jk = ?) DESC, (umur_max_hari - umur_min_hari) ASC
             LIMIT 1',
            [$testId, $jk, $umur, $jk]
        );

        return self::$cache[$kunci] = $row;
    }

    /**
     * Tetapkan flag hasil numerik.
     *
     * @return string '' | 'N' | 'L' | 'H' | 'LL' | 'HH'
     */
    public static function flagNumerik(?float $nilai, ?array $rujukan): string
    {
        if ($nilai === null || $rujukan === null) {
            return '';
        }

        $criticalLow  = $rujukan['critical_low']  === null ? null : (float) $rujukan['critical_low'];
        $criticalHigh = $rujukan['critical_high'] === null ? null : (float) $rujukan['critical_high'];
        $low          = $rujukan['low']  === null ? null : (float) $rujukan['low'];
        $high         = $rujukan['high'] === null ? null : (float) $rujukan['high'];

        if ($criticalLow !== null && $nilai <= $criticalLow) {
            return 'LL';
        }
        if ($criticalHigh !== null && $nilai >= $criticalHigh) {
            return 'HH';
        }
        if ($low !== null && $nilai < $low) {
            return 'L';
        }
        if ($high !== null && $nilai > $high) {
            return 'H';
        }
        if ($low === null && $high === null) {
            return '';
        }

        return 'N';
    }

    /**
     * Tetapkan flag hasil kualitatif (teks/pilihan) dengan membandingkan
     * terhadap nilai normal yang tercatat pada nilai rujukan.
     */
    public static function flagTeks(?string $nilai, ?array $rujukan): string
    {
        if ($nilai === null || $nilai === '' || $rujukan === null) {
            return '';
        }

        $normal = trim((string) ($rujukan['nilai_normal_teks'] ?? ''));
        if ($normal === '') {
            return '';
        }

        foreach (explode(',', $normal) as $kandidat) {
            if (strcasecmp(trim($kandidat), trim($nilai)) === 0) {
                return 'N';
            }
        }

        return 'A';
    }

    /** Teks rujukan yang dicetak pada lembar hasil. */
    public static function teks(?array $rujukan): string
    {
        if ($rujukan === null) {
            return '';
        }

        $teks = trim((string) ($rujukan['teks_rujukan'] ?? ''));
        if ($teks !== '') {
            return $teks;
        }

        $low  = $rujukan['low'];
        $high = $rujukan['high'];

        if ($low !== null && $high !== null) {
            return rtrim(rtrim(number_format((float) $low, 2, '.', ''), '0'), '.')
                . ' - '
                . rtrim(rtrim(number_format((float) $high, 2, '.', ''), '0'), '.');
        }
        if ($high !== null) {
            return '< ' . rtrim(rtrim(number_format((float) $high, 2, '.', ''), '0'), '.');
        }
        if ($low !== null) {
            return '> ' . rtrim(rtrim(number_format((float) $low, 2, '.', ''), '0'), '.');
        }

        return (string) ($rujukan['nilai_normal_teks'] ?? '');
    }

    /** Apakah flag termasuk nilai kritis yang wajib dilaporkan. */
    public static function kritis(string $flag): bool
    {
        return $flag === 'LL' || $flag === 'HH';
    }

    /**
     * Delta check: bandingkan dengan hasil terverifikasi terakhir pasien
     * untuk pemeriksaan yang sama.
     *
     * @return array{status:string,persen:?float,nilai_sebelumnya:?float,tanggal:?string}
     */
    public static function deltaCheck(
        int $patientId,
        int $testId,
        ?float $nilaiBaru,
        int $rentangHari,
        float $ambangPersen,
        ?int $kecualikanOrderId = null
    ): array {
        $kosong = ['status' => 'tidak_ada_data', 'persen' => null, 'nilai_sebelumnya' => null, 'tanggal' => null];

        if ($nilaiBaru === null) {
            return $kosong;
        }

        $sql = 'SELECT r.nilai_num, r.verified_at, r.created_at
                FROM results r
                JOIN orders o ON o.id = r.order_id
                WHERE o.patient_id = ?
                  AND r.test_id = ?
                  AND r.nilai_num IS NOT NULL
                  AND r.status IN (\'verified\',\'final\',\'corrected\')
                  AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$patientId, $testId, $rentangHari];

        if ($kecualikanOrderId !== null) {
            $sql     .= ' AND r.order_id <> ?';
            $params[] = $kecualikanOrderId;
        }

        $sql .= ' ORDER BY r.created_at DESC LIMIT 1';

        $sebelumnya = Database::selectOne($sql, $params);
        if ($sebelumnya === null) {
            return $kosong;
        }

        $lama = (float) $sebelumnya['nilai_num'];
        if (abs($lama) < 1e-9) {
            return $kosong;
        }

        $persen = abs(($nilaiBaru - $lama) / $lama) * 100;

        return [
            'status'           => $persen >= $ambangPersen ? 'flagged' : 'ok',
            'persen'           => round($persen, 2),
            'nilai_sebelumnya' => $lama,
            'tanggal'          => (string) ($sebelumnya['verified_at'] ?? $sebelumnya['created_at']),
        ];
    }

    public static function bersihkanCache(): void
    {
        self::$cache = [];
    }
}
