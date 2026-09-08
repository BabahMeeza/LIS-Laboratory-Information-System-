<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\KhanzaService;
use App\Services\OrderService;
use App\Services\ReferenceRangeService;

/**
 * Verifikasi (validasi) hasil oleh dokter penanggung jawab, lalu rilis.
 *
 * Rilis adalah titik di mana hasil dianggap sah untuk dipakai klinis:
 * lembar hasil dapat dicetak dan hasil dikirim balik ke SIMRS Khanza.
 */
final class VerificationController extends Controller
{
    public function index(): Response
    {
        $prioritas = $this->request->str('prioritas');
        $asal      = $this->request->str('asal');

        $where = ["o.status IN ('resulted','in_progress')", "o.status <> 'cancelled'"];
        $bind  = [];

        if ($prioritas !== '') {
            $where[] = 'o.prioritas = ?';
            $bind[]  = $prioritas;
        }
        if ($asal !== '') {
            $where[] = 'o.asal = ?';
            $bind[]  = $asal;
        }

        $sqlWhere = implode(' AND ', $where);

        $antrian = Database::select(
            "SELECT o.id, o.no_order, o.no_lab, o.prioritas, o.asal, o.tgl_order,
                    o.khanza_noorder, o.dokter_perujuk,
                    p.nama AS nama_pasien, p.no_rm, p.jk, p.tgl_lahir,
                    COUNT(oi.id) AS jml_item,
                    SUM(r.id IS NOT NULL) AS jml_hasil,
                    SUM(r.flag IN ('L','H')) AS jml_abnormal,
                    SUM(r.flag IN ('LL','HH')) AS jml_kritis,
                    SUM(r.delta_check = 'flagged') AS jml_delta,
                    TIMESTAMPDIFF(MINUTE, o.tgl_order, NOW()) AS umur_menit
             FROM orders o
             JOIN patients p ON p.id = o.patient_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.status <> 'cancelled'
             LEFT JOIN results r ON r.order_item_id = oi.id
             WHERE $sqlWhere
             GROUP BY o.id, o.no_order, o.no_lab, o.prioritas, o.asal, o.tgl_order,
                      o.khanza_noorder, o.dokter_perujuk, p.nama, p.no_rm, p.jk, p.tgl_lahir
             HAVING jml_hasil > 0
             ORDER BY (o.prioritas = 'cito') DESC, jml_kritis DESC, o.tgl_order ASC
             LIMIT 300",
            $bind
        );

        return $this->view('verification/index', [
            'antrian' => $antrian,
            'filter'  => compact('prioritas', 'asal'),
        ], 'Antrian Verifikasi');
    }

