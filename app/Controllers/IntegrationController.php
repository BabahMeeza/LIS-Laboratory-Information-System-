<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\KhanzaService;

/**
 * Pusat kendali integrasi SIMRS Khanza: status koneksi, penarikan order,
 * impor & pemetaan master pemeriksaan, dan pemantauan antrian pengiriman.
 */
final class IntegrationController extends Controller
{
    public function index(): Response
    {
        $statistik = Database::selectOne(
            "SELECT
                SUM(arah = 'masuk'  AND DATE(created_at) = CURDATE()) AS order_masuk_hari_ini,
                SUM(arah = 'keluar' AND jenis = 'hasil' AND status = 'sukses' AND DATE(created_at) = CURDATE()) AS hasil_terkirim_hari_ini,
                SUM(status = 'antri')  AS antri,
                SUM(status = 'gagal')  AS gagal
             FROM khanza_sync_log"
        ) ?? [];

        $terakhir = Database::select(
            'SELECT * FROM khanza_sync_log ORDER BY id DESC LIMIT 15'
        );

        $orderKhanza = Database::select(
            "SELECT o.id, o.no_order, o.no_lab, o.khanza_noorder, o.status, o.tgl_order,
                    p.nama AS nama_pasien, p.no_rm,
                    (SELECT COUNT(*) FROM khanza_sync_log k
                      WHERE k.ref_type = 'order' AND k.ref_id = CAST(o.id AS CHAR)
                        AND k.jenis = 'hasil' AND k.status = 'sukses') AS hasil_terkirim
             FROM orders o JOIN patients p ON p.id = o.patient_id
             WHERE o.khanza_noorder IS NOT NULL
             ORDER BY o.id DESC LIMIT 25"
        );

        $belumDipetakan = (int) Database::scalar(
            'SELECT COUNT(*) FROM khanza_templates WHERE test_id IS NULL'
        );

        return $this->view('integration/index', [
            'aktif'          => KhanzaService::aktif(),
            'baseUrl'        => Config::setting('khanza.base_url', (string) Config::get('khanza.base_url', '')),
            'modeOrder'      => Config::setting('khanza.mode_order', 'push'),
            'kirimSaat'      => Config::setting('khanza.kirim_hasil_saat', 'verifikasi'),
            'statistik'      => $statistik,
            'terakhir'       => $terakhir,
            'orderKhanza'    => $orderKhanza,
            'belumDipetakan' => $belumDipetakan,
        ], 'Integrasi SIMRS Khanza');
    }

    public function ujiKoneksi(): Response
    {
        $hasil = KhanzaService::ping();

        if ($hasil['ok']) {
            $info = '';
            if (is_array($hasil['data'])) {
                $info = ' Versi konektor: ' . (string) ($hasil['data']['versi'] ?? '?')
                      . ', database: ' . (string) ($hasil['data']['database'] ?? '?');
            }
            Flash::sukses($hasil['pesan'] . $info);
        } else {
            Flash::error($hasil['pesan']);
        }

        return $this->redirect('/integrasi');
    }

    public function tarikOrder(): Response
    {
        $hasil = KhanzaService::tarikOrder($this->request->int('limit', 50));
        Audit::log('tarik_order_khanza', null, null, $hasil['pesan']);

        if ($hasil['gagal'] > 0) {
            Flash::peringatan($hasil['pesan'] . ' Periksa Log Integrasi untuk detail kegagalan.');
        } else {
            Flash::sukses($hasil['pesan']);
        }

        return $this->redirect('/integrasi');
    }

    public function imporTemplate(): Response
    {
        $hasil = KhanzaService::imporTemplate();

        if ($hasil['sukses']) {
            Flash::sukses($hasil['pesan']);
            Flash::info('Periksa hasil pemetaan otomatis di menu Integrasi → Pemetaan Pemeriksaan.');
        } else {
            Flash::error($hasil['pesan']);
        }

        return $this->redirect('/integrasi/pemetaan');
    }

