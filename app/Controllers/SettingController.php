<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Response;

final class SettingController extends Controller
{
    /** Kunci pengaturan yang boleh diubah lewat UI. */
    private const DIIZINKAN = [
        'app.nama_lab', 'app.nama_faskes', 'app.alamat', 'app.telepon', 'app.logo',
        'barcode.prefix', 'barcode.format', 'barcode.panjang_seq',
        'order.prefix', 'nolab.reset',
        'hasil.auto_verify', 'hasil.auto_verify_user', 'hasil.delta_check_hari', 'hasil.delta_ambang_persen',
        'khanza.aktif', 'khanza.base_url', 'khanza.api_key', 'khanza.api_secret',
        'khanza.mode_order', 'khanza.kirim_hasil_saat', 'khanza.auto_buat_pasien',
        'middleware.heartbeat_detik', 'middleware.simpan_raw_hari',
    ];

    public function index(): Response
    {
        $settings = [];
        foreach (Database::select('SELECT * FROM settings ORDER BY grup, `key`') as $row) {
            $settings[(string) $row['grup']][] = $row;
        }

        return $this->view('settings/index', ['settings' => $settings], 'Pengaturan');
    }

    public function simpan(): Response
    {
        $input   = $this->request->arr('setting');
        $diubah  = 0;

        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, self::DIIZINKAN, true)) {
                continue;
            }
            Config::putSetting($key, is_scalar($value) ? (string) $value : null);
            $diubah++;
        }

        // Checkbox tidak terkirim saat tidak dicentang — tangani eksplisit.
        foreach (['khanza.aktif', 'hasil.auto_verify', 'khanza.auto_buat_pasien'] as $key) {
            if (!array_key_exists($key, $input)) {
                Config::putSetting($key, '0');
            }
        }

        Config::flushSettings();
        Audit::log('ubah_pengaturan', null, null, $diubah . ' pengaturan diperbarui');
        Flash::sukses($diubah . ' pengaturan disimpan.');

        return $this->redirect('/pengaturan');
    }

    public function apiClients(): Response
    {
        $clients = Database::select(
            'SELECT id, nama, api_key, scopes, ip_whitelist, aktif, last_used_at, created_at
             FROM api_clients ORDER BY id'
        );

        return $this->view('settings/api', [
            'clients' => $clients,
            'secret'  => \App\Core\Session::pull('_secret_baru'),
            'ipAnda'  => $this->request->ip(),
        ], 'Kredensial API');
    }

    /**
     * Ubah Batas IP kredensial yang sudah ada.
     *
     * Sebelumnya batas IP hanya dapat diisi saat kredensial dibuat. Satu
     * salah ketik berarti kredensialnya harus dibuang dan dibuat ulang —
     * beserta secret baru yang harus disalin lagi ke setiap pemanggil.
     *
     * Setiap entri divalidasi lebih dulu. Daftar yang tidak sah tidak
     * disimpan: kesalahan ketik pada berkas ini mengunci pemanggil di
     * luar, dan gejalanya (403) tidak menunjuk ke tempat kesalahannya.
     *
     * @param array<string,string> $params
     */
    public function simpanBatasIp(array $params): Response
    {
        $id     = (int) $params['id'];
        $client = Database::selectOne('SELECT id, nama FROM api_clients WHERE id = ?', [$id]);

        if ($client === null) {
            Flash::error('Kredensial tidak ditemukan.');

            return $this->redirect('/pengaturan/api');
        }

        $mentah = trim($this->request->str('ip_whitelist'));

        if ($mentah === '') {
            Database::update('api_clients', ['ip_whitelist' => null], 'id = ?', [$id]);
            Audit::log('ubah_batas_ip', 'api_client', (string) $id, $client['nama'] . ': batas IP dikosongkan');
            Flash::sukses('Batas IP dikosongkan — kredensial "' . $client['nama'] . '" kini menerima semua alamat.');

            return $this->redirect('/pengaturan/api');
        }

        $bersih = [];
        $salah  = [];

        foreach (array_map('trim', explode(',', $mentah)) as $entri) {
            if ($entri === '') {
                continue;
            }
            if (self::entriIpSah($entri)) {
                $bersih[] = $entri;
            } else {
                $salah[] = $entri;
            }
        }

        if ($salah !== []) {
            Flash::error(
                'Bukan alamat atau CIDR yang sah: ' . implode(', ', $salah)
                . '. Tidak ada yang disimpan.'
            );

            return $this->redirect('/pengaturan/api');
        }

        if ($bersih === []) {
            Flash::error('Daftar kosong setelah dibersihkan. Tidak ada yang disimpan.');

            return $this->redirect('/pengaturan/api');
        }

        $daftar = implode(', ', $bersih);

        Database::update('api_clients', ['ip_whitelist' => $daftar], 'id = ?', [$id]);
        Audit::log('ubah_batas_ip', 'api_client', (string) $id, $client['nama'] . ': ' . $daftar);

        // Peringatan, bukan penolakan: petugas boleh saja membatasi ke alamat
        // mesin lain. Tapi bila alamatnya sendiri tidak termasuk, itu jauh
        // lebih sering salah ketik daripada niat.
        $ipAnda = $this->request->ip();
        if (!self::ujiDaftar($ipAnda, $daftar)) {
            Flash::peringatan(
                'Batas IP disimpan: ' . $daftar . '. Perhatikan bahwa alamat Anda sendiri ('
                . $ipAnda . ') tidak termasuk di dalamnya.'
            );
        } else {
            Flash::sukses('Batas IP kredensial "' . $client['nama'] . '" disimpan: ' . $daftar);
        }

        return $this->redirect('/pengaturan/api');
    }

    /** Satu entri daftar: alamat tunggal, CIDR, atau kata kunci "lokal". */
    private static function entriIpSah(string $entri): bool
    {
        if (strcasecmp($entri, 'lokal') === 0 || strcasecmp($entri, 'local') === 0) {
            return true;
        }

        if (str_contains($entri, '/')) {
            [$alamat, $bit] = array_pad(explode('/', $entri, 2), 2, '');

            if (@inet_pton($alamat) === false || $bit === '' || !ctype_digit($bit)) {
                return false;
            }

            $maksimal = str_contains($alamat, ':') ? 128 : 32;

            return (int) $bit >= 0 && (int) $bit <= $maksimal;
        }

        return @inet_pton($entri) !== false;
    }

    /** Apakah sebuah alamat lolos daftar — memakai pencocokan ApiAuth yang sama. */
    private static function ujiDaftar(string $ip, string $daftar): bool
    {
        try {
            $m = new \ReflectionMethod(\App\Core\ApiAuth::class, 'ipDiizinkan');
            $m->setAccessible(true);

            return (bool) $m->invoke(null, $ip, $daftar);
        } catch (\Throwable) {
            return true;   // jangan menakut-nakuti bila pemeriksaannya sendiri gagal
        }
    }

    public function simpanApiClient(): Response
    {
        $nama = $this->request->str('nama');
        if ($nama === '') {
            Flash::error('Nama kredensial wajib diisi.');

            return $this->redirect('/pengaturan/api');
        }

        $apiKey = 'lis_' . bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(24));

        // Secret disimpan dua bentuk:
        //  - hash bcrypt untuk memverifikasi secret yang dikirim apa adanya
        //  - terenkripsi (reversibel) karena HMAC memerlukan secret asli
        $secretEnc = null;
        try {
            $secretEnc = \App\Core\Crypto::enkripsi($secret);
        } catch (\Throwable $e) {
            Flash::peringatan(
                'Secret tersimpan, namun tidak dapat dienkripsi untuk verifikasi tanda tangan: '
                . $e->getMessage() . ' Isi security.app_key pada config/config.php, lalu buat ulang kredensial ini.'
            );
        }

        $id = Database::insert('api_clients', [
            'nama'         => $nama,
            'api_key'      => $apiKey,
            'secret_hash'  => password_hash($secret, PASSWORD_BCRYPT),
            'secret_enc'   => $secretEnc,
            'scopes'       => $this->request->str('scopes', 'instrument'),
            'ip_whitelist' => $this->request->str('ip_whitelist') ?: null,
            'aktif'        => 1,
        ]);

        Audit::log('tambah_api_client', 'api_client', (string) $id, $nama);

        // Secret hanya ditampilkan sekali.
        \App\Core\Session::put('_secret_baru', ['api_key' => $apiKey, 'secret' => $secret, 'nama' => $nama]);
        Flash::sukses('Kredensial dibuat. Salin secret sekarang — nilai ini tidak ditampilkan lagi.');

        return $this->redirect('/pengaturan/api');
    }

    /** @param array<string,string> $params */
    public function hapusApiClient(array $params): Response
    {
        $id = (int) $params['id'];

        Database::update('api_clients', ['aktif' => 0], 'id = ?', [$id]);
        Audit::log('nonaktifkan_api_client', 'api_client', (string) $id, 'Dinonaktifkan');
        Flash::sukses('Kredensial dinonaktifkan.');

        return $this->redirect('/pengaturan/api');
    }

    public function audit(): Response
    {
        $aksi = $this->request->str('aksi');
        $cari = $this->request->str('q');
        $dari = $this->request->str('dari', date('Y-m-d', strtotime('-7 days')));

        $where = ['DATE(a.created_at) >= ?'];
        $bind  = [$dari];

        if ($aksi !== '') {
            $where[] = 'a.aksi = ?';
            $bind[]  = $aksi;
        }
        if ($cari !== '') {
            $where[] = '(a.actor LIKE ? OR a.deskripsi LIKE ? OR a.ref_id LIKE ?)';
            $like    = '%' . $cari . '%';
            array_push($bind, $like, $like, $like);
        }

        $sqlWhere = implode(' AND ', $where);

        $log = Database::select(
            "SELECT a.* FROM audit_logs a WHERE $sqlWhere ORDER BY a.id DESC LIMIT 300",
            $bind
        );

        $daftarAksi = array_column(
            Database::select('SELECT DISTINCT aksi FROM audit_logs ORDER BY aksi'),
            'aksi'
        );

        return $this->view('settings/audit', [
            'log'        => $log,
            'daftarAksi' => $daftarAksi,
            'filter'     => compact('aksi', 'cari', 'dari'),
        ], 'Jejak Audit');
    }
}
