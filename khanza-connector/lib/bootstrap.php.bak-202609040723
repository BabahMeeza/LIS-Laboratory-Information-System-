<?php
declare(strict_types=1);

/**
 * Bootstrap konektor Khanza.
 *
 * Konektor sengaja ditulis sebagai sekumpulan berkas PHP polos tanpa
 * framework dan tanpa Composer, karena harus dapat dijatuhkan begitu saja
 * ke dalam htdocs pada server SIMRS Khanza yang umumnya memakai XAMPP
 * versi lama dan tidak memiliki akses internet.
 */

if (!defined('KONEKTOR_VERSI')) {
    define('KONEKTOR_VERSI', '1.0.0');
}

// -------------------------------------------------------------------
// Ekstensi wajib
// -------------------------------------------------------------------
//
// Diperiksa lebih dulu agar ekstensi yang hilang menghasilkan jawaban JSON
// yang menyebutkan perbaikannya, bukan galat fatal di tengah permintaan
// (mis. "Call to undefined function mb_substr()") yang dari sisi LIS hanya
// tampak sebagai HTTP 500 tanpa keterangan apa pun.

$ekstensiHilang = [];
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json'] as $ekstensi) {
    if (!extension_loaded($ekstensi)) {
        $ekstensiHilang[] = $ekstensi;
    }
}

if ($ekstensiHilang !== []) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    exit(json_encode([
        'sukses' => false,
        'pesan'  => 'Ekstensi PHP belum aktif: ' . implode(', ', $ekstensiHilang)
            . '. Aktifkan pada ' . (php_ini_loaded_file() ?: 'php.ini')
            . ' (hapus titik-koma di depan baris extension=<nama>), '
            . 'lalu jalankan ulang Apache.',
    ]));
}

// -------------------------------------------------------------------
// Konfigurasi
// -------------------------------------------------------------------

$berkasKonfigurasi = __DIR__ . '/../config.php';

if (!is_file($berkasKonfigurasi)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    exit(json_encode([
        'sukses' => false,
        'pesan'  => 'config.php belum dibuat. Salin config.example.php menjadi config.php lalu sesuaikan.',
    ]));
}

/** @var array<string,mixed> $KONFIG */
$KONFIG = require $berkasKonfigurasi;

// -------------------------------------------------------------------
// Bantuan umum
// -------------------------------------------------------------------

function konfig(string $kunci, $bawaan = null)
{
    global $KONFIG;

    $bagian = explode('.', $kunci);
    $nilai  = $KONFIG;

    foreach ($bagian as $b) {
        if (!is_array($nilai) || !array_key_exists($b, $nilai)) {
            return $bawaan;
        }
        $nilai = $nilai[$b];
    }

    return $nilai;
}

function catat(string $tingkat, string $pesan): void
{
    $berkas = konfig('log_file', '');
    if ($berkas === '' || $berkas === null) {
        return;
    }

    $baris = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($tingkat), $pesan);
    @file_put_contents($berkas, $baris, FILE_APPEND | LOCK_EX);
}

/**
 * @param array<string,mixed> $data
 */
function jawab(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sukses($data = null, string $pesan = 'OK'): void
{
    jawab([
        'sukses' => true,
        'pesan'  => $pesan,
        'data'   => $data,
        'versi'  => KONEKTOR_VERSI,
        'waktu'  => date('c'),
    ]);
}

function gagal(string $pesan, int $status = 400, $data = null): void
{
    catat('warn', "Ditolak ($status): $pesan");

    jawab([
        'sukses' => false,
        'pesan'  => $pesan,
        'data'   => $data,
        'versi'  => KONEKTOR_VERSI,
        'waktu'  => date('c'),
    ], $status);
}

// -------------------------------------------------------------------
// Database
// -------------------------------------------------------------------

/**
 * @param string $bagian 'db_bridging' atau 'db_sik'
 */
function db(string $bagian = 'db_bridging'): PDO
{
    static $koneksi = [];

    if (isset($koneksi[$bagian])) {
        return $koneksi[$bagian];
    }

    $c = konfig($bagian, []);
    if (!is_array($c) || ($c['name'] ?? '') === '') {
        gagal('Konfigurasi database "' . $bagian . '" belum lengkap.', 500);
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'] ?? '127.0.0.1',
        (int) ($c['port'] ?? 3306),
        $c['name'],
        $c['charset'] ?? 'utf8'
    );

    try {
        $pdo = new PDO($dsn, $c['user'] ?? 'root', $c['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        catat('error', 'Koneksi database gagal: ' . $e->getMessage());
        gagal('Koneksi ke database ' . $c['name'] . ' gagal: ' . $e->getMessage(), 500);
    }

    return $koneksi[$bagian] = $pdo;
}

/**
 * @param  array<int,mixed> $params
 * @return array<int,array<string,mixed>>
 */
function ambilSemua(string $sql, array $params = [], string $bagian = 'db_bridging'): array
{
    $stmt = db($bagian)->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** @param array<int,mixed> $params */
function jalankan(string $sql, array $params = [], string $bagian = 'db_bridging'): int
{
    $stmt = db($bagian)->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/** Cek keberadaan tabel — dipakai ping.php untuk diagnosa pemasangan. */
function tabelAda(string $tabel, string $bagian = 'db_bridging'): bool
{
    $stmt = db($bagian)->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$tabel]);

    return (int) $stmt->fetchColumn() > 0;
}

/** Daftar kolom sebuah tabel — dipakai agar kueri tahan beda versi Khanza. */
function kolomTabel(string $tabel, string $bagian = 'db_bridging'): array
{
    static $cache = [];
    $kunci = $bagian . '.' . $tabel;

    if (isset($cache[$kunci])) {
        return $cache[$kunci];
    }

    try {
        $stmt = db($bagian)->prepare(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$tabel]);
        $kolom = array_map('strval', array_column($stmt->fetchAll(), 'column_name'));
    } catch (Throwable $e) {
        $kolom = [];
    }

    return $cache[$kunci] = $kolom;
}

// -------------------------------------------------------------------
// Autentikasi
// -------------------------------------------------------------------

function bodyMentah(): string
{
    static $body = null;

    if ($body === null) {
        $body = (string) file_get_contents('php://input');
    }

    return $body;
}

/** @return array<string,mixed> */
function bodyJson(): array
{
    $isi = json_decode(bodyMentah(), true);

    return is_array($isi) ? $isi : [];
}

function headerPermintaan(string $nama): string
{
    $kunci = 'HTTP_' . strtoupper(str_replace('-', '_', $nama));

    return (string) ($_SERVER[$kunci] ?? '');
}

function ipPemanggil(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', (string) $_SERVER[$k])[0]);
        }
    }

    return '0.0.0.0';
}

function ipCocokCidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }

    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipLong          = ip2long($ip);
    $subnetLong      = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    $mask = -1 << (32 - (int) $bits);

    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Verifikasi kredensial pemanggil. Dipanggil di awal setiap endpoint.
 */
function wajibAuth(): void
{
    $daftarIp = konfig('ip_whitelist', []);
    if (is_array($daftarIp) && $daftarIp !== []) {
        $ip = ipPemanggil();
        $ok = false;
        foreach ($daftarIp as $izin) {
            if (ipCocokCidr($ip, trim((string) $izin))) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            gagal('Alamat IP ' . $ip . ' tidak diizinkan mengakses konektor ini.', 403);
        }
    }

    $kunciDiharapkan = (string) konfig('api_key', '');
    if ($kunciDiharapkan === '' || strpos($kunciDiharapkan, 'ubah_dengan') === 0) {
        gagal('Konektor belum dikonfigurasi: api_key masih bernilai bawaan.', 500);
    }

    $kunciDikirim = headerPermintaan('X-API-Key');
    if ($kunciDikirim === '') {
        gagal('Header X-API-Key tidak ditemukan.', 401);
    }
    if (!hash_equals($kunciDiharapkan, $kunciDikirim)) {
        gagal('API key tidak dikenali.', 401);
    }

    $secret    = (string) konfig('api_secret', '');
    $signature = headerPermintaan('X-Signature');
    $wajib     = (bool) konfig('wajib_signature', false);
    $body      = bodyMentah();

    if ($wajib && $body !== '' && $signature === '') {
        gagal('Tanda tangan (X-Signature) wajib disertakan.', 401);
    }

    if ($signature !== '' && $secret !== '') {
        $dihitung = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($dihitung, $signature)) {
            gagal('Tanda tangan permintaan tidak sah.', 401);
        }
    }
}

/** Batasi endpoint pada metode HTTP tertentu. */
function wajibMetode(string $metode): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($metode)) {
        gagal('Metode HTTP harus ' . strtoupper($metode) . '.', 405);
    }
}

// -------------------------------------------------------------------
// Klien HTTP (untuk push_orders.php)
// -------------------------------------------------------------------

/**
 * @param  array<string,mixed> $payload
 * @return array{ok:bool,status:int,body:string,data:array<mixed>|null,error:?string}
 */
function kirimKeLis(string $jalur, array $payload): array
{
    $base   = rtrim((string) konfig('lis.base_url', ''), '/');
    $url    = $base . $jalur;
    $body   = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $secret = (string) konfig('lis.api_secret', '');

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'X-API-Key: ' . (string) konfig('lis.api_key', ''),
    ];

    if ($secret !== '' && strpos($secret, 'ubah_dengan') !== 0) {
        $headers[] = 'X-Signature: ' . hash_hmac('sha256', $body, $secret);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int) konfig('lis.timeout', 20),
            CURLOPT_SSL_VERIFYPEER => (bool) konfig('lis.verify_ssl', true),
            CURLOPT_SSL_VERIFYHOST => konfig('lis.verify_ssl', true) ? 2 : 0,
        ]);

        $respons = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error   = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
    } else {
        $konteks = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => (int) konfig('lis.timeout', 20),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => (bool) konfig('lis.verify_ssl', true),
                'verify_peer_name' => (bool) konfig('lis.verify_ssl', true),
            ],
        ]);

        $respons = @file_get_contents($url, false, $konteks);
        $status  = 0;
        if (isset($http_response_header[0]) &&
            preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) === 1) {
            $status = (int) $m[1];
        }
        $error = $respons === false ? 'Permintaan HTTP gagal' : null;
    }

    $teks = $respons === false ? '' : (string) $respons;
    $data = json_decode($teks, true);

    return [
        'ok'     => $error === null && $status >= 200 && $status < 300,
        'status' => $status,
        'body'   => $teks,
        'data'   => is_array($data) ? $data : null,
        'error'  => $error,
    ];
}
