<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pesan sekali-tampil antar redirect.
 */
final class Flash
{
    private const KEY = '_flash';

    public static function add(string $tipe, string $pesan): void
    {
        $messages   = (array) Session::get(self::KEY, []);
        $messages[] = ['tipe' => $tipe, 'pesan' => $pesan];
        Session::put(self::KEY, $messages);
    }

    public static function sukses(string $pesan): void
    {
        self::add('sukses', $pesan);
    }

    public static function error(string $pesan): void
    {
        self::add('error', $pesan);
    }

    public static function info(string $pesan): void
    {
        self::add('info', $pesan);
    }

    public static function peringatan(string $pesan): void
    {
        self::add('peringatan', $pesan);
    }

    /** @return array<int,array{tipe:string,pesan:string}> */
    public static function ambil(): array
    {
        /** @var array<int,array{tipe:string,pesan:string}> $messages */
        $messages = (array) Session::pull(self::KEY, []);

        return $messages;
    }

    /** Simpan input form agar dapat diisi ulang setelah validasi gagal. */
    public static function simpanInput(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_baru'], $input['password_konfirmasi']);
        Session::put('_old_input', $input);
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        $old = (array) Session::get('_old_input', []);

        return $old[$key] ?? $default;
    }

    public static function bersihkanInput(): void
    {
        Session::forget('_old_input');
    }

    /** @param array<string,string> $errors */
    public static function simpanError(array $errors): void
    {
        Session::put('_errors', $errors);
    }

    /** @return array<string,string> */
    public static function errors(): array
    {
        return (array) Session::pull('_errors', []);
    }
}
