<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Autentikasi untuk endpoint /api/*.
 *
 * Skema: header X-API-Key wajib. Bila klien menyertakan X-Signature,
 * tanda tangan HMAC-SHA256 atas raw body diverifikasi dengan secret klien.
 * Verifikasi tanda tangan bersifat opsional agar alat/skrip lama tetap
 * dapat terhubung, tetapi sangat dianjurkan untuk jaringan non-lokal.
 */
final class ApiAuth
{
    /** @var array<string,mixed>|null */
    private static ?array $client = null;

    /**
     * @throws HttpException
     */
    public static function wajib(Request $request, string $scope = ''): void
    {
        // Lisensi diperiksa lebih dulu. Kredensial yang sah pun tidak boleh
        // melayani selama identitas aplikasi tidak utuh — inilah yang
        // dimaksud "kredensial menjadi tidak aktif". Keadaan ini runtime,
        // bukan tulisan ke kolom aktif: baris kredensial tidak disentuh,
        // sehingga pemulihannya tidak menyisakan pekerjaan rumah.
        if (Lisensi::terkunci()) {
            self::tolak($request, 'lisensi aplikasi tidak sah', [
                'sebab_lisensi' => Lisensi::sebab(),
            ]);
            throw new HttpException(
                503,
                'Kredensial API dinonaktifkan: lisensi aplikasi tidak sah. ' . Lisensi::sebab()
            );
        }

        $key = $request->header('X-Api-Key') ?? $request->str('api_key');

        if ($key === '' || $key === null) {
            self::tolak($request, 'header X-API-Key tidak ada', [
                'header_diterima' => implode(',', $request->namaHeader()),
            ]);
            throw new HttpException(401, 'Header X-API-Key tidak ditemukan.');
        }

        $client = Database::selectOne(
            'SELECT * FROM api_clients WHERE api_key = ? AND aktif = 1 LIMIT 1',
            [$key]
        );

        if ($client === null) {
            self::tolak($request, 'API key tidak dikenali atau nonaktif', [
                'key' => substr($key, 0, 12) . '…',
            ]);
            throw new HttpException(401, 'API key tidak dikenali atau sudah dinonaktifkan.');
        }

        // Batasan IP per klien.
        $whitelist = trim((string) ($client['ip_whitelist'] ?? ''));
        if ($whitelist !== '' && !self::ipDiizinkan($request->ip(), $whitelist)) {
            self::tolak($request, 'IP tidak ada dalam batas kredensial', [
                'klien'    => (string) $client['nama'],
                'daftar'   => $whitelist,
                'petunjuk' => 'Tambahkan ' . $request->ip() . ' ke Batas IP kredensial ini, atau kosongkan daftarnya.',
            ]);

            // Alamat yang terlihat ikut disebut. Tanpa itu petugas menebak
            // alamat mana yang harus didaftarkan — dan tebakannya sering
            // meleset, karena klien di mesin yang sama muncul sebagai ::1,
            // bukan sebagai alamat LAN yang mereka ketik di pengaturan.
            throw new HttpException(
                403,
                'Alamat IP ' . $request->ip() . ' tidak ada dalam Batas IP kredensial "'
                . $client['nama'] . '". Daftar yang berlaku: ' . $whitelist
            );
        }

        // Cakupan izin.
        if ($scope !== '') {
            $scopes = array_map('trim', explode(',', (string) $client['scopes']));
            if (!in_array($scope, $scopes, true) && !in_array('admin', $scopes, true)) {
                self::tolak($request, 'cakupan tidak dimiliki', [
                    'klien'      => (string) $client['nama'],
                    'diperlukan' => $scope,
                    'dimiliki'   => (string) $client['scopes'],
                ]);
                throw new HttpException(403, 'Kredensial ini tidak memiliki cakupan: ' . $scope);
            }
        }

        // Verifikasi tanda tangan HMAC bila dikirim.
        // Secret yang dipakai adalah milik KLIEN INI, bukan secret bersama.
        $signature = $request->header('X-Signature');
        if ($signature !== null && $signature !== '') {
            $secret = Crypto::dekripsi($client['secret_enc'] ?? null);

            if ($secret === null) {
                self::tolak($request, 'secret_enc tidak dapat dibaca', [
                    'klien'      => (string) $client['nama'],
                    'secret_enc' => ($client['secret_enc'] ?? null) === null ? 'NULL' : 'ada tapi gagal didekripsi',
                    'petunjuk'   => 'security.app_key berbeda dari saat kredensial dibuat, atau kosong saat itu',
                ]);
                throw new HttpException(
                    401,
                    'Kredensial "' . $client['nama'] . '" tidak menyimpan secret yang dapat dipakai '
                    . 'memverifikasi tanda tangan. Buat ulang kredensial dari menu Pengaturan → Kredensial API.'
                );
            }
            if (!hash_equals(hash_hmac('sha256', $request->rawBody(), $secret), $signature)) {
                // Tanda tangan dicatat sebagian saja. Cukup untuk membedakan
                // "secret berbeda" dari "body berbeda" tanpa membocorkan apa pun:
                // panjang body yang ditandatangani ikut dicatat karena penyebab
                // tersering adalah body yang berubah di tengah jalan, bukan secret.
                self::tolak($request, 'X-Signature tidak cocok', [
                    'klien'        => (string) $client['nama'],
                    'diterima'     => substr($signature, 0, 12) . '…',
                    'seharusnya'   => substr(hash_hmac('sha256', $request->rawBody(), $secret), 0, 12) . '…',
                    'panjang_body' => strlen($request->rawBody()),
                ]);
                throw new HttpException(401, 'Tanda tangan permintaan (X-Signature) tidak sah.');
            }
        }

        Database::update('api_clients', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$client['id']]);
        self::$client = $client;
    }

    /**
     * Catat setiap penolakan autentikasi API dengan sebabnya.
     *
     * Sebelumnya hanya satu dari empat jalur 401 yang tercatat, sehingga
     * sebuah 401 di log server tidak dapat dibedakan dari tiga sebab lain
     * dan hanya bisa ditebak. Sebab yang tidak tercatat adalah sebab yang
     * dicari dengan menebak.
     *
     * Tidak ada nilai rahasia yang ditulis: API key dipotong, tanda tangan
     * dipotong, secret tidak pernah menyentuh log.
     *
     * @param array<string,scalar> $konteks
     */
    private static function tolak(Request $request, string $sebab, array $konteks = []): void
    {
        Logger::warning('Autentikasi API ditolak', array_merge([
            'sebab'  => $sebab,
            'jalur'  => $request->method() . ' ' . $request->path(),
            'ip'     => $request->ip(),
        ], $konteks));
    }

    /**
     * Apakah alamat pemanggil termasuk dalam daftar batas IP kredensial.
     *
     * Daftar berupa CSV berisi alamat tunggal, CIDR, atau kata kunci
     * "lokal". IPv4 dan IPv6 keduanya berlaku.
     *
     * TIGA HAL YANG DULU SALAH DI SINI, dan ketiganya sama-sama muncul
     * sebagai 403 yang membingungkan:
     *
     * 1. ip2long() hanya mengerti IPv4. Klien yang datang sebagai ::1 —
     *    yaitu hampir semua klien di mesin yang sama — tidak akan pernah
     *    cocok dengan entri CIDR apa pun. Sekarang pencocokan memakai
     *    inet_pton dan pembandingan biner, jadi IPv6 ikut terlayani.
     *
     * 2. Alamat yang sama dapat muncul dalam dua bentuk. Klien yang
     *    menghubungi soket IPv6 lewat IPv4 tercatat sebagai
     *    ::ffff:192.168.0.108, bukan 192.168.0.108. Bentuk itu kini
     *    dinormalkan lebih dulu, sehingga mendaftarkan alamat IPv4 saja
     *    sudah cukup.
     *
     * 3. Localhost punya dua alamat, 127.0.0.1 dan ::1, dan keduanya
     *    menunjuk mesin yang sama. Mendaftarkan salah satunya kini
     *    menerima keduanya; "lokal" menerima seluruh rentang loopback.
     */
    private static function ipDiizinkan(string $ip, string $whitelist): bool
    {
        $ipBiner = self::keBiner($ip);

        foreach (array_map('trim', explode(',', $whitelist)) as $entry) {
            if ($entry === '') {
                continue;
            }

            if (strcasecmp($entry, 'lokal') === 0 || strcasecmp($entry, 'local') === 0) {
                if (self::loopback($ip)) {
                    return true;
                }
                continue;
            }

            // Perbandingan teks apa adanya, untuk entri yang bukan alamat.
            if ($entry === $ip) {
                return true;
            }

            if (str_contains($entry, '/')) {
                if (self::cocokCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }

            $entryBiner = self::keBiner($entry);
            if ($ipBiner !== null && $entryBiner !== null && $ipBiner === $entryBiner) {
                return true;
            }

            // 127.0.0.1 dan ::1 adalah mesin yang sama.
            if (self::loopback($ip) && self::loopback($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alamat menjadi bentuk biner yang dapat dibandingkan.
     *
     * ::ffff:a.b.c.d diturunkan menjadi 4 byte IPv4-nya, supaya satu alamat
     * tidak pernah punya dua wujud yang tidak saling cocok.
     */
    private static function keBiner(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        // Bentuk [::1] dari beberapa proksi.
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        $biner = @inet_pton($ip);
        if ($biner === false) {
            return null;
        }

        // IPv4-mapped: 80 bit nol, 16 bit satu, lalu 4 byte IPv4.
        if (strlen($biner) === 16 && str_starts_with($biner, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($biner, 12);
        }

        return $biner;
    }

    private static function loopback(string $ip): bool
    {
        $biner = self::keBiner($ip);
        if ($biner === null) {
            return false;
        }

        // ::1
        if (strlen($biner) === 16) {
            return $biner === str_repeat("\0", 15) . "\x01";
        }

        // Seluruh 127.0.0.0/8
        return $biner[0] === "\x7f";
    }

    private static function cocokCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');

        $ipBiner     = self::keBiner($ip);
        $subnetBiner = self::keBiner($subnet);

        if ($ipBiner === null || $subnetBiner === null) {
            return false;
        }

        // Keluarga harus sama: 192.168.0.0/24 tidak menjaring alamat IPv6,
        // dan sebaliknya. Membandingkan panjang berbeda hanya menghasilkan
        // kecocokan palsu.
        if (strlen($ipBiner) !== strlen($subnetBiner)) {
            return false;
        }

        $maksimal = strlen($ipBiner) * 8;
        $panjang  = ($bits === '') ? $maksimal : (int) $bits;

        if ($panjang < 0 || $panjang > $maksimal) {
            return false;
        }
        if ($panjang === 0) {
            return true;
        }

        $byteUtuh = intdiv($panjang, 8);
        $sisaBit  = $panjang % 8;

        if ($byteUtuh > 0 && !hash_equals(substr($subnetBiner, 0, $byteUtuh), substr($ipBiner, 0, $byteUtuh))) {
            return false;
        }
        if ($sisaBit === 0) {
            return true;
        }

        $topeng = chr(0xFF << (8 - $sisaBit) & 0xFF);

        return ($ipBiner[$byteUtuh] & $topeng) === ($subnetBiner[$byteUtuh] & $topeng);
    }

    /** @return array<string,mixed>|null */
    public static function client(): ?array
    {
        return self::$client;
    }

    public static function clientNama(): string
    {
        return (string) (self::$client['nama'] ?? 'api');
    }
}
