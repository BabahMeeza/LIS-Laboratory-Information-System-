<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembungkus request HTTP.
 */
final class Request
{
    private string $method;
    private string $path;
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,string> */
    private array $headers;
    private ?string $rawBody = null;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query   = $_GET;
        $this->body    = $_POST;
        $this->headers = $this->collectHeaders();
        $this->path    = $this->resolvePath();

        // Dukung method override untuk form HTML (PUT/PATCH/DELETE).
        if ($this->method === 'POST' && isset($this->body['_method'])) {
            $override = strtoupper((string) $this->body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $this->method = $override;
            }
        }

        // Body JSON.
        if ($this->isJson() && $this->body === []) {
            $decoded = json_decode($this->rawBody(), true);
            if (is_array($decoded)) {
                $this->body = $decoded;
            }
        }
    }

    private function resolvePath(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Buang awalan folder tempat aplikasi dipasang.
        //
        // Ada dua tata letak yang harus sama-sama bekerja:
        //
        //   a. DocumentRoot menunjuk langsung ke public/
        //      SCRIPT_NAME /index.php → tidak ada awalan yang dibuang
        //
        //   b. Proyek diletakkan di htdocs sebagai sub-folder
        //      /LIS/public/login  → SCRIPT_NAME /LIS/public/index.php
        //      /LIS/login         → permintaan yang ditulis ulang secara
        //                           internal oleh .htaccess akar ke
        //                           public/, sehingga REQUEST_URI tetap
        //                           /LIS/login sementara SCRIPT_NAME sudah
        //                           /LIS/public/index.php
        //
        // Karena itu awalan dicoba dari yang terpanjang ke yang terpendek:
        // /LIS/public lebih dulu, baru /LIS. Yang pertama cocok dipakai.
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($script !== '' && $script !== '/' && $script !== '.') {
            $segmen = explode('/', trim($script, '/'));

            while ($segmen !== []) {
                $awalan = '/' . implode('/', $segmen);

                // Cocok hanya bila benar-benar batas segmen, agar
                // /LIST tidak ikut terpangkas oleh awalan /LIS.
                if ($path === $awalan || str_starts_with($path, $awalan . '/')) {
                    $path = substr($path, strlen($awalan));
                    break;
                }

                array_pop($segmen);
            }
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array<string,string> */
    private function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name           = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Nama header yang benar-benar diterima.
     *
     * Dipakai saat mencatat penolakan autentikasi: bila X-API-Key hilang,
     * daftar ini membedakan "klien tidak mengirimnya" dari "proksi atau
     * server web membuangnya di tengah jalan". Hanya nama, tanpa nilai.
     *
     * @return list<string>
     */
    public function namaHeader(): array
    {
        return array_keys($this->headers);
    }

    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type', '')), 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isJson()
            || str_contains(strtolower((string) $this->header('Accept', '')), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input');
        }

        return $this->rawBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'ya', 'yes'], true);
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<int|string,mixed> */
    public function arr(string $key): array
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', (string) $_SERVER[$key])[0]);
            }
        }

        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
