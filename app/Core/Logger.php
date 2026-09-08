<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Logger berkas harian sederhana.
 */
final class Logger
{
    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }

        $dir = BASE_PATH . '/' . trim((string) Config::get('storage.logs', 'storage/logs'), '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return self::$dir = $dir;
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s: %s%s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE),
            PHP_EOL
        );

        @file_put_contents(self::dir() . '/lis-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(\Throwable $e): void
    {
        self::write('error', get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())[0] ?? '',
        ]);
    }
}
