<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembungkus session PHP dengan pengaturan cookie yang aman.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name((string) Config::get('app.session_name', 'LISSESSID'));
        session_set_cookie_params([
            'lifetime' => (int) Config::get('app.session_lifetime', 28800),
            'path'     => Url::base() !== '' ? Url::base() . '/' : '/',
            'httponly' => true,
            'secure'   => $https,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        // Regenerasi ID berkala untuk mengurangi risiko session fixation.
        if (!isset($_SESSION['_lahir'])) {
            $_SESSION['_lahir'] = time();
        } elseif (time() - (int) $_SESSION['_lahir'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_lahir'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }

    /** Ambil nilai lalu hapus (flash data). */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }
}
