<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Penyusun response HTTP.
 */
final class Response
{
    private int $status = 200;
    /** @var array<string,string> */
    private array $headers = [];
    private string $body = '';

    public static function make(string $body = '', int $status = 200): self
    {
        $response         = new self();
        $response->body   = $body;
        $response->status = $status;

        return $response;
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $response = self::make(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status
        );
        $response->headers['Content-Type'] = 'application/json; charset=utf-8';

        return $response;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response                       = self::make('', $status);
        $response->headers['Location']  = Url::to($url);

        return $response;
    }

    public static function notFound(string $message = 'Halaman tidak ditemukan'): self
    {
        return self::make($message, 404);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            if (!isset($this->headers['Content-Type'])) {
                $this->headers['Content-Type'] = 'text/html; charset=utf-8';
            }
            // Header keamanan dasar.
            $this->headers += [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options'        => 'SAMEORIGIN',
                'Referrer-Policy'        => 'same-origin',
            ];
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
