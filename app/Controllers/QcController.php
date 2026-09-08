<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Response;

/**
 * Kontrol mutu internal: bahan kontrol (lot), entri hasil QC,
 * perhitungan z-score, dan evaluasi aturan Westgard.
 */
final class QcController extends Controller
{
    public function index(): Response
    {
        $lots = Database::select(
            "SELECT l.*, t.nama AS nama_test, t.satuan, i.nama AS nama_alat,
                    (SELECT COUNT(*) FROM qc_results q WHERE q.qc_lot_id = l.id) AS jml_data,
                    (SELECT q.status FROM qc_results q WHERE q.qc_lot_id = l.id ORDER BY q.tgl_uji DESC LIMIT 1) AS status_terakhir,
                    (SELECT q.tgl_uji FROM qc_results q WHERE q.qc_lot_id = l.id ORDER BY q.tgl_uji DESC LIMIT 1) AS uji_terakhir
             FROM qc_lots l
             JOIN tests t ON t.id = l.test_id
             LEFT JOIN instruments i ON i.id = l.instrument_id
             WHERE l.aktif = 1
             ORDER BY t.nama, l.level"
        );

        return $this->view('qc/index', ['lots' => $lots], 'Kontrol Mutu');
    }

    /** @param array<string,string> $params */
    public function lot(array $params): Response
    {
        $id  = (int) $params['id'];
        $lot = Database::selectOne(
            'SELECT l.*, t.nama AS nama_test, t.satuan, t.desimal, i.nama AS nama_alat
             FROM qc_lots l JOIN tests t ON t.id = l.test_id
             LEFT JOIN instruments i ON i.id = l.instrument_id
             WHERE l.id = ?',
            [$id]
        );

        if ($lot === null) {
            throw new HttpException(404, 'Lot QC tidak ditemukan.');
        }

        $hasil = Database::select(
            'SELECT q.*, u.nama AS oleh FROM qc_results q
             LEFT JOIN users u ON u.id = q.user_id
             WHERE q.qc_lot_id = ? ORDER BY q.tgl_uji DESC LIMIT 60',
            [$id]
        );

        // Statistik periode berjalan.
        $statistik = Database::selectOne(
            'SELECT COUNT(*) AS n, AVG(nilai) AS mean_aktual, STDDEV_SAMP(nilai) AS sd_aktual
             FROM qc_results WHERE qc_lot_id = ? AND tgl_uji >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            [$id]
        ) ?? [];

        $cv = null;
        if (!empty($statistik['mean_aktual']) && (float) $statistik['mean_aktual'] != 0.0) {
            $cv = ((float) $statistik['sd_aktual'] / (float) $statistik['mean_aktual']) * 100;
        }

        return $this->view('qc/lot', [
            'lot'       => $lot,
            'hasil'     => array_reverse($hasil),  // urut kronologis untuk grafik
            'tabel'     => $hasil,
            'statistik' => $statistik,
            'cv'        => $cv,
        ], 'QC — ' . $lot['nama_test'] . ' Level ' . $lot['level']);
    }

    /** @param array<string,string> $params */
    public function entri(array $params): Response
    {
        $lotId = (int) $params['id'];
        $lot   = Database::selectOne('SELECT * FROM qc_lots WHERE id = ?', [$lotId]);

        if ($lot === null) {
            throw new HttpException(404, 'Lot QC tidak ditemukan.');
        }

        $nilai = $this->request->float('nilai');
        if ($nilai === null) {
            Flash::error('Nilai QC wajib diisi.');

            return $this->redirect('/qc/lot/' . $lotId);
        }

        $mean = (float) $lot['mean'];
        $sd   = (float) $lot['sd'];

        if ($sd <= 0) {
            Flash::error('SD pada lot ini bernilai nol — perbaiki data lot terlebih dahulu.');

            return $this->redirect('/qc/lot/' . $lotId);
        }

        $z = ($nilai - $mean) / $sd;

        // Ambil 9 data terakhir untuk evaluasi aturan multi-titik.
        $sebelumnya = array_map(
            static fn ($r) => (float) $r['z_score'],
            Database::select(
                'SELECT z_score FROM qc_results WHERE qc_lot_id = ? ORDER BY tgl_uji DESC LIMIT 9',
                [$lotId]
            )
        );

        [$pelanggaran, $status] = $this->westgard($z, $sebelumnya);

        Database::insert('qc_results', [
            'qc_lot_id' => $lotId,
            'nilai'     => $nilai,
            'z_score'   => round($z, 4),
            'westgard'  => $pelanggaran === [] ? null : implode(', ', $pelanggaran),
            'status'    => $status,
            'tgl_uji'   => $this->request->str('tgl_uji') ?: date('Y-m-d H:i:s'),
            'user_id'   => Auth::id(),
            'catatan'   => $this->request->str('catatan') ?: null,
            'tindakan'  => $this->request->str('tindakan') ?: null,
        ]);

        Audit::log('entri_qc', 'qc_lot', (string) $lotId, 'Nilai ' . $nilai . ' (z=' . round($z, 2) . ')');

        if ($status === 'out') {
            Flash::error(
                'QC DI LUAR KENDALI — pelanggaran: ' . implode(', ', $pelanggaran) . '. '
                . 'Hentikan pemeriksaan pasien untuk parameter ini, lakukan tindakan perbaikan, dan catat tindakannya.'
            );
        } elseif ($status === 'warning') {
            Flash::peringatan('QC masuk zona peringatan (' . implode(', ', $pelanggaran) . '). Amati kecenderungan pada run berikutnya.');
        } else {
            Flash::sukses('Hasil QC tercatat, dalam kendali (z = ' . number_format($z, 2) . ').');
        }

        return $this->redirect('/qc/lot/' . $lotId);
    }

