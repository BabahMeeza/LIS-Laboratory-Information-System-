<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Barcode;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Helper;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;
use App\Services\OrderService;

/**
 * Laporan: lembar hasil pasien, rekap harian, TAT, produktivitas,
 * dan register nilai kritis.
 */
final class ReportController extends Controller
{
    public function index(): Response
    {
        $dari   = $this->request->str('dari', date('Y-m-01'));
        $sampai = $this->request->str('sampai', date('Y-m-d'));

        $rekap = Database::selectOne(
            "SELECT COUNT(*) AS total_order,
                    SUM(status = 'released')  AS dirilis,
                    SUM(status = 'cancelled') AS dibatalkan,
                    SUM(prioritas = 'cito')   AS cito,
                    SUM(asal = 'ralan')       AS ralan,
                    SUM(asal = 'ranap')       AS ranap,
                    SUM(asal = 'igd')         AS igd,
                    SUM(asal = 'luar')        AS luar,
                    COALESCE(SUM(total_harga),0) AS total_biaya
             FROM orders WHERE DATE(tgl_order) BETWEEN ? AND ?",
            [$dari, $sampai]
        ) ?? [];

        $perKategori = Database::select(
            "SELECT tc.nama AS kategori, COUNT(oi.id) AS jml,
                    COALESCE(SUM(oi.harga),0) AS biaya
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN tests t  ON t.id = oi.test_id
             LEFT JOIN test_categories tc ON tc.id = t.category_id
             WHERE DATE(o.tgl_order) BETWEEN ? AND ? AND oi.status <> 'cancelled'
             GROUP BY tc.nama ORDER BY jml DESC",
            [$dari, $sampai]
        );

        $terbanyak = Database::select(
            "SELECT t.kode, t.nama, COUNT(oi.id) AS jml
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN tests t  ON t.id = oi.test_id
             WHERE DATE(o.tgl_order) BETWEEN ? AND ? AND oi.status <> 'cancelled'
             GROUP BY t.id, t.kode, t.nama ORDER BY jml DESC LIMIT 20",
            [$dari, $sampai]
        );

        $penolakan = Database::select(
            "SELECT s.kondisi, s.alasan_tolak, COUNT(*) AS jml
             FROM specimens s JOIN orders o ON o.id = s.order_id
             WHERE s.status = 'rejected' AND DATE(o.tgl_order) BETWEEN ? AND ?
             GROUP BY s.kondisi, s.alasan_tolak ORDER BY jml DESC LIMIT 20",
            [$dari, $sampai]
        );

        return $this->view('reports/index', [
            'rekap'       => $rekap,
            'perKategori' => $perKategori,
            'terbanyak'   => $terbanyak,
            'penolakan'   => $penolakan,
            'dari'        => $dari,
            'sampai'      => $sampai,
        ], 'Laporan');
    }

    /**
     * Lembar hasil pasien — siap cetak (A4/A5).
     *
     * @param array<string,string> $params
     */
    public function lembarHasil(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $order   = OrderService::detail($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }

        $items = OrderService::hasilOrder($orderId);

        // Hanya cetak hasil yang sudah diverifikasi, kecuali diminta draft.
        $draft = $this->request->bool('draft');
        if (!$draft) {
            $items = array_values(array_filter(
                $items,
                static fn ($i) => $i['result_id'] !== null
                    && in_array((string) $i['status_hasil'], ['verified', 'corrected'], true)
            ));
        }

        if ($items === []) {
            throw new HttpException(404, 'Belum ada hasil terverifikasi pada order ini. Tambahkan ?draft=1 untuk mencetak draft.');
        }

        // Kelompokkan per kategori.
        $perKategori = [];
        foreach ($items as $item) {
            $perKategori[(string) ($item['kategori'] ?? 'Lain-lain')][] = $item;
        }

        $verifikator = Database::selectOne(
            'SELECT u.nama, u.gelar, u.nip FROM results r
             JOIN users u ON u.id = r.verified_by
             WHERE r.order_id = ? AND r.verified_by IS NOT NULL
             ORDER BY r.verified_at DESC LIMIT 1',
            [$orderId]
        );

        $barcodeUtama = Database::scalar(
            'SELECT barcode FROM specimens WHERE order_id = ? ORDER BY id LIMIT 1',
            [$orderId]
        );

        return Response::make(View::capture('reports/lembar_hasil', [
            'order'        => $order,
            'perKategori'  => $perKategori,
            'verifikator'  => $verifikator,
            'draft'        => $draft,
            'barcodeSvg'   => $barcodeUtama === null ? '' : Barcode::svg((string) $barcodeUtama, 34, 1.3, true),
            'identitas'    => $this->identitasFaskes(),
            'tat'          => OrderService::tatMenit($orderId),
        ]));
    }

