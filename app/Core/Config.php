<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembaca konfigurasi.
 *
 * Menggabungkan dua sumber:
 *   1. config/config.php  — statis, dibaca sekali saat boot
 *   2. tabel `settings`   — dapat diubah pengguna lewat UI (lazy-load)
 *
 * Akses memakai notasi titik: Config::get('db.host').
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    /** @var array<string,string|null>|null */
    private static ?array $settings = null;

    /** @param array<string,mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Nilai dari tabel `settings` (dapat diubah lewat UI). */
    public static function setting(string $key, ?string $default = null): ?string
    {
        if (self::$settings === null) {
            self::$settings = [];
            try {
                $rows = Database::select('SELECT `key`, `value` FROM settings');
                foreach ($rows as $row) {
                    self::$settings[$row['key']] = $row['value'];
                }
            } catch (\Throwable $e) {
                // Database belum siap (mis. saat instalasi) — jatuh ke default.
                return $default;
            }
        }

        $value = self::$settings[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function settingBool(string $key, bool $default = false): bool
    {
        $value = self::setting($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'ya', 'on'], true);
    }

    public static function settingInt(string $key, int $default = 0): int
    {
        $value = self::setting($key);

        return $value === null ? $default : (int) $value;
    }

    public static function putSetting(string $key, ?string $value): void
    {
        Database::execute(
            'INSERT INTO settings (`key`,`value`) VALUES (?,?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
        self::$settings[$key] = $value;
    }

    /** Buang cache settings agar dibaca ulang dari database. */
    public static function flushSettings(): void
    {
        self::$settings = null;
    }
}
