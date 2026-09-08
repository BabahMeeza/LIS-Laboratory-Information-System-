<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Services\KhanzaService;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $hariIni = date('Y-m-d');

        $ringkasan = Database::selectOne(
            'SELECT
                COUNT(*)                                        AS total_order,
                SUM(prioritas = \'cito\')                       AS cito,
                SUM(status IN (\'ordered\',\'collected\'))      AS menunggu_sampel,
                SUM(status = \'received\')                      AS sampel_diterima,
                SUM(status IN (\'in_progress\',\'resulted\'))   AS dikerjakan,
                SUM(status IN (\'verified\',\'released\'))      AS selesai
             FROM orders
             WHERE DATE(tgl_order) = ? AND status <> \'cancelled\'',
            [$hariIni]
        ) ?? [];

        $nilaiKritis = Database::select(
            'SELECT r.id, r.nilai, r.flag, r.created_at, r.kritis_dilapor_at,
                    t.nama AS nama_test, t.satuan,
                    o.id AS order_id, o.no_order, o.no_lab, o.prioritas,
                    p.nama AS nama_pasien, p.no_rm
             FROM results r
             JOIN tests t   ON t.id = r.test_id
             JOIN orders o  ON o.id = r.order_id
             JOIN patients p ON p.id = o.patient_id
             WHERE r.is_kritis = 1 AND r.kritis_dilapor_at IS NULL
               AND r.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
             ORDER BY r.created_at DESC
             LIMIT 15'
        );

        $menungguVerifikasi = Database::select(
            'SELECT o.id, o.no_order, o.no_lab, o.prioritas, o.tgl_order, o.asal,
                    p.nama AS nama_pasien, p.no_rm,
                    COUNT(r.id) AS jml_hasil,
                    SUM(r.flag IN (\'L\',\'H\',\'LL\',\'HH\',\'A\')) AS jml_abnormal,
                    TIMESTAMPDIFF(MINUTE, o.tgl_order, NOW()) AS umur_menit
             FROM orders o
             JOIN patients p ON p.id = o.patient_id
             JOIN results r  ON r.order_id = o.id
             WHERE o.status = \'resulted\'
             GROUP BY o.id, o.no_order, o.no_lab, o.prioritas, o.tgl_order, o.asal, p.nama, p.no_rm
             ORDER BY (o.prioritas = \'cito\') DESC, o.tgl_order ASC
             LIMIT 15'
        );

        $alat = Database::select(
            'SELECT i.*,
                    (SELECT COUNT(*) FROM instrument_messages m
                      WHERE m.instrument_id = i.id AND DATE(m.created_at) = CURDATE()) AS pesan_hari_ini
             FROM instruments i
             WHERE i.aktif = 1
             ORDER BY i.nama'
        );

        $antrianKhanza = (int) Database::scalar(
            'SELECT COUNT(*) FROM khanza_sync_log WHERE status = \'antri\' AND arah = \'keluar\''
        );

        $menggantung = (int) Database::scalar(
            'SELECT COUNT(*) FROM orphan_results WHERE status = \'menunggu\''
        );

        // Volume 7 hari terakhir untuk grafik ringkas.
        $tren = Database::select(
            'SELECT DATE(tgl_order) AS tgl, COUNT(*) AS jml
             FROM orders
             WHERE tgl_order >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status <> \'cancelled\'
             GROUP BY DATE(tgl_order)
             ORDER BY tgl'
        );

        return $this->view('dashboard/index', [
            'ringkasan'          => $ringkasan,
            'nilaiKritis'        => $nilaiKritis,
            'menungguVerifikasi' => $menungguVerifikasi,
            'alat'               => $alat,
            'antrianKhanza'      => $antrianKhanza,
            'menggantung'        => $menggantung,
            'tren'               => $tren,
            'khanzaAktif'        => KhanzaService::aktif(),
        ], 'Dashboard');
    }

    /** Endpoint JSON untuk penyegaran berkala di dashboard. */
    public function statistik(): Response
    {
        return $this->json([
            'sukses' => true,
            'data'   => [
                'menunggu_verifikasi' => (int) Database::scalar("SELECT COUNT(*) FROM orders WHERE status = 'resulted'"),
                'nilai_kritis'        => (int) Database::scalar('SELECT COUNT(*) FROM results WHERE is_kritis = 1 AND kritis_dilapor_at IS NULL'),
                'menggantung'         => (int) Database::scalar("SELECT COUNT(*) FROM orphan_results WHERE status = 'menunggu'"),
                'antrian_khanza'      => (int) Database::scalar("SELECT COUNT(*) FROM khanza_sync_log WHERE status = 'antri'"),
                'alat_online'         => (int) Database::scalar("SELECT COUNT(*) FROM instruments WHERE aktif = 1 AND status_koneksi = 'online'"),
                'waktu'               => date('c'),
            ],
        ]);
    }
}