    /** Analisis Turn Around Time — indikator mutu utama laboratorium. */
    public function tat(): Response
    {
        $dari   = $this->request->str('dari', date('Y-m-01'));
        $sampai = $this->request->str('sampai', date('Y-m-d'));

        $ringkasan = Database::selectOne(
            "SELECT COUNT(*) AS jml,
                    AVG(TIMESTAMPDIFF(MINUTE, tgl_order, tgl_selesai)) AS rata2,
                    MIN(TIMESTAMPDIFF(MINUTE, tgl_order, tgl_selesai)) AS tercepat,
                    MAX(TIMESTAMPDIFF(MINUTE, tgl_order, tgl_selesai)) AS terlama
             FROM orders
             WHERE status = 'released' AND tgl_selesai IS NOT NULL
               AND DATE(tgl_order) BETWEEN ? AND ?",
            [$dari, $sampai]
        ) ?? [];

        $perPrioritas = Database::select(
            "SELECT prioritas, COUNT(*) AS jml,
                    AVG(TIMESTAMPDIFF(MINUTE, tgl_order, tgl_selesai)) AS rata2
             FROM orders
             WHERE status = 'released' AND tgl_selesai IS NOT NULL
               AND DATE(tgl_order) BETWEEN ? AND ?
             GROUP BY prioritas",
            [$dari, $sampai]
        );

        // Kepatuhan terhadap target TAT per pemeriksaan.
        $perTest = Database::select(
            "SELECT t.kode, t.nama, t.tat_menit AS target,
                    COUNT(r.id) AS jml,
                    AVG(TIMESTAMPDIFF(MINUTE, o.tgl_order, r.verified_at)) AS rata2,
                    SUM(TIMESTAMPDIFF(MINUTE, o.tgl_order, r.verified_at) <= t.tat_menit) AS tepat_waktu
             FROM results r
             JOIN orders o ON o.id = r.order_id
             JOIN tests t  ON t.id = r.test_id
             WHERE r.verified_at IS NOT NULL AND DATE(o.tgl_order) BETWEEN ? AND ?
             GROUP BY t.id, t.kode, t.nama, t.tat_menit
             HAVING jml > 0
             ORDER BY (tepat_waktu / jml) ASC
             LIMIT 40",
            [$dari, $sampai]
        );

        return $this->view('reports/tat', [
            'ringkasan'    => $ringkasan,
            'perPrioritas' => $perPrioritas,
            'perTest'      => $perTest,
            'dari'         => $dari,
            'sampai'       => $sampai,
        ], 'Analisis TAT');
    }

    public function produktivitas(): Response
    {
        $dari   = $this->request->str('dari', date('Y-m-01'));
        $sampai = $this->request->str('sampai', date('Y-m-d'));

        $perPetugas = Database::select(
            "SELECT u.nama, u.role,
                    SUM(r.entered_by = u.id)  AS diinput,
                    SUM(r.verified_by = u.id) AS diverifikasi
             FROM users u
             LEFT JOIN results r ON (r.entered_by = u.id OR r.verified_by = u.id)
                 AND DATE(r.created_at) BETWEEN ? AND ?
             WHERE u.aktif = 1
             GROUP BY u.id, u.nama, u.role
             HAVING diinput > 0 OR diverifikasi > 0
             ORDER BY (COALESCE(diinput,0) + COALESCE(diverifikasi,0)) DESC",
            [$dari, $sampai]
        );

        $perAlat = Database::select(
            "SELECT i.nama, COUNT(r.id) AS jml_hasil,
                    (SELECT COUNT(*) FROM instrument_messages m
                      WHERE m.instrument_id = i.id AND DATE(m.created_at) BETWEEN ? AND ?) AS jml_pesan
             FROM instruments i
             LEFT JOIN results r ON r.instrument_id = i.id AND DATE(r.created_at) BETWEEN ? AND ?
             GROUP BY i.id, i.nama ORDER BY jml_hasil DESC",
            [$dari, $sampai, $dari, $sampai]
        );

        $manualVsAlat = Database::selectOne(
            "SELECT SUM(is_manual = 1) AS manual, SUM(is_manual = 0) AS dari_alat
             FROM results WHERE DATE(created_at) BETWEEN ? AND ?",
            [$dari, $sampai]
        ) ?? [];

        return $this->view('reports/produktivitas', [
            'perPetugas'   => $perPetugas,
            'perAlat'      => $perAlat,
            'manualVsAlat' => $manualVsAlat,
            'dari'         => $dari,
            'sampai'       => $sampai,
        ], 'Produktivitas');
    }

