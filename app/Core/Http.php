<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Klien HTTP minimal untuk memanggil konektor Khanza.
 *
 * Memakai cURL bila tersedia, jika tidak jatuh ke stream context
 * (beberapa instalasi XAMPP tidak mengaktifkan ekstensi cURL).
 */
final class Http
{
    /**
     * @param  array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error:?string,data:array<mixed>|null}
     */
    public static function request(
        string $method,
        string $url,
        ?array $json = null,
        array $headers = [],
        int $timeout = 15,
        bool $verifySsl = true
    ): array {
        $method = strtoupper($method);
        $body   = $json === null ? null : (string) json_encode($json, JSON_UNESCAPED_UNICODE);

        $headers['Accept'] = 'application/json';
        if ($body !== null) {
            $headers['Content-Type']   = 'application/json; charset=utf-8';
            $headers['Content-Length'] = (string) strlen($body);
        }

        $result = function_exists('curl_init')
            ? self::viaCurl($method, $url, $body, $headers, $timeout, $verifySsl)
            : self::viaStream($method, $url, $body, $headers, $timeout, $verifySsl);

        $result['data'] = null;
        if ($result['body'] !== '') {
            $decoded = json_decode($result['body'], true);
            if (is_array($decoded)) {
                $result['data'] = $decoded;
            }
        }

        return $result;
    }

    /** @param array<string,string> $headers */
    public static function get(string $url, array $headers = [], int $timeout = 15, bool $verifySsl = true): array
    {
        return self::request('GET', $url, null, $headers, $timeout, $verifySsl);
    }

    /** @param array<string,string> $headers */
    public static function post(string $url, array $json, array $headers = [], int $timeout = 15, bool $verifySsl = true): array
    {
        return self::request('POST', $url, $json, $headers, $timeout, $verifySsl);
    }

    /**
     * Tanda tangan HMAC-SHA256 atas isi body.
     * Konektor Khanza memverifikasi nilai ini pada header X-Signature.
     */
    public static function signature(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /** @param array<string,string> $headers */
    private static function viaCurl(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout,
        bool $verifySsl
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Gagal menginisialisasi cURL'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'ok'     => $error === null && $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => $response === false ? '' : (string) $response,
            'error'  => $error,
        ];
    }

    /** @param array<string,string> $headers */
    private static function viaStream(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout,
        bool $verifySsl
    ): array {
        $headerString = '';
        foreach ($headers as $name => $value) {
            $headerString .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headerString,
                'content'       => $body ?? '',
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status   = 0;

        if (isset($http_response_header[0])
            && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) === 1) {
            $status = (int) $m[1];
        }

        return [
            'ok'     => $response !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => $response === false ? '' : (string) $response,
            'error'  => $response === false ? 'Permintaan HTTP gagal' : null,
        ];
    }
}