    public function formLot(): Response
    {
        return $this->view('qc/lot_form', [
            'tests' => Database::select('SELECT id, kode, nama, satuan FROM tests WHERE aktif = 1 AND tipe_hasil = \'numerik\' ORDER BY nama'),
            'alat'  => Database::select('SELECT id, nama FROM instruments WHERE aktif = 1 ORDER BY nama'),
        ], 'Bahan Kontrol Baru');
    }

    public function simpanLot(): Response
    {
        $data = $this->validasi([
            'test_id'    => 'required|integer|exists:tests',
            'nama_bahan' => 'required|max:100',
            'lot'        => 'required|max:50',
            'level'      => 'required|in:1,2,3',
            'mean'       => 'required|numeric',
            'sd'         => 'required|numeric|min_num:0.0000001',
        ], [
            'test_id'    => 'Pemeriksaan',
            'nama_bahan' => 'Nama bahan kontrol',
            'mean'       => 'Nilai target (mean)',
            'sd'         => 'Simpangan baku (SD)',
        ]);

        if ($data === null) {
            return $this->redirect('/qc/lot-baru');
        }

        $id = Database::insert('qc_lots', [
            'instrument_id'  => $this->request->int('instrument_id') ?: null,
            'test_id'        => $this->request->int('test_id'),
            'nama_bahan'     => $this->request->str('nama_bahan'),
            'lot'            => $this->request->str('lot'),
            'level'          => $this->request->str('level', '1'),
            'mean'           => (float) $this->request->float('mean', 0.0),
            'sd'             => (float) $this->request->float('sd', 1.0),
            'cv_target'      => $this->request->float('cv_target'),
            'tgl_mulai'      => $this->request->str('tgl_mulai') ?: null,
            'tgl_kadaluarsa' => $this->request->str('tgl_kadaluarsa') ?: null,
            'aktif'          => 1,
        ]);

        Audit::log('tambah_qc_lot', 'qc_lot', (string) $id, $this->request->str('nama_bahan'));
        Flash::sukses('Bahan kontrol tersimpan.');
        Flash::bersihkanInput();

        return $this->redirect('/qc/lot/' . $id);
    }

    /**
     * Evaluasi aturan Westgard yang lazim dipakai laboratorium klinik.
     *
     * @param  array<int,float> $sebelumnya z-score terbaru lebih dulu
     * @return array{0:array<int,string>,1:string}
     */
    private function westgard(float $z, array $sebelumnya): array
    {
        $pelanggaran = [];
        $deret       = array_merge([$z], $sebelumnya);

        // 1-3s : satu titik di luar ±3SD → tolak
        if (abs($z) > 3) {
            $pelanggaran[] = '1-3s';
        }

        // 1-2s : peringatan
        if (abs($z) > 2 && abs($z) <= 3) {
            $pelanggaran[] = '1-2s';
        }

        // 2-2s : dua titik berturut-turut di sisi sama melewati 2SD
        if (count($deret) >= 2
            && abs($deret[0]) > 2 && abs($deret[1]) > 2
            && ($deret[0] > 0) === ($deret[1] > 0)) {
            $pelanggaran[] = '2-2s';
        }

        // R-4s : selisih dua titik berurutan melebihi 4SD
        if (count($deret) >= 2 && abs($deret[0] - $deret[1]) > 4) {
            $pelanggaran[] = 'R-4s';
        }

        // 4-1s : empat titik berturut-turut di sisi sama melewati 1SD
        if (count($deret) >= 4) {
            $empat = array_slice($deret, 0, 4);
            $samaSisi = true;
            foreach ($empat as $nilai) {
                if (abs($nilai) <= 1 || ($nilai > 0) !== ($empat[0] > 0)) {
                    $samaSisi = false;
                    break;
                }
            }
            if ($samaSisi) {
                $pelanggaran[] = '4-1s';
            }
        }

        // 10x : sepuluh titik berturut-turut di sisi mean yang sama
        if (count($deret) >= 10) {
            $sepuluh  = array_slice($deret, 0, 10);
            $samaSisi = true;
            foreach ($sepuluh as $nilai) {
                if (($nilai > 0) !== ($sepuluh[0] > 0)) {
                    $samaSisi = false;
                    break;
                }
            }
            if ($samaSisi) {
                $pelanggaran[] = '10x';
            }
        }

        $penolak = array_intersect($pelanggaran, ['1-3s', '2-2s', 'R-4s', '4-1s', '10x']);

        $status = match (true) {
            $penolak !== []     => 'out',
            $pelanggaran !== [] => 'warning',
            default             => 'in',
        };

        return [$pelanggaran, $status];
    }
}