    /** Register nilai kritis — dokumen wajib akreditasi. */
    public function nilaiKritis(): Response
    {
        $dari   = $this->request->str('dari', date('Y-m-01'));
        $sampai = $this->request->str('sampai', date('Y-m-d'));

        $rows = Database::select(
            "SELECT r.id, r.nilai, r.satuan, r.flag, r.ref_teks, r.created_at,
                    r.kritis_dilapor_ke, r.kritis_dilapor_at,
                    TIMESTAMPDIFF(MINUTE, r.created_at, r.kritis_dilapor_at) AS menit_lapor,
                    t.nama AS nama_test,
                    o.no_order, o.no_lab, o.id AS order_id, o.dokter_perujuk, o.asal,
                    p.nama AS nama_pasien, p.no_rm,
                    u.nama AS pelapor
             FROM results r
             JOIN tests t   ON t.id = r.test_id
             JOIN orders o  ON o.id = r.order_id
             JOIN patients p ON p.id = o.patient_id
             LEFT JOIN users u ON u.id = r.kritis_dilapor_by
             WHERE r.is_kritis = 1 AND DATE(r.created_at) BETWEEN ? AND ?
             ORDER BY r.created_at DESC
             LIMIT 500",
            [$dari, $sampai]
        );

        $ringkasan = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(kritis_dilapor_at IS NOT NULL) AS dilaporkan,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, kritis_dilapor_at)) AS rata2_menit
             FROM results WHERE is_kritis = 1 AND DATE(created_at) BETWEEN ? AND ?",
            [$dari, $sampai]
        ) ?? [];

        return $this->view('reports/nilai_kritis', [
            'rows'      => $rows,
            'ringkasan' => $ringkasan,
            'dari'      => $dari,
            'sampai'    => $sampai,
        ], 'Register Nilai Kritis');
    }

    /** Ekspor CSV hasil pemeriksaan untuk pengolahan lanjutan. */
    public function ekspor(): Response
    {
        $dari   = $this->request->str('dari', date('Y-m-01'));
        $sampai = $this->request->str('sampai', date('Y-m-d'));

        $rows = Database::select(
            "SELECT o.no_order, o.no_lab, o.tgl_order, o.asal, o.prioritas, o.status,
                    o.dokter_perujuk, o.khanza_noorder, o.khanza_no_rawat,
                    p.no_rm, p.nama AS nama_pasien, p.jk, p.tgl_lahir,
                    t.kode AS kode_test, t.nama AS nama_test,
                    r.nilai, r.satuan, r.flag, r.ref_teks, r.status AS status_hasil,
                    r.verified_at, i.nama AS alat
             FROM orders o
             JOIN patients p    ON p.id = o.patient_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.status <> 'cancelled'
             JOIN tests t       ON t.id = oi.test_id
             LEFT JOIN results r ON r.order_item_id = oi.id
             LEFT JOIN instruments i ON i.id = r.instrument_id
             WHERE DATE(o.tgl_order) BETWEEN ? AND ?
             ORDER BY o.tgl_order, o.no_lab, t.urut
             LIMIT 50000",
            [$dari, $sampai]
        );

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Gagal menyiapkan berkas ekspor.');
        }

        // BOM agar Excel membaca UTF-8 dengan benar.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'No Order', 'No Lab', 'Tanggal Order', 'Asal', 'Prioritas', 'Status Order',
            'Dokter Perujuk', 'No Order Khanza', 'No Rawat',
            'No RM', 'Nama Pasien', 'JK', 'Tanggal Lahir',
            'Kode Pemeriksaan', 'Nama Pemeriksaan',
            'Hasil', 'Satuan', 'Flag', 'Nilai Rujukan', 'Status Hasil', 'Waktu Verifikasi', 'Alat',
        ], ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return Response::make($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="hasil-lab-' . $dari . '-sd-' . $sampai . '.csv"');
    }

    /** @return array<string,string> */
    private function identitasFaskes(): array
    {
        return [
            'faskes'  => (string) Config::setting('app.nama_faskes', 'Fasilitas Kesehatan'),
            'lab'     => (string) Config::setting('app.nama_lab', 'Laboratorium Klinik'),
            'alamat'  => (string) Config::setting('app.alamat', ''),
            'telepon' => (string) Config::setting('app.telepon', ''),
            'logo'    => (string) Config::setting('app.logo', ''),
        ];
    }
}
