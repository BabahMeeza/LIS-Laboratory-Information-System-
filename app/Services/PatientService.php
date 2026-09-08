<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Pengelolaan data pasien, termasuk sinkronisasi dari SIMRS Khanza.
 */
final class PatientService
{
    /** Nomor rekam medis internal LIS bila pasien datang tanpa RM rumah sakit. */
    public static function nomorRm(): string
    {
        $urut = Database::nextCounter('rm:global');

        return 'L' . str_pad((string) $urut, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Cari atau buat pasien berdasarkan data order Khanza.
     * Pencocokan utama memakai no_rkm_medis Khanza.
     *
     * @param  array<string,mixed> $payload
     * @return int|null id pasien, atau null bila data tidak memadai
     */
    public static function dariKhanza(array $payload): ?int
    {
        $noRkmMedis = trim((string) ($payload['no_rkm_medis'] ?? ''));
        $nama       = trim((string) ($payload['nm_pasien'] ?? $payload['nama'] ?? ''));

        if ($noRkmMedis === '' || $nama === '') {
            return null;
        }

        $existing = Database::selectOne(
            'SELECT id FROM patients WHERE khanza_no_rkm_medis = ? LIMIT 1',
            [$noRkmMedis]
        );

        $data = [
            'khanza_no_rkm_medis' => $noRkmMedis,
            'nama'                => mb_substr($nama, 0, 100),
            'jk'                  => self::jk((string) ($payload['jk'] ?? '')),
            'tgl_lahir'           => self::tanggal((string) ($payload['tgl_lahir'] ?? '')),
            'tempat_lahir'        => self::teks($payload['tmp_lahir'] ?? null, 50),
            'alamat'              => self::teks($payload['alamat'] ?? null, 255),
            'email'               => self::teks($payload['email'] ?? null, 100),
            'nik'                 => self::teks($payload['no_ktp'] ?? $payload['nik'] ?? null, 20),
        ];

        if ($existing !== null) {
            // Perbarui data demografi bila berubah di SIMRS.
            Database::update('patients', array_filter(
                $data,
                static fn ($v) => $v !== null && $v !== ''
            ), 'id = ?', [$existing['id']]);

            return (int) $existing['id'];
        }

        if (!Config::settingBool('khanza.auto_buat_pasien', true)) {
            return null;
        }

        $data['no_rm'] = $noRkmMedis; // gunakan nomor RM rumah sakit sebagai nomor RM LIS

        // Hindari tabrakan bila nomor RM sudah dipakai pasien lain.
        if ((int) Database::scalar('SELECT COUNT(*) FROM patients WHERE no_rm = ?', [$data['no_rm']]) > 0) {
            $data['no_rm'] = self::nomorRm();
        }

        return Database::insert('patients', $data);
    }

    private static function jk(string $nilai): string
    {
        $nilai = strtoupper(trim($nilai));

        return match ($nilai) {
            'L', 'M', '1', 'LAKI-LAKI', 'LAKI' => 'L',
            'P', 'F', '2', 'PEREMPUAN', 'WANITA' => 'P',
            default => 'X',
        };
    }

    private static function tanggal(string $nilai): ?string
    {
        $nilai = trim($nilai);
        if ($nilai === '' || str_starts_with($nilai, '0000')) {
            return null;
        }
        $ts = strtotime($nilai);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private static function teks(mixed $nilai, int $maks): ?string
    {
        if ($nilai === null) {
            return null;
        }
        $teks = trim((string) $nilai);

        return $teks === '' ? null : mb_substr($teks, 0, $maks);
    }

    /**
     * Pencarian pasien untuk autocomplete.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cari(string $kata, int $limit = 20): array
    {
        $kata = trim($kata);
        if ($kata === '') {
            return [];
        }
        $like = '%' . $kata . '%';

        return Database::select(
            'SELECT id, no_rm, khanza_no_rkm_medis, nama, jk, tgl_lahir, alamat
             FROM patients
             WHERE no_rm LIKE ? OR khanza_no_rkm_medis LIKE ? OR nama LIKE ? OR nik LIKE ?
             ORDER BY nama LIMIT ' . max(1, min(100, $limit)),
            [$like, $like, $like, $like]
        );
    }
}
