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
use App\Services\OrderService;

final class OrderController extends Controller
{
    public function index(): Response
    {
        $cari      = $this->request->str('q');
        $status    = $this->request->str('status');
        $asal      = $this->request->str('asal');
        $prioritas = $this->request->str('prioritas');
        $dari      = $this->request->str('dari', date('Y-m-d'));
        $sampai    = $this->request->str('sampai', date('Y-m-d'));

        $hal   = $this->halaman();
        $per   = $this->perHalaman();
        $mulai = ($hal - 1) * $per;

        $where = ['DATE(o.tgl_order) BETWEEN ? AND ?'];
        $bind  = [$dari, $sampai];

        if ($cari !== '') {
            $where[] = '(o.no_order LIKE ? OR o.no_lab LIKE ? OR o.khanza_noorder LIKE ? OR p.nama LIKE ? OR p.no_rm LIKE ?)';
            $like    = '%' . $cari . '%';
            array_push($bind, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $bind[]  = $status;
        }
        if ($asal !== '') {
            $where[] = 'o.asal = ?';
            $bind[]  = $asal;
        }
        if ($prioritas !== '') {
            $where[] = 'o.prioritas = ?';
            $bind[]  = $prioritas;
        }

        $sqlWhere = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM orders o JOIN patients p ON p.id = o.patient_id WHERE $sqlWhere",
            $bind
        );

        $orders = Database::select(
            "SELECT o.*, p.nama AS nama_pasien, p.no_rm, p.jk, p.tgl_lahir,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id AND oi.status <> 'cancelled') AS jml_item,
                    (SELECT COUNT(*) FROM results r WHERE r.order_id = o.id) AS jml_hasil,
                    (SELECT GROUP_CONCAT(s.barcode SEPARATOR ', ') FROM specimens s WHERE s.order_id = o.id) AS barcode
             FROM orders o
             JOIN patients p ON p.id = o.patient_id
             WHERE $sqlWhere
             ORDER BY (o.prioritas = 'cito') DESC, o.tgl_order DESC
             LIMIT $per OFFSET $mulai",
            $bind
        );

        return $this->view('orders/index', [
            'orders'    => $orders,
            'total'     => $total,
            'hal'       => $hal,
            'per'       => $per,
            'filter'    => compact('cari', 'status', 'asal', 'prioritas', 'dari', 'sampai'),
        ], 'Daftar Order');
    }

    public function form(): Response
    {
        $kategori = Database::select('SELECT * FROM test_categories WHERE aktif = 1 ORDER BY urut');
        $tests    = Database::select(
            'SELECT t.*, tc.nama AS kategori, st.nama AS spesimen
             FROM tests t
             LEFT JOIN test_categories tc ON tc.id = t.category_id
             LEFT JOIN specimen_types st ON st.id = t.specimen_type_id
             WHERE t.aktif = 1
             ORDER BY tc.urut, t.urut, t.nama'
        );
        $panels = Database::select(
            'SELECT p.*, (SELECT COUNT(*) FROM test_panel_items i WHERE i.panel_id = p.id) AS jml_item
             FROM test_panels p WHERE p.aktif = 1 ORDER BY p.nama'
        );

        $pasienId = $this->request->int('pasien_id');
        $pasien   = $pasienId > 0
            ? Database::selectOne('SELECT * FROM patients WHERE id = ?', [$pasienId])
            : null;

        return $this->view('orders/form', [
            'kategori' => $kategori,
            'tests'    => $tests,
            'panels'   => $panels,
            'pasien'   => $pasien,
        ], 'Order Pemeriksaan Baru');
    }

    public function simpan(): Response
    {
        $patientId = $this->request->int('patient_id');
        $testIds   = array_map('intval', $this->request->arr('tests'));
        $panelIds  = array_map('intval', $this->request->arr('panels'));

        if ($patientId <= 0) {
            Flash::error('Pasien belum dipilih.');

            return $this->back('/order/baru');
        }
        if ($testIds === [] && $panelIds === []) {
            Flash::error('Pilih minimal satu pemeriksaan atau paket.');

            return $this->back('/order/baru');
        }

        try {
            $hasil = OrderService::buat([
                'patient_id'      => $patientId,
                'asal'            => $this->request->str('asal', 'ralan'),
                'nama_ruang'      => $this->request->str('nama_ruang') ?: null,
                'nama_carabayar'  => $this->request->str('nama_carabayar') ?: null,
                'dokter_perujuk'  => $this->request->str('dokter_perujuk') ?: null,
                'diagnosa_klinis' => $this->request->str('diagnosa_klinis') ?: null,
                'prioritas'       => $this->request->str('prioritas', 'rutin'),
                'catatan'         => $this->request->str('catatan') ?: null,
                'sumber'          => 'manual',
                'created_by'      => Auth::id(),
            ], $testIds, $panelIds);
        } catch (\Throwable $e) {
            Flash::error('Gagal menyimpan order: ' . $e->getMessage());

            return $this->back('/order/baru');
        }

        Flash::sukses(
            'Order ' . $hasil['no_order'] . ' dibuat (No. Lab ' . $hasil['no_lab'] . '). '
            . 'Barcode spesimen: ' . implode(', ', $hasil['barcode'])
        );

        return $this->redirect('/order/' . $hasil['order_id']);
    }

    /** @param array<string,string> $params */
    public function detail(array $params): Response
    {
        $id    = (int) $params['id'];
        $order = OrderService::detail($id);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }

