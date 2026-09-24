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
use App\Services\CriticalValueService;

/**
 * Antrean nilai kritis — layar yang menagih, bukan sekadar mencatat.
 *
 * MENGAPA LAYAR INI ADA
 *
 * Nilai kritis yang tersimpan tanpa tempat untuk dilihat sama saja dengan
 * tidak tercatat. Sebelumnya satu-satunya jejak nilai kritis adalah baris
 * pada berkas log dan tiga kolom kosong pada tabel results — keduanya
 * tidak akan pernah dibuka oleh petugas yang sedang sibuk.
 *
 * CLSI GP47 menempatkan pelaporan nilai kritis sebagai proses dengan
 * tenggat, bukan sebagai catatan. Layar ini menampilkan tenggat itu:
 * yang paling lama menunggu berada di atas, dan yang sudah lewat tenggat
 * ditandai. Selama masih ada baris di sini, ada pasien yang hasilnya
 * berbahaya dan belum diketahui klinisinya.
 */
final class CriticalController extends Controller
{
    public function index(): Response
    {
        $daftar = CriticalValueService::tertunggak(200);

        // Riwayat singkat untuk konteks — termasuk yang terlambat, karena
        // justru itulah yang perlu ditinjau saat rapat mutu.
        $terakhir = Database::select(
            "SELECT cn.id, cn.nilai, cn.satuan, cn.flag, cn.status,
                    cn.terdeteksi_at, cn.dilapor_at, cn.terlambat_menit,
                    cn.penerima_nama, cn.penerima_peran, cn.cara,
                    cn.bacaan_ulang, cn.bacaan_cocok,
                    o.no_order, p.nama AS nama_pasien, p.no_rm,
                    t.nama AS nama_test, u.nama AS pelapor
               FROM critical_notifications cn
               JOIN orders   o ON o.id = cn.order_id
               JOIN patients p ON p.id = cn.patient_id
               JOIN tests    t ON t.id = cn.test_id
          LEFT JOIN users    u ON u.id = cn.dilapor_by
              WHERE cn.status = 'terlapor'
              ORDER BY cn.dilapor_at DESC
              LIMIT 30"
        );

        return $this->view('critical/index', [
            'judul'        => 'Nilai Kritis',
            'daftar'       => $daftar,
            'terakhir'     => $terakhir,
            'wajibBaca'    => Config::settingBool('kritis.wajib_baca_ulang', true),
            'batasBawaan'  => Config::settingInt('kritis.batas_menit', 30),
        ]);
    }

    /**
     * Mencatat pelaporan satu nilai kritis.
     *
     * @param array<string,string> $params
     */
    public function lapor(array $params): Response
    {
        $id = (int) $params['id'];

        $ada = Database::selectOne(
            'SELECT id, result_id FROM critical_notifications WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($ada === null) {
            throw new HttpException(404, 'Catatan nilai kritis tidak ditemukan.');
        }

        $hasil = CriticalValueService::lapor($id, (int) Auth::id(), [
            'penerima_nama'  => $this->request->str('penerima_nama'),
            'penerima_peran' => $this->request->str('penerima_peran'),
            'cara'           => $this->request->str('cara'),
            'bacaan_ulang'   => $this->request->str('bacaan_ulang'),
            'catatan'        => $this->request->str('catatan'),
        ]);

        if (!$hasil['ok']) {
            // Penolakan read-back BUKAN kesalahan pengguna — itu justru
            // pengaman yang bekerja. Pesannya menjelaskan apa yang harus
            // dilakukan, bukan menyalahkan.
            Flash::error($hasil['pesan']);

            return $this->redirect('/nilai-kritis');
        }

        Audit::log(
            'lapor_nilai_kritis',
            'result',
            (string) $ada['result_id'],
            'Dilaporkan kepada ' . $this->request->str('penerima_nama') . '. ' . $hasil['pesan']
        );

        Flash::sukses($hasil['pesan']);

        return $this->redirect('/nilai-kritis');
    }
}
