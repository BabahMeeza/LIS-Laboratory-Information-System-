<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Kesalahan yang diterjemahkan langsung menjadi status HTTP.
 */
final class HttpException extends \RuntimeException
{
    public function __construct(private int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::pesanDefault($status), $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    private static function pesanDefault(int $status): string
    {
        return match ($status) {
            400     => 'Permintaan tidak sah.',
            401     => 'Anda harus masuk terlebih dahulu.',
            403     => 'Akses ditolak.',
            404     => 'Data atau halaman tidak ditemukan.',
            405     => 'Metode HTTP tidak diizinkan untuk alamat ini.',
            409     => 'Terjadi konflik data.',
            422     => 'Data yang dikirim tidak lolos validasi.',
            429     => 'Terlalu banyak permintaan.',
            default => 'Terjadi kesalahan pada server.',
        };
    }
}