        $items     = OrderService::hasilOrder($id);
        $spesimen  = Database::select(
            'SELECT s.*, st.nama AS jenis_spesimen, st.container, st.warna,
                    ua.nama AS diambil_oleh, ut.nama AS diterima_oleh
             FROM specimens s
             LEFT JOIN specimen_types st ON st.id = s.specimen_type_id
             LEFT JOIN users ua ON ua.id = s.collected_by
             LEFT JOIN users ut ON ut.id = s.received_by
             WHERE s.order_id = ? ORDER BY s.id',
            [$id]
        );

        $sync = Database::select(
            'SELECT * FROM khanza_sync_log
             WHERE ref_type = \'order\' AND ref_id = ?
             ORDER BY id DESC LIMIT 10',
            [(string) $id]
        );

        return $this->view('orders/detail', [
            'order'    => $order,
            'items'    => $items,
            'spesimen' => $spesimen,
            'sync'     => $sync,
            'tat'      => OrderService::tatMenit($id),
        ], 'Order ' . $order['no_order']);
    }

    /** @param array<string,string> $params */
    public function batal(array $params): Response
    {
        $id     = (int) $params['id'];
        $alasan = $this->request->str('alasan');

        if ($alasan === '') {
            Flash::error('Alasan pembatalan wajib diisi.');

            return $this->redirect('/order/' . $id);
        }

        if (OrderService::batal($id, $alasan)) {
            Flash::sukses('Order dibatalkan.');
        } else {
            Flash::error('Order tidak dapat dibatalkan (sudah dirilis atau sudah batal).');
        }

        return $this->redirect('/order/' . $id);
    }

    /** Tambah pemeriksaan pada order yang sudah berjalan. */
    public function tambahItem(array $params): Response
    {
        $orderId = (int) $params['id'];
        $testIds = array_map('intval', $this->request->arr('tests'));

        $order = Database::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }
        if (in_array((string) $order['status'], ['released', 'cancelled'], true)) {
            Flash::error('Order yang sudah dirilis atau dibatalkan tidak dapat diubah.');

            return $this->redirect('/order/' . $orderId);
        }
        if ($testIds === []) {
            Flash::error('Tidak ada pemeriksaan yang dipilih.');

            return $this->redirect('/order/' . $orderId);
        }

        $ditambah = 0;
        Database::transaction(static function () use ($orderId, $testIds, &$ditambah): void {
            foreach ($testIds as $testId) {
                $sudahAda = (int) Database::scalar(
                    'SELECT COUNT(*) FROM order_items WHERE order_id = ? AND test_id = ?',
                    [$orderId, $testId]
                );
                if ($sudahAda > 0) {
                    continue;
                }

                $test = Database::selectOne('SELECT * FROM tests WHERE id = ?', [$testId]);
                if ($test === null) {
                    continue;
                }

                // Pakai spesimen yang sudah ada untuk jenis tabung yang sama.
                $specimenId = Database::scalar(
                    'SELECT id FROM specimens WHERE order_id = ? AND (specimen_type_id <=> ?) LIMIT 1',
                    [$orderId, $test['specimen_type_id']]
                );

                if ($specimenId === null) {
                    $specimenId = Database::insert('specimens', [
                        'order_id'         => $orderId,
                        'barcode'          => OrderService::barcodeSpesimen(),
                        'specimen_type_id' => $test['specimen_type_id'],
                        'status'           => 'pending',
                    ]);
                }

                Database::insert('order_items', [
                    'order_id'            => $orderId,
                    'test_id'             => $testId,
                    'specimen_id'         => (int) $specimenId,
                    'harga'               => (float) $test['harga'],
                    'status'              => 'pending',
                    'urut'                => (int) $test['urut'],
                    'khanza_kd_jenis_prw' => $test['khanza_kd_jenis_prw'],
                    'khanza_id_template'  => $test['khanza_id_template'],
                ]);
                $ditambah++;
            }

            Database::execute(
                'UPDATE orders SET total_harga =
                    (SELECT COALESCE(SUM(harga),0) FROM order_items WHERE order_id = ? AND status <> \'cancelled\')
                 WHERE id = ?',
                [$orderId, $orderId]
            );
        });

        OrderService::segarkanStatus($orderId);
        Audit::log('tambah_item_order', 'order', (string) $orderId, $ditambah . ' pemeriksaan ditambahkan');
        Flash::sukses($ditambah . ' pemeriksaan ditambahkan ke order.');

        return $this->redirect('/order/' . $orderId);
    }

    /** @param array<string,string> $params */
    public function hapusItem(array $params): Response
    {
        $orderId = (int) $params['id'];
        $itemId  = (int) $params['itemId'];

        $item = Database::selectOne(
            'SELECT oi.*, r.id AS result_id FROM order_items oi
             LEFT JOIN results r ON r.order_item_id = oi.id
             WHERE oi.id = ? AND oi.order_id = ?',
            [$itemId, $orderId]
        );

        if ($item === null) {
            Flash::error('Item tidak ditemukan.');

            return $this->redirect('/order/' . $orderId);
        }
        if ($item['result_id'] !== null) {
            Flash::error('Pemeriksaan yang sudah memiliki hasil tidak dapat dihapus. Gunakan pembatalan item.');

            return $this->redirect('/order/' . $orderId);
        }

        Database::update('order_items', ['status' => 'cancelled'], 'id = ?', [$itemId]);
        OrderService::segarkanStatus($orderId);
        Audit::log('batal_item_order', 'order_item', (string) $itemId, 'Item dibatalkan');
        Flash::sukses('Pemeriksaan dibatalkan dari order.');

        return $this->redirect('/order/' . $orderId);
    }
}