    /** @param array<string,string> $params */
    public function detail(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $order   = OrderService::detail($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }

        $items = OrderService::hasilOrder($orderId);
        $umur  = Helper::umurHari($order['tgl_lahir'] ?? null);

        foreach ($items as &$item) {
            $item['sebelumnya'] = Database::select(
                'SELECT r.nilai, r.flag, r.created_at, o.no_lab
                 FROM results r JOIN orders o ON o.id = r.order_id
                 WHERE o.patient_id = ? AND r.test_id = ? AND r.order_id <> ?
                   AND r.status IN (\'verified\',\'corrected\')
                 ORDER BY r.created_at DESC LIMIT 2',
                [$order['patient_id'], $item['test_id'], $orderId]
            );
        }
        unset($item);

        return $this->view('verification/detail', [
            'order' => $order,
            'items' => $items,
            'umur'  => $umur,
        ], 'Verifikasi — ' . $order['no_order']);
    }

    /** @param array<string,string> $params */
    public function verifikasi(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $order   = OrderService::detail($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }
        if ((string) $order['status'] === 'released') {
            Flash::error('Order sudah dirilis.');

            return $this->redirect('/order/' . $orderId);
        }

        /** @var array<int,string> $pilih */
        $pilih  = array_map('intval', $this->request->arr('verifikasi'));
        $semua  = $this->request->bool('semua');

        // Blokir verifikasi bila ada nilai kritis yang belum dilaporkan.
        $kritisBelumLapor = Database::select(
            'SELECT r.id, t.nama AS nama_test, r.nilai
             FROM results r JOIN tests t ON t.id = r.test_id
             WHERE r.order_id = ? AND r.is_kritis = 1 AND r.kritis_dilapor_at IS NULL',
            [$orderId]
        );

        if ($kritisBelumLapor !== [] && !$this->request->bool('abaikan_kritis')) {
            $daftar = implode(', ', array_map(
                static fn ($r) => $r['nama_test'] . ' = ' . $r['nilai'],
                $kritisBelumLapor
            ));
            Flash::error(
                'Verifikasi ditahan: terdapat nilai kritis yang belum tercatat pelaporannya (' . $daftar . '). '
                . 'Catat pelaporan ke DPJP terlebih dahulu.'
            );

            return $this->redirect('/verifikasi/' . $orderId);
        }

        $sql = 'SELECT r.* FROM results r
                JOIN order_items oi ON oi.id = r.order_item_id
                WHERE r.order_id = ? AND oi.status <> \'cancelled\'
                  AND r.status IN (\'final\',\'preliminary\',\'pending\')';
        $bind = [$orderId];

        if (!$semua && $pilih !== []) {
            $sql   .= ' AND r.id IN (' . implode(',', array_fill(0, count($pilih), '?')) . ')';
            $bind   = array_merge($bind, $pilih);
        }

        $results = Database::select($sql, $bind);

        if ($results === []) {
            Flash::peringatan('Tidak ada hasil yang perlu diverifikasi.');

            return $this->redirect('/verifikasi/' . $orderId);
        }

        $now = date('Y-m-d H:i:s');
        Database::transaction(static function () use ($results, $now): void {
            foreach ($results as $r) {
                Database::update('results', [
                    'status'      => 'verified',
                    'verified_by' => Auth::id(),
                    'verified_at' => $now,
                ], 'id = ?', [$r['id']]);

                Database::update('order_items', ['status' => 'verified'], 'id = ?', [$r['order_item_id']]);

                Database::insert('result_history', [
                    'result_id'   => (int) $r['id'],
                    'nilai_lama'  => $r['nilai'],
                    'nilai_baru'  => $r['nilai'],
                    'status_lama' => $r['status'],
                    'status_baru' => 'verified',
                    'alasan'      => 'Verifikasi',
                    'user_id'     => Auth::id(),
                    'sumber'      => 'verifikasi',
                ]);
            }
        });

        $status = OrderService::segarkanStatus($orderId);
        Audit::log('verifikasi_hasil', 'order', (string) $orderId, count($results) . ' hasil diverifikasi');
        Flash::sukses(count($results) . ' hasil diverifikasi.');

        // Kirim ke Khanza bila diatur "kirim saat verifikasi" dan order sudah lengkap.
        if ($status === 'verified' && Config::setting('khanza.kirim_hasil_saat', 'verifikasi') === 'verifikasi') {
            $this->kirimKeKhanza($orderId);
        }

        return $this->redirect($status === 'verified' ? '/verifikasi' : '/verifikasi/' . $orderId);
    }

    /**
     * Rilis order: hasil final untuk klinisi. Mencatat waktu selesai
     * (dasar perhitungan TAT) dan memicu pengiriman ke SIMRS bila diatur.
     *
     * @param array<string,string> $params
     */
    public function rilis(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $order   = OrderService::detail($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }
        if ((string) $order['status'] === 'released') {
            Flash::info('Order ini sudah dirilis sebelumnya.');

            return $this->redirect('/order/' . $orderId);
        }

        $belumVerifikasi = (int) Database::scalar(
            "SELECT COUNT(*) FROM order_items oi
             LEFT JOIN results r ON r.order_item_id = oi.id
             WHERE oi.order_id = ? AND oi.status <> 'cancelled'
               AND (r.id IS NULL OR r.status NOT IN ('verified','corrected'))",
            [$orderId]
        );

        if ($belumVerifikasi > 0 && !$this->request->bool('rilis_sebagian')) {
            Flash::error(
                $belumVerifikasi . ' pemeriksaan belum diverifikasi. '
                . 'Centang "rilis sebagian" bila memang ingin merilis hasil yang sudah ada.'
            );

            return $this->redirect('/verifikasi/' . $orderId);
        }

        Database::update('orders', [
            'status'      => 'released',
            'tgl_selesai' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$orderId]);

        Database::execute(
            "UPDATE order_items SET status = 'released' WHERE order_id = ? AND status = 'verified'",
            [$orderId]
        );

        Audit::log('rilis_hasil', 'order', (string) $orderId, 'Order dirilis' . ($belumVerifikasi > 0 ? ' (sebagian)' : ''));
        Flash::sukses('Hasil dirilis. Lembar hasil siap dicetak.');

        if (Config::setting('khanza.kirim_hasil_saat', 'verifikasi') === 'rilis') {
            $this->kirimKeKhanza($orderId);
        }

        return $this->redirect('/laporan/hasil/' . $orderId);
    }

    /**
     * Batalkan verifikasi (mis. ditemukan kekeliruan sebelum dirilis).
     *
     * @param array<string,string> $params
     */
    public function batalVerifikasi(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $alasan  = trim($this->request->str('alasan'));

        if ($alasan === '') {
            Flash::error('Alasan pembatalan verifikasi wajib diisi.');

            return $this->redirect('/verifikasi/' . $orderId);
        }

        $order = Database::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }
        if ((string) $order['status'] === 'released') {
            Flash::error('Order yang sudah dirilis tidak dapat dibatalkan verifikasinya. Gunakan koreksi hasil.');

            return $this->redirect('/order/' . $orderId);
        }

        $results = Database::select(
            "SELECT * FROM results WHERE order_id = ? AND status = 'verified'",
            [$orderId]
        );

        Database::transaction(static function () use ($results, $alasan): void {
            foreach ($results as $r) {
                Database::update('results', [
                    'status'      => 'final',
                    'verified_by' => null,
                    'verified_at' => null,
                ], 'id = ?', [$r['id']]);

                Database::update('order_items', ['status' => 'resulted'], 'id = ?', [$r['order_item_id']]);

                Database::insert('result_history', [
                    'result_id'   => (int) $r['id'],
                    'nilai_lama'  => $r['nilai'],
                    'nilai_baru'  => $r['nilai'],
                    'status_lama' => 'verified',
                    'status_baru' => 'final',
                    'alasan'      => mb_substr('Batal verifikasi: ' . $alasan, 0, 255),
                    'user_id'     => Auth::id(),
                    'sumber'      => 'batal_verifikasi',
                ]);
            }
        });

        OrderService::segarkanStatus($orderId);
        Audit::log('batal_verifikasi', 'order', (string) $orderId, $alasan);
        Flash::sukses('Verifikasi dibatalkan untuk ' . count($results) . ' hasil.');

        return $this->redirect('/verifikasi/' . $orderId);
    }

    private function kirimKeKhanza(int $orderId): void
    {
        if (!KhanzaService::aktif()) {
            return;
        }

        $hasil = KhanzaService::kirimHasil($orderId);

        if ($hasil['sukses']) {
            Flash::info('Hasil terkirim ke SIMRS Khanza.');
        } else {
            Flash::peringatan($hasil['pesan']);
        }
    }
}
