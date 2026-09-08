<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Token CSRF untuk seluruh form yang mengubah data.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!Session::has(self::KEY)) {
            Session::put(self::KEY, bin2hex(random_bytes(32)));
        }

        return (string) Session::get(self::KEY);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function check(Request $request): bool
    {
        if (!Config::get('security.csrf_enabled', true)) {
            return true;
        }

        // Request API memakai API key, bukan CSRF.
        if (str_starts_with($request->path(), '/api/')) {
            return true;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $sent = (string) ($request->input('_token') ?? $request->header('X-CSRF-Token', ''));

        return $sent !== '' && hash_equals(self::token(), $sent);
    }
}
