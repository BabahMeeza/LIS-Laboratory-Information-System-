<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Autentikasi pengguna dan kontrol akses berbasis peran (RBAC).
 *
 * Peran:
 *   admin       — akses penuh, termasuk pengaturan & pengguna
 *   manajer     — laporan, monitoring, tanpa ubah master
 *   verifikator — memverifikasi & merilis hasil (dokter PK)
 *   analis      — entri hasil, worklist, alat
 *   sampling    — pendaftaran order, pengambilan & penerimaan spesimen
 *   viewer      — hanya baca
 */
final class Auth
{
    private const SESSION_KEY = '_user';

    /**
     * Peta izin per peran. Kunci izin memakai format "modul.aksi".
     *
     * @var array<string,array<int,string>>
     */
    private const PERMISSIONS = [
        'admin' => ['*'],

        'manajer' => [
            'dashboard.lihat', 'order.lihat', 'spesimen.lihat', 'worklist.lihat',
            'hasil.lihat', 'laporan.lihat', 'laporan.ekspor', 'alat.lihat',
            'qc.lihat', 'integrasi.lihat', 'master.lihat', 'pasien.lihat',
        ],

        'verifikator' => [
            'dashboard.lihat', 'order.lihat', 'order.buat', 'spesimen.lihat',
            'worklist.lihat', 'hasil.lihat', 'hasil.entri', 'hasil.verifikasi',
            'hasil.rilis', 'hasil.koreksi', 'hasil.batal_verifikasi',
            'laporan.lihat', 'laporan.ekspor', 'laporan.cetak',
            'alat.lihat', 'qc.lihat', 'qc.entri', 'master.lihat',
            'pasien.lihat', 'integrasi.lihat', 'kritis.lapor',
        ],

        'analis' => [
            'dashboard.lihat', 'order.lihat', 'order.buat',
            'spesimen.lihat', 'spesimen.ambil', 'spesimen.terima', 'spesimen.tolak',
            'worklist.lihat', 'hasil.lihat', 'hasil.entri',
            'alat.lihat', 'alat.kelola', 'alat.pemetaan',
            'qc.lihat', 'qc.entri', 'laporan.lihat', 'laporan.cetak',
            'master.lihat', 'pasien.lihat', 'pasien.kelola',
            'integrasi.lihat', 'kritis.lapor',
        ],

        'sampling' => [
            'dashboard.lihat', 'order.lihat', 'order.buat', 'order.ubah',
            'spesimen.lihat', 'spesimen.ambil', 'spesimen.terima', 'spesimen.tolak',
            'spesimen.cetak_label', 'pasien.lihat', 'pasien.kelola',
            'worklist.lihat', 'hasil.lihat', 'laporan.cetak',
        ],

        'viewer' => [
            'dashboard.lihat', 'order.lihat', 'spesimen.lihat',
            'worklist.lihat', 'hasil.lihat', 'laporan.lihat', 'pasien.lihat',
        ],
    ];

    /** @var array<string,mixed>|null */
    private static ?array $cached = null;

    /**
     * Pembatasan percobaan login dicatat di database (bukan session),
     * sehingga tidak dapat direset dengan menghapus cookie peramban.
     *
     * @return array{terkunci:bool,sampai:?string}
     */
    public static function statusKunci(string $username, string $ip): array
    {
        $row = Database::selectOne(
            'SELECT gagal, locked_until FROM login_attempts WHERE username = ? AND ip = ? LIMIT 1',
            [strtolower($username), $ip]
        );

        if ($row === null || $row['locked_until'] === null) {
            return ['terkunci' => false, 'sampai' => null];
        }

        $sampai = strtotime((string) $row['locked_until']);
        if ($sampai === false || $sampai <= time()) {
            return ['terkunci' => false, 'sampai' => null];
        }

        return ['terkunci' => true, 'sampai' => (string) $row['locked_until']];
    }

    private static function catatGagal(string $username, string $ip): void
    {
        $maks  = max(1, (int) Config::get('security.max_login_gagal', 5));
        $menit = max(1, (int) Config::get('security.lockout_menit', 15));

        Database::execute(
            'INSERT INTO login_attempts (username, ip, gagal) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE gagal = gagal + 1',
            [strtolower($username), $ip]
        );

        $gagal = (int) Database::scalar(
            'SELECT gagal FROM login_attempts WHERE username = ? AND ip = ?',
            [strtolower($username), $ip]
        );

        if ($gagal >= $maks) {
            Database::execute(
                'UPDATE login_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), gagal = 0
                 WHERE username = ? AND ip = ?',
                [$menit, strtolower($username), $ip]
            );
            Logger::warning('Akun dikunci sementara karena percobaan login gagal', [
                'username' => $username,
                'ip'       => $ip,
                'menit'    => $menit,
            ]);
        }
    }

    private static function bersihkanGagal(string $username, string $ip): void
    {
        Database::execute(
            'DELETE FROM login_attempts WHERE username = ? AND ip = ?',
            [strtolower($username), $ip]
        );
    }

    public static function attempt(string $username, string $password, string $ip = ''): bool
    {
        $ip = $ip === '' ? '0.0.0.0' : $ip;

        if (self::statusKunci($username, $ip)['terkunci']) {
            return false;
        }

        $user = Database::selectOne(
            'SELECT * FROM users WHERE username = ? AND aktif = 1 LIMIT 1',
            [$username]
        );

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            self::catatGagal($username, $ip);

            return false;
        }

        // Perbarui hash bila parameter bcrypt berubah.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
            Database::update(
                'users',
                ['password_hash' => password_hash($password, PASSWORD_BCRYPT)],
                'id = ?',
                [$user['id']]
            );
        }

        self::bersihkanGagal($username, $ip);
        session_regenerate_id(true);

        Session::put(self::SESSION_KEY, [
            'id'       => (int) $user['id'],
            'username' => $user['username'],
            'nama'     => $user['nama'],
            'nip'      => $user['nip'],
            'role'     => $user['role'],
            'gelar'    => $user['gelar'],
        ]);
        self::$cached = null;

        Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        Audit::log('login', 'user', (string) $user['id'], 'Login berhasil', null, null, $ip);

        return true;
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('logout', 'user', (string) self::id(), 'Logout');
        }
        Session::destroy();
        self::$cached = null;
    }

    public static function check(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $user = Session::get(self::SESSION_KEY);

        return self::$cached = is_array($user) ? $user : null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function nama(): string
    {
        $user = self::user();

        return $user === null ? 'system' : (string) $user['nama'];
    }

    public static function role(): string
    {
        $user = self::user();

        return $user === null ? '' : (string) $user['role'];
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        if ($role === '') {
            return false;
        }

        $granted = self::PERMISSIONS[$role] ?? [];
        if (in_array('*', $granted, true)) {
            return true;
        }

        if (in_array($permission, $granted, true)) {
            return true;
        }

        // Izin wildcard per modul, mis. "hasil.*"
        $modul = explode('.', $permission)[0];

        return in_array($modul . '.*', $granted, true);
    }

    /** @param array<int,string> $permissions */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    public static function roles(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
