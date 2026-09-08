<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Jejak audit. Setiap perubahan data klinis wajib tercatat di sini
 * (persyaratan telusur akreditasi laboratorium).
 */
final class Audit
{
    public static function log(
        string $aksi,
        ?string $refType = null,
        ?string $refId = null,
        ?string $deskripsi = null,
        mixed $dataLama = null,
        mixed $dataBaru = null,
        ?string $ip = null
    ): void {
        try {
            Database::insert('audit_logs', [
                'user_id'   => Auth::id(),
                'actor'     => Auth::check() ? Auth::nama() : 'system',
                'aksi'      => substr($aksi, 0, 60),
                'ref_type'  => $refType === null ? null : substr($refType, 0, 40),
                'ref_id'    => $refId === null ? null : substr($refId, 0, 40),
                'deskripsi' => $deskripsi === null ? null : substr($deskripsi, 0, 255),
                'data_lama' => self::encode($dataLama),
                'data_baru' => self::encode($dataBaru),
                'ip'        => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
        } catch (\Throwable $e) {
            // Audit tidak boleh menggagalkan operasi utama — cukup catat ke file.
            Logger::error('Gagal menulis audit log: ' . $e->getMessage());
        }
    }

    private static function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
