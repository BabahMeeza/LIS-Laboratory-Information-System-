<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Renderer template PHP polos dengan dukungan layout.
 *
 * Contoh:
 *   View::render('orders/index', ['orders' => $orders], 'Daftar Order');
 */
final class View
{
    /** @var array<string,mixed> Data yang dibagikan ke seluruh view */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], string $judul = '', string $layout = 'layouts/app'): Response
    {
        $content = self::capture($template, $data);

        $layoutData = array_merge($data, [
            'judul'   => $judul,
            'konten'  => $content,
        ]);

        return Response::make(self::capture($layout, $layoutData));
    }

    /** Render tanpa layout (untuk fragmen HTMX / cetak). */
    public static function partial(string $template, array $data = []): Response
    {
        return Response::make(self::capture($template, $data));
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function capture(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('Template tidak ditemukan: ' . $template);
        }

        extract(array_merge(self::$shared, $data), EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
