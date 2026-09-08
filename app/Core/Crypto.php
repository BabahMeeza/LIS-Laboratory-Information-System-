<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Enkripsi simetris untuk rahasia yang harus dapat dibaca kembali —
 * khususnya secret klien API yang dipakai menghitung HMAC.
 *
 * Kata sandi pengguna TIDAK memakai kelas ini; kata sandi tetap
 * di-hash satu arah dengan bcrypt.
 *
 * Format keluaran: base64( iv[12] || tag[16] || ciphertext ).
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    /** Kunci 32 byte diturunkan dari security.app_key. */
    private static function kunci(): string
    {
        $appKey = (string) Config::get('security.app_key', '');

        if ($appKey === '' || str_starts_with($appKey, 'ubah_dengan')) {
            throw new RuntimeException(
                'security.app_key belum diatur di config/config.php. '
                . 'Hasilkan dengan: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        return hash('sha256', 'lis-secret-v1|' . $appKey, true);
    }

    public static function tersedia(): bool
    {
        try {
            self::kunci();
        } catch (\Throwable) {
            return false;
        }

        return in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    public static function enkripsi(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::kunci(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Enkripsi gagal.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /** @return string|null null bila data rusak atau kunci tidak cocok */
    public static function dekripsi(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        try {
            $plain = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                self::kunci(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
        } catch (\Throwable $e) {
            Logger::error('Dekripsi gagal: ' . $e->getMessage());

            return null;
        }

        return $plain === false ? null : $plain;
    }
}
