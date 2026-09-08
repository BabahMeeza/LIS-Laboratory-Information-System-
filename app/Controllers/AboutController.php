<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Lisensi;
use App\Core\Response;

/**
 * Halaman Tentang Aplikasi.
 *
 * Seluruh isinya dibaca dari config/lisensi.php yang bertanda tangan.
 * Pengendali ini tidak memuat satu pun nilai identitas secara harfiah:
 * menuliskannya di sini akan membuat ada dua sumber kebenaran, dan yang
 * satu bisa diubah tanpa menyentuh yang ditandatangani.
 */
final class AboutController extends Controller
{
    public function index(): Response
    {
        $lisensi = Lisensi::data();

        $sidik = '';
        $path  = BASE_PATH . '/config/lisensi.php';
        if (is_file($path)) {
            $isi   = str_replace("\r\n", "\n", (string) file_get_contents($path));
            $sidik = substr(hash('sha256', $isi), 0, 16) . '…';
        }

        $versiDb = '-';
        try {
            $versiDb = (string) Database::scalar('SELECT VERSION()');
        } catch (\Throwable) {
            $versiDb = 'tidak terbaca';
        }

        return $this->view('about', [
            'lisensi' => $lisensi,
            'sistem'  => [
                'diawasi'   => implode(', ', Lisensi::DIAWASI),
                'sidik'     => $sidik,
                'versi_lis' => defined('LIS_VERSI') ? LIS_VERSI : '-',
                'php'       => PHP_VERSION,
                'db'        => $versiDb,
                'zona'      => (string) Config::get('app.timezone', date_default_timezone_get()),
            ],
        ], 'Tentang Aplikasi');
    }
}
