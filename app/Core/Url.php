<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembentuk URL yang sadar sub-folder instalasi.
 */
final class Url
{
    private static ?string $base = null;

    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        // app.base_url ditulis untuk Apache/XAMPP, mis. '/LIS/public'.
        //
        // Server bawaan PHP (php -S) tidak mengenal DocumentRoot htdocs:
        // dengan '-t public', folder public/ menjadi akar, sehingga awalan
        // '/LIS/public' menunjuk ke lokasi yang tidak ada dan setiap tautan
        // berakhir 404 — termasuk pengalihan pertama ke halaman masuk.
        //
        // Karena itu pada SAPI cli-server awalan selalu diturunkan dari
        // SCRIPT_NAME. Nilainya benar untuk kedua cara menjalankan server
        // bawaan ('-t public' maupun dari dalam htdocs), sehingga
        // config.php yang sama dapat dipakai untuk XAMPP dan php -S tanpa
        // perlu disunting bolak-balik.
        $configured = (string) Config::get('app.base_url', '');
        if ($configured !== '' && PHP_SAPI !== 'cli-server') {
            return self::$base = rtrim($configured, '/');
        }

        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

        return self::$base = ($script === '/' || $script === '.') ? '' : rtrim($script, '/');
    }

    /** Bentuk URL absolut-relatif dari path aplikasi. */
    public static function to(string $path = '/'): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return self::base() . '/' . ltrim($path, '/');
    }

    /** URL aset statis di dalam public/. */
    public static function asset(string $path): string
    {
        return self::to('assets/' . ltrim($path, '/'));
    }

    /** Tambahkan/ubah query string pada URL saat ini. */
    public static function withQuery(array $params): string
    {
        $current = $_GET;
        foreach ($params as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }

        $path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = http_build_query($current);

        return $path . ($query !== '' ? '?' . $query : '');
    }
}
