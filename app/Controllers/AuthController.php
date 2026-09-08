<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function form(): Response
    {
        return $this->view('auth/login', [], 'Masuk');
    }

    public function masuk(): Response
    {
        $username = $this->request->str('username');
        $password = $this->request->str('password');

        if ($username === '' || $password === '') {
            Flash::error('Nama pengguna dan kata sandi wajib diisi.');

            return $this->redirect('/login');
        }

        $ip = $this->request->ip();

        if (!Auth::attempt($username, $password, $ip)) {
            $kunci = Auth::statusKunci($username, $ip);

            if ($kunci['terkunci']) {
                Flash::error(
                    'Terlalu banyak percobaan gagal. Akun ini dikunci sementara sampai '
                    . date('H:i', strtotime((string) $kunci['sampai'])) . '.'
                );
            } else {
                Flash::error('Nama pengguna atau kata sandi salah. Bila berulang, akun dikunci sementara.');
            }

            return $this->redirect('/login');
        }

        $tujuan = (string) Session::pull('_redirect_setelah_login', '/');

        return $this->redirect($tujuan === '/login' ? '/' : $tujuan);
    }

    public function keluar(): Response
    {
        Auth::logout();

        return $this->redirect('/login');
    }

    public function profil(): Response
    {
        $user = Database::selectOne('SELECT * FROM users WHERE id = ?', [Auth::id()]);

        return $this->view('auth/profil', ['user' => $user], 'Profil Saya');
    }

    public function ubahPassword(): Response
    {
        $lama  = $this->request->str('password_lama');
        $baru  = $this->request->str('password_baru');
        $ulang = $this->request->str('password_konfirmasi');

        $user = Database::selectOne('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if ($user === null || !password_verify($lama, (string) $user['password_hash'])) {
            Flash::error('Kata sandi lama tidak cocok.');

            return $this->redirect('/profil');
        }

        $minimal = (int) Config::get('security.password_min', 8);
        if (mb_strlen($baru) < $minimal) {
            Flash::error('Kata sandi baru minimal ' . $minimal . ' karakter.');

            return $this->redirect('/profil');
        }
        if ($baru !== $ulang) {
            Flash::error('Konfirmasi kata sandi tidak cocok.');

            return $this->redirect('/profil');
        }

        Database::update(
            'users',
            ['password_hash' => password_hash($baru, PASSWORD_BCRYPT)],
            'id = ?',
            [Auth::id()]
        );

        Flash::sukses('Kata sandi berhasil diperbarui.');

        return $this->redirect('/profil');
    }
}
