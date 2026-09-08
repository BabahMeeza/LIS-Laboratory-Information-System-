<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Response;

final class UserController extends Controller
{
    public function index(): Response
    {
        $users = Database::select(
            'SELECT id, username, nama, nip, email, role, gelar, aktif, last_login_at, created_at
             FROM users ORDER BY aktif DESC, nama'
        );

        return $this->view('users/index', ['users' => $users], 'Pengguna');
    }

    /** @param array<string,string> $params */
    public function form(array $params = []): Response
    {
        $user = null;
        if (isset($params['id'])) {
            $user = Database::selectOne('SELECT * FROM users WHERE id = ?', [(int) $params['id']]);
            if ($user === null) {
                throw new HttpException(404, 'Pengguna tidak ditemukan.');
            }
        }

        return $this->view('users/form', [
            'user'  => $user,
            'roles' => Auth::roles(),
        ], $user === null ? 'Pengguna Baru' : 'Ubah Pengguna: ' . $user['nama']);
    }

    /** @param array<string,string> $params */
    public function simpan(array $params = []): Response
    {
        $id      = isset($params['id']) ? (int) $params['id'] : 0;
        $minimal = (int) Config::get('security.password_min', 8);

        $rules = [
            'username' => 'required|max:50|regex:/^[A-Za-z0-9._-]+$/',
            'nama'     => 'required|max:100',
            'role'     => 'required|in:' . implode(',', Auth::roles()),
            'email'    => 'email|max:100',
        ];
        if ($id === 0) {
            $rules['password'] = 'required|min:' . $minimal;
        }

        $data = $this->validasi($rules, [
            'username' => 'Nama pengguna',
            'nama'     => 'Nama lengkap',
            'role'     => 'Peran',
            'password' => 'Kata sandi',
        ]);

        if ($data === null) {
            return $this->redirect($id > 0 ? "/pengguna/$id/edit" : '/pengguna/baru');
        }

        $username = $this->request->str('username');

        $bentrok = Database::selectOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);
        if ($bentrok !== null) {
            Flash::error('Nama pengguna "' . $username . '" sudah dipakai.');

            return $this->redirect($id > 0 ? "/pengguna/$id/edit" : '/pengguna/baru');
        }

        $simpan = [
            'username' => $username,
            'nama'     => $this->request->str('nama'),
            'nip'      => $this->request->str('nip') ?: null,
            'email'    => $this->request->str('email') ?: null,
            'role'     => $this->request->str('role'),
            'gelar'    => $this->request->str('gelar') ?: null,
            'aktif'    => $this->request->bool('aktif') ? 1 : 0,
        ];

        // Cegah admin terakhir menonaktifkan dirinya sendiri.
        if ($id > 0 && $id === Auth::id() && ($simpan['aktif'] === 0 || $simpan['role'] !== 'admin')) {
            $adminLain = (int) Database::scalar(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1 AND id <> ?",
                [$id]
            );
            if ($adminLain === 0) {
                Flash::error('Tidak dapat menurunkan atau menonaktifkan admin terakhir.');

                return $this->redirect('/pengguna/' . $id . '/edit');
            }
        }

        $password = $this->request->str('password');
        if ($password !== '') {
            if (mb_strlen($password) < $minimal) {
                Flash::error('Kata sandi minimal ' . $minimal . ' karakter.');

                return $this->redirect($id > 0 ? "/pengguna/$id/edit" : '/pengguna/baru');
            }
            $simpan['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        }

        if ($id > 0) {
            Database::update('users', $simpan, 'id = ?', [$id]);
            Audit::log('ubah_pengguna', 'user', (string) $id, $simpan['nama'] . ' (' . $simpan['role'] . ')');
            Flash::sukses('Data pengguna diperbarui.');
        } else {
            $id = Database::insert('users', $simpan);
            Audit::log('tambah_pengguna', 'user', (string) $id, $simpan['nama'] . ' (' . $simpan['role'] . ')');
            Flash::sukses('Pengguna baru dibuat.');
        }

        Flash::bersihkanInput();

        return $this->redirect('/pengguna');
    }

    /** @param array<string,string> $params */
    public function resetPassword(array $params): Response
    {
        $id      = (int) $params['id'];
        $minimal = (int) Config::get('security.password_min', 8);
        $baru    = $this->request->str('password_baru');

        if (mb_strlen($baru) < $minimal) {
            Flash::error('Kata sandi baru minimal ' . $minimal . ' karakter.');

            return $this->redirect('/pengguna/' . $id . '/edit');
        }

        Database::update(
            'users',
            ['password_hash' => password_hash($baru, PASSWORD_BCRYPT)],
            'id = ?',
            [$id]
        );

        Audit::log('reset_password', 'user', (string) $id, 'Kata sandi direset oleh admin');
        Flash::sukses('Kata sandi pengguna direset. Minta pengguna segera menggantinya.');

        return $this->redirect('/pengguna');
    }
}
