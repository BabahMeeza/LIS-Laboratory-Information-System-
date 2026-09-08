<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Fungsi bantu lintas modul: format tanggal Indonesia, hitung umur,
 * escaping, dan pembentukan nomor.
 */
final class Helper
{
    private const BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const HARI = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
    ];

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function tanggal(?string $datetime, bool $denganJam = false): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '-';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '-';
        }

        $teks = date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);

        return $denganJam ? $teks . ', ' . date('H:i', $ts) : $teks;
    }

    public static function tanggalPendek(?string $datetime, bool $denganJam = true): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '-';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '-';
        }

        return date($denganJam ? 'd/m/Y H:i' : 'd/m/Y', $ts);
    }

    public static function hari(?string $datetime): string
    {
        $ts = $datetime === null ? time() : (strtotime($datetime) ?: time());

        return self::HARI[date('l', $ts)] ?? '';
    }

    /** Umur dalam hari — dipakai untuk memilih nilai rujukan. */
    public static function umurHari(?string $tglLahir, ?string $acuan = null): ?int
    {
        if ($tglLahir === null || $tglLahir === '' || str_starts_with($tglLahir, '0000')) {
            return null;
        }
        $lahir = strtotime($tglLahir);
        $ref   = $acuan === null ? time() : (strtotime($acuan) ?: time());
        if ($lahir === false || $lahir > $ref) {
            return null;
        }

        return (int) floor(($ref - $lahir) / 86400);
    }

    /** Umur terformat: "34 th 2 bl 5 hr". */
    public static function umurTeks(?string $tglLahir, ?string $acuan = null): string
    {
        if ($tglLahir === null || $tglLahir === '' || str_starts_with($tglLahir, '0000')) {
            return '-';
        }

        try {
            $lahir = new \DateTimeImmutable($tglLahir);
            $ref   = new \DateTimeImmutable($acuan ?? 'now');
            if ($lahir > $ref) {
                return '-';
            }
            $diff = $lahir->diff($ref);

            return sprintf('%d th %d bl %d hr', $diff->y, $diff->m, $diff->d);
        } catch (\Throwable) {
            return '-';
        }
    }

    public static function jenisKelamin(?string $kode): string
    {
        return match ($kode) {
            'L'     => 'Laki-laki',
            'P'     => 'Perempuan',
            default => '-',
        };
    }

    public static function rupiah(float|int|string|null $nilai): string
    {
        return 'Rp ' . number_format((float) $nilai, 0, ',', '.');
    }

    /** Format nilai numerik sesuai jumlah desimal pemeriksaan. */
    public static function nilai(mixed $nilai, int $desimal = 2): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }
        if (!is_numeric($nilai)) {
            return (string) $nilai;
        }

        return number_format((float) $nilai, $desimal, '.', '');
    }

    /** Label bahasa Indonesia untuk status alur kerja. */
    public static function labelStatus(string $status): string
    {
        return match ($status) {
            'draft'         => 'Draft',
            'ordered'       => 'Terdaftar',
            'collected'     => 'Sampel Diambil',
            'received'      => 'Sampel Diterima',
            'in_progress'   => 'Sedang Diperiksa',
            'resulted'      => 'Ada Hasil',
            'verified'      => 'Terverifikasi',
            'released'      => 'Dirilis',
            'cancelled'     => 'Dibatalkan',
            'rejected'      => 'Ditolak',
            'pending'       => 'Menunggu',
            'preliminary'   => 'Sementara',
            'final'         => 'Final',
            'corrected'     => 'Dikoreksi',
            'rerun'         => 'Ulang',
            'menunggu'      => 'Menunggu',
            'terpasang'     => 'Terpasang',
            'dibuang'       => 'Dibuang',
            default         => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** Kelas CSS badge untuk status. */
    public static function warnaStatus(string $status): string
    {
        return match ($status) {
            'released', 'verified', 'final'     => 'sukses',
            'resulted', 'in_progress'           => 'info',
            'collected', 'received', 'ordered'  => 'netral',
            'cancelled', 'rejected', 'error'    => 'bahaya',
            'draft', 'pending', 'menunggu'      => 'redup',
            default                             => 'netral',
        };
    }

    /** Kelas CSS untuk flag hasil. */
    public static function warnaFlag(string $flag): string
    {
        return match ($flag) {
            'LL', 'HH' => 'kritis',
            'L', 'H'   => 'abnormal',
            'A'        => 'abnormal',
            'N'        => 'normal',
            default    => '',
        };
    }

    public static function labelFlag(string $flag): string
    {
        return match ($flag) {
            'L'     => 'L',
            'H'     => 'H',
            'LL'    => 'L!',
            'HH'    => 'H!',
            'A'     => 'A',
            default => '',
        };
    }

    /** Potong teks panjang untuk tampilan tabel. */
    public static function potong(?string $teks, int $panjang = 60): string
    {
        $teks = (string) $teks;

        return mb_strlen($teks) <= $panjang ? $teks : mb_substr($teks, 0, $panjang - 1) . '…';
    }

    /** Selisih waktu ramah-baca, mis. "12 menit lalu". */
    public static function sejak(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '-';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '-';
        }

        $detik = time() - $ts;
        if ($detik < 0) {
            return 'baru saja';
        }
        if ($detik < 60) {
            return $detik . ' detik lalu';
        }
        if ($detik < 3600) {
            return intdiv($detik, 60) . ' menit lalu';
        }
        if ($detik < 86400) {
            return intdiv($detik, 3600) . ' jam lalu';
        }

        return intdiv($detik, 86400) . ' hari lalu';
    }

    /** Durasi menit → "1j 25m". */
    public static function durasi(?int $menit): string
    {
        if ($menit === null) {
            return '-';
        }
        if ($menit < 60) {
            return $menit . 'm';
        }

        return intdiv($menit, 60) . 'j ' . ($menit % 60) . 'm';
    }

    public static function slug(string $teks): string
    {
        $teks = preg_replace('/[^a-zA-Z0-9]+/', '-', $teks) ?? '';

        return strtolower(trim($teks, '-'));
    }
}