    /** Layar pemetaan template Khanza → pemeriksaan LIS. */
    public function pemetaan(): Response
    {
        $tampil = $this->request->str('tampil', 'belum');
        $cari   = $this->request->str('q');

        $where = ['1=1'];
        $bind  = [];

        if ($tampil === 'belum') {
            $where[] = 'kt.test_id IS NULL';
        } elseif ($tampil === 'sudah') {
            $where[] = 'kt.test_id IS NOT NULL';
        }
        if ($cari !== '') {
            $where[] = '(kt.pemeriksaan LIKE ? OR kt.nm_perawatan LIKE ? OR kt.kd_jenis_prw LIKE ?)';
            $like    = '%' . $cari . '%';
            array_push($bind, $like, $like, $like);
        }

        $sqlWhere = implode(' AND ', $where);

        $templates = Database::select(
            "SELECT kt.*, t.kode AS kode_test, t.nama AS nama_test_lis
             FROM khanza_templates kt
             LEFT JOIN tests t ON t.id = kt.test_id
             WHERE $sqlWhere
             ORDER BY kt.kd_jenis_prw, kt.id_template
             LIMIT 500",
            $bind
        );

        $tests = Database::select(
            'SELECT t.id, t.kode, t.nama, t.satuan, tc.nama AS kategori
             FROM tests t LEFT JOIN test_categories tc ON tc.id = t.category_id
             WHERE t.aktif = 1 ORDER BY tc.urut, t.urut, t.nama'
        );

        return $this->view('integration/pemetaan', [
            'templates' => $templates,
            'tests'     => $tests,
            'filter'    => compact('tampil', 'cari'),
        ], 'Pemetaan Pemeriksaan Khanza');
    }

    public function simpanPemetaan(): Response
    {
        $pemetaan = $this->request->arr('test_id');
        $jumlah   = 0;

        Database::transaction(static function () use ($pemetaan, &$jumlah): void {
            foreach ($pemetaan as $templateId => $testId) {
                $templateId = (int) $templateId;
                $testId     = (int) $testId;

                $template = Database::selectOne('SELECT * FROM khanza_templates WHERE id = ?', [$templateId]);
                if ($template === null) {
                    continue;
                }

                Database::update('khanza_templates', [
                    'test_id' => $testId > 0 ? $testId : null,
                ], 'id = ?', [$templateId]);

                // Tulis balik ke master tests agar pemetaan dipakai
                // saat order Khanza masuk maupun saat hasil dikirim.
                if ($testId > 0) {
                    Database::update('tests', [
                        'khanza_kd_jenis_prw' => $template['kd_jenis_prw'],
                        'khanza_id_template'  => (int) $template['id_template'],
                    ], 'id = ?', [$testId]);
                }

                $jumlah++;
            }
        });

        Audit::log('simpan_pemetaan_khanza', null, null, $jumlah . ' baris pemetaan');
        Flash::sukses($jumlah . ' pemetaan disimpan.');

        return $this->back('/integrasi/pemetaan');
    }

    public function log(): Response
    {
        $status = $this->request->str('status');
        $arah   = $this->request->str('arah');
        $jenis  = $this->request->str('jenis');

        $where = ['1=1'];
        $bind  = [];

        foreach (['status' => $status, 'arah' => $arah, 'jenis' => $jenis] as $kolom => $nilai) {
            if ($nilai !== '') {
                $where[] = "$kolom = ?";
                $bind[]  = $nilai;
            }
        }

        $sqlWhere = implode(' AND ', $where);

        $log = Database::select(
            "SELECT id, arah, jenis, ref_type, ref_id, endpoint, http_code, status,
                    percobaan, next_retry_at, pesan, created_at,
                    CHAR_LENGTH(COALESCE(payload,'')) AS panjang_payload
             FROM khanza_sync_log
             WHERE $sqlWhere
             ORDER BY id DESC LIMIT 200",
            $bind
        );

        return $this->view('integration/log', [
            'log'    => $log,
            'filter' => compact('status', 'arah', 'jenis'),
        ], 'Log Integrasi');
    }

    /** @param array<string,string> $params */
    public function kirimUlang(array $params): Response
    {
        $id  = (int) $params['id'];
        $row = Database::selectOne('SELECT * FROM khanza_sync_log WHERE id = ?', [$id]);

        if ($row === null) {
            throw new HttpException(404, 'Entri log tidak ditemukan.');
        }
        if ((string) $row['ref_type'] !== 'order' || $row['ref_id'] === null) {
            Flash::error('Entri ini bukan pengiriman hasil order sehingga tidak dapat dikirim ulang.');

            return $this->redirect('/integrasi/log');
        }

        $hasil = KhanzaService::kirimHasil((int) $row['ref_id']);

        if ($hasil['sukses']) {
            Database::update('khanza_sync_log', ['status' => 'sukses'], 'id = ?', [$id]);
            Flash::sukses($hasil['pesan']);
        } else {
            Flash::error($hasil['pesan']);
        }

        return $this->redirect('/integrasi/log');
    }

    public function prosesAntrian(): Response
    {
        $hasil = KhanzaService::prosesAntrian(100);

        Flash::sukses(sprintf(
            'Antrian diproses: %d entri, %d sukses, %d masih gagal.',
            $hasil['diproses'],
            $hasil['sukses'],
            $hasil['gagal']
        ));

        return $this->redirect('/integrasi');
    }
}
