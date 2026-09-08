<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\PatientService;

final class PatientController extends Controller
{
    /** @param array<string,string> $params */
    public function index(array $params = []): Response
    {
        $cari  = $this->request->str('q');
        $hal   = $this->halaman();
        $per   = $this->perHalaman();
        $mulai = ($hal - 1) * $per;

        $where  = '1=1';
        $bind   = [];

        if ($cari !== '') {
            $where = '(no_rm LIKE ? OR khanza_no_rkm_medis LIKE ? OR nama LIKE ? OR nik LIKE ?)';
            $like  = '%' . $cari . '%';
            $bind  = [$like, $like, $like, $like];
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM patients WHERE $where", $bind);

        $pasien = Database::select(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM orders o WHERE o.patient_id = p.id) AS jml_order,
                    (SELECT MAX(o.tgl_order) FROM orders o WHERE o.patient_id = p.id) AS order_terakhir
             FROM patients p
             WHERE $where
             ORDER BY p.nama
             LIMIT $per OFFSET $mulai",
            $bind
        );

        return $this->view('patients/index', [
            'pasien' => $pasien,
            'total'  => $total,
            'hal'    => $hal,
            'per'    => $per,
            'cari'   => $cari,
        ], 'Data Pasien');
    }

    /** @param array<string,string> $params */
    public function form(array $params = []): Response
    {
        $pasien = null;
        if (isset($params['id'])) {
            $pasien = Database::selectOne('SELECT * FROM patients WHERE id = ?', [(int) $params['id']]);
            if ($pasien === null) {
                throw new HttpException(404, 'Pasien tidak ditemukan.');
            }
        }

        return $this->view('patients/form', ['pasien' => $pasien], $pasien === null ? 'Pasien Baru' : 'Ubah Data Pasien');
    }

    /** @param array<string,string> $params */
    public function simpan(array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        $data = $this->validasi([
            'nama'      => 'required|max:100',
            'jk'        => 'required|in:L,P,X',
            'tgl_lahir' => 'date',
            'nik'       => 'max:20',
            'telepon'   => 'max:30',
            'email'     => 'email|max:100',
            'alamat'    => 'max:255',
        ], [
            'nama'      => 'Nama pasien',
            'jk'        => 'Jenis kelamin',
            'tgl_lahir' => 'Tanggal lahir',
        ]);

        if ($data === null) {
            return $this->redirect($id > 0 ? "/pasien/$id/edit" : '/pasien/baru');
        }

        $simpan = [
            'nama'                => $this->request->str('nama'),
            'jk'                  => $this->request->str('jk', 'X'),
            'tgl_lahir'           => $this->request->str('tgl_lahir') ?: null,
            'tempat_lahir'        => $this->request->str('tempat_lahir') ?: null,
            'nik'                 => $this->request->str('nik') ?: null,
            'alamat'              => $this->request->str('alamat') ?: null,
            'telepon'             => $this->request->str('telepon') ?: null,
            'email'               => $this->request->str('email') ?: null,
            'gol_darah'           => $this->request->str('gol_darah') ?: null,
            'khanza_no_rkm_medis' => $this->request->str('khanza_no_rkm_medis') ?: null,
        ];

        if ($id > 0) {
            Database::update('patients', $simpan, 'id = ?', [$id]);
            Audit::log('ubah_pasien', 'patient', (string) $id, $simpan['nama']);
            Flash::sukses('Data pasien diperbarui.');
        } else {
            $noRm = $this->request->str('no_rm');
            if ($noRm === '') {
                $noRm = $simpan['khanza_no_rkm_medis'] ?? PatientService::nomorRm();
            }
            if ((int) Database::scalar('SELECT COUNT(*) FROM patients WHERE no_rm = ?', [$noRm]) > 0) {
                Flash::error('Nomor rekam medis "' . $noRm . '" sudah dipakai pasien lain.');

                return $this->redirect('/pasien/baru');
            }

            $simpan['no_rm'] = $noRm;
            $id              = Database::insert('patients', $simpan);
            Audit::log('buat_pasien', 'patient', (string) $id, $simpan['nama']);
            Flash::sukses('Pasien baru tersimpan dengan nomor RM ' . $noRm . '.');
        }

        Flash::bersihkanInput();

        return $this->redirect('/pasien/' . $id);
    }

    /** @param array<string,string> $params */
    public function detail(array $params): Response
    {
        $id     = (int) $params['id'];
        $pasien = Database::selectOne('SELECT * FROM patients WHERE id = ?', [$id]);

        if ($pasien === null) {
            throw new HttpException(404, 'Pasien tidak ditemukan.');
        }

        $orders = Database::select(
            'SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS jml_item
             FROM orders o WHERE o.patient_id = ?
             ORDER BY o.tgl_order DESC LIMIT 50',
            [$id]
        );

        return $this->view('patients/detail', [
            'pasien' => $pasien,
            'orders' => $orders,
        ], 'Pasien: ' . $pasien['nama']);
    }

    /** Riwayat hasil kumulatif — berguna untuk melihat tren antar kunjungan. */
    public function riwayat(array $params): Response
    {
        $id     = (int) $params['id'];
        $pasien = Database::selectOne('SELECT * FROM patients WHERE id = ?', [$id]);

        if ($pasien === null) {
            throw new HttpException(404, 'Pasien tidak ditemukan.');
        }

        $rows = Database::select(
            'SELECT t.id AS test_id, t.kode, t.nama AS nama_test, t.satuan, t.desimal,
                    r.nilai, r.nilai_num, r.flag, r.ref_teks, r.created_at,
                    o.no_lab, o.no_order, DATE(o.tgl_order) AS tgl
             FROM results r
             JOIN orders o ON o.id = r.order_id
             JOIN tests t  ON t.id = r.test_id
             WHERE o.patient_id = ? AND r.status IN (\'verified\',\'corrected\',\'final\')
             ORDER BY t.urut, t.nama, o.tgl_order DESC
             LIMIT 500',
            [$id]
        );

        // Susun menjadi matriks pemeriksaan × tanggal.
        $matriks  = [];
        $tanggal  = [];
        foreach ($rows as $row) {
            $tgl = (string) $row['tgl'];
            $tanggal[$tgl] = true;
            $matriks[(string) $row['nama_test']]['satuan'] = $row['satuan'];
            $matriks[(string) $row['nama_test']]['ref']    = $row['ref_teks'];
            $matriks[(string) $row['nama_test']]['nilai'][$tgl] = [
                'nilai' => $row['nilai'],
                'flag'  => $row['flag'],
            ];
        }
        $tanggal = array_keys($tanggal);
        rsort($tanggal);
        $tanggal = array_slice($tanggal, 0, 10);

        return $this->view('patients/riwayat', [
            'pasien'  => $pasien,
            'matriks' => $matriks,
            'tanggal' => $tanggal,
        ], 'Riwayat Hasil: ' . $pasien['nama']);
    }

    /** Autocomplete pencarian pasien (JSON). */
    public function cari(): Response
    {
        return $this->json([
            'sukses' => true,
            'data'   => PatientService::cari($this->request->str('q'), 20),
        ]);
    }
}
