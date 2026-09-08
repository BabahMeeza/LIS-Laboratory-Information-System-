<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Barcode;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;
use App\Services\OrderService;

final class SpecimenController extends Controller
{
    public function index(): Response
    {
        $status = $this->request->str('status');
        $dari   = $this->request->str('dari', date('Y-m-d'));
        $sampai = $this->request->str('sampai', date('Y-m-d'));
        $cari   = $this->request->str('q');

        $where = ['DATE(o.tgl_order) BETWEEN ? AND ?', "o.status <> 'cancelled'"];
        $bind  = [$dari, $sampai];

        if ($status !== '') {
            $where[] = 's.status = ?';
            $bind[]  = $status;
        }
        if ($cari !== '') {
            $where[] = '(s.barcode LIKE ? OR o.no_lab LIKE ? OR p.nama LIKE ? OR p.no_rm LIKE ?)';
            $like    = '%' . $cari . '%';
            array_push($bind, $like, $like, $like, $like);
        }

        $sqlWhere = implode(' AND ', $where);

        $spesimen = Database::select(
            "SELECT s.*, o.no_order, o.no_lab, o.prioritas, o.asal, o.tgl_order,
                    p.nama AS nama_pasien, p.no_rm, p.jk, p.tgl_lahir,
                    st.nama AS jenis_spesimen, st.container, st.warna,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.specimen_id = s.id AND oi.status <> 'cancelled') AS jml_item
             FROM specimens s
             JOIN orders o   ON o.id = s.order_id
             JOIN patients p ON p.id = o.patient_id
             LEFT JOIN specimen_types st ON st.id = s.specimen_type_id
             WHERE $sqlWhere
             ORDER BY (o.prioritas = 'cito') DESC, s.id DESC
             LIMIT 300",
            $bind
        );

        return $this->view('specimens/index', [
            'spesimen' => $spesimen,
            'filter'   => compact('status', 'dari', 'sampai', 'cari'),
        ], 'Spesimen');
    }

    /** Layar penerimaan sampel berbasis pemindaian barcode. */
    public function penerimaan(): Response
    {
        $terbaru = Database::select(
            "SELECT s.*, o.no_lab, o.no_order, o.prioritas,
                    p.nama AS nama_pasien, p.no_rm,
                    st.nama AS jenis_spesimen,
                    u.nama AS diterima_oleh
             FROM specimens s
             JOIN orders o   ON o.id = s.order_id
             JOIN patients p ON p.id = o.patient_id
             LEFT JOIN specimen_types st ON st.id = s.specimen_type_id
             LEFT JOIN users u ON u.id = s.received_by
             WHERE s.received_at IS NOT NULL AND DATE(s.received_at) = CURDATE()
             ORDER BY s.received_at DESC LIMIT 30"
        );

        $menunggu = (int) Database::scalar(
            "SELECT COUNT(*) FROM specimens s JOIN orders o ON o.id = s.order_id
             WHERE s.status IN ('pending','collected') AND o.status <> 'cancelled'"
        );

        return $this->view('specimens/penerimaan', [
            'terbaru'  => $terbaru,
            'menunggu' => $menunggu,
        ], 'Penerimaan Sampel');
    }

    /** @param array<string,string> $params */
    public function ambil(array $params): Response
    {
        $id = (int) $params['id'];
        $this->ubahStatus($id, 'collected', [
            'collected_at' => date('Y-m-d H:i:s'),
            'collected_by' => Auth::id(),
        ], 'Spesimen ditandai sudah diambil.');

        return $this->back('/spesimen');
    }

    /** @param array<string,string> $params */
    public function terima(array $params): Response
    {
        $id      = (int) $params['id'];
        $kondisi = $this->request->str('kondisi', 'baik');

        $this->ubahStatus($id, 'received', [
            'received_at' => date('Y-m-d H:i:s'),
            'received_by' => Auth::id(),
            'kondisi'     => $kondisi,
            // Bila belum tercatat pengambilan, isikan sekaligus.
            'collected_at' => Database::scalar('SELECT collected_at FROM specimens WHERE id = ?', [$id]) ?? date('Y-m-d H:i:s'),
        ], 'Spesimen diterima di laboratorium.');

        return $this->back('/spesimen/penerimaan');
    }

    /** @param array<string,string> $params */
    public function tolak(array $params): Response
    {
        $id     = (int) $params['id'];
        $alasan = $this->request->str('alasan');
        $kondisi = $this->request->str('kondisi', 'tidak_sesuai');

        if ($alasan === '') {
            Flash::error('Alasan penolakan wajib diisi — ini bagian dari rekam mutu pra-analitik.');

            return $this->back('/spesimen');
        }

        $this->ubahStatus($id, 'rejected', [
            'alasan_tolak' => $alasan,
            'kondisi'      => $kondisi,
            'received_at'  => date('Y-m-d H:i:s'),
            'received_by'  => Auth::id(),
        ], 'Spesimen ditolak: ' . $alasan);

        return $this->back('/spesimen');
    }

    /** Penerimaan cepat dengan memindai barcode. */
    public function terimaBarcode(): Response
    {
        $barcode = trim($this->request->str('barcode'));

        if ($barcode === '') {
            return $this->json(['sukses' => false, 'pesan' => 'Barcode kosong.'], 422);
        }

        $spesimen = Database::selectOne(
            'SELECT s.*, o.id AS order_id, o.no_lab, o.no_order, o.prioritas, o.status AS status_order,
                    p.nama AS nama_pasien, p.no_rm, st.nama AS jenis_spesimen
             FROM specimens s
             JOIN orders o   ON o.id = s.order_id
             JOIN patients p ON p.id = o.patient_id
             LEFT JOIN specimen_types st ON st.id = s.specimen_type_id
             WHERE s.barcode = ? LIMIT 1',
            [$barcode]
        );

        if ($spesimen === null) {
            return $this->json(['sukses' => false, 'pesan' => 'Barcode "' . $barcode . '" tidak dikenali.'], 404);
        }
        if ((string) $spesimen['status_order'] === 'cancelled') {
            return $this->json(['sukses' => false, 'pesan' => 'Order untuk spesimen ini sudah dibatalkan.'], 409);
        }
        if ((string) $spesimen['status'] === 'received') {
            return $this->json([
                'sukses' => false,
                'pesan'  => 'Spesimen ini sudah diterima pada ' . $spesimen['received_at'] . '.',
                'data'   => $spesimen,
            ], 409);
        }
        if ((string) $spesimen['status'] === 'rejected') {
            return $this->json(['sukses' => false, 'pesan' => 'Spesimen ini berstatus ditolak.'], 409);
        }

        $now = date('Y-m-d H:i:s');
        Database::update('specimens', [
            'status'       => 'received',
            'received_at'  => $now,
            'received_by'  => Auth::id(),
            'collected_at' => $spesimen['collected_at'] ?? $now,
            'kondisi'      => $this->request->str('kondisi', 'baik'),
        ], 'id = ?', [$spesimen['id']]);

        Database::execute(
            "UPDATE order_items SET status = 'received'
             WHERE specimen_id = ? AND status IN ('pending','collected')",
            [$spesimen['id']]
        );

        OrderService::segarkanStatus((int) $spesimen['order_id']);
        Audit::log('terima_spesimen', 'specimen', (string) $spesimen['id'], 'Barcode ' . $barcode);

        $items = Database::select(
            'SELECT t.kode, t.nama FROM order_items oi JOIN tests t ON t.id = oi.test_id
             WHERE oi.specimen_id = ? AND oi.status <> \'cancelled\' ORDER BY t.urut',
            [$spesimen['id']]
        );

        return $this->json([
            'sukses' => true,
            'pesan'  => 'Spesimen diterima.',
            'data'   => [
                'barcode'      => $barcode,
                'no_lab'       => $spesimen['no_lab'],
                'no_order'     => $spesimen['no_order'],
                'order_id'     => (int) $spesimen['order_id'],
                'nama_pasien'  => $spesimen['nama_pasien'],
                'no_rm'        => $spesimen['no_rm'],
                'prioritas'    => $spesimen['prioritas'],
                'jenis'        => $spesimen['jenis_spesimen'],
                'diterima_at'  => $now,
                'pemeriksaan'  => $items,
            ],
        ]);
    }

    /** Label barcode siap cetak untuk satu spesimen. */
    public function label(array $params): Response
    {
        $id  = (int) $params['id'];
        $row = $this->dataLabel('s.id = ?', [$id]);

        if ($row === []) {
            throw new HttpException(404, 'Spesimen tidak ditemukan.');
        }

        return Response::make(View::capture('specimens/label', [
            'labels' => $row,
            'lab'    => Config::setting('app.nama_lab', 'Laboratorium'),
        ]));
    }

    /** Cetak beberapa label sekaligus (mis. seluruh spesimen satu order). */
    public function labelBatch(): Response
    {
        $orderId = $this->request->int('order_id');
        $ids     = array_filter(array_map('intval', explode(',', $this->request->str('ids'))));

        if ($orderId > 0) {
            $labels = $this->dataLabel('s.order_id = ?', [$orderId]);
        } elseif ($ids !== []) {
            $tanda  = implode(',', array_fill(0, count($ids), '?'));
            $labels = $this->dataLabel("s.id IN ($tanda)", $ids);
        } else {
            $labels = $this->dataLabel("DATE(o.tgl_order) = CURDATE() AND s.status = 'pending'", []);
        }

        return Response::make(View::capture('specimens/label', [
            'labels' => $labels,
            'lab'    => Config::setting('app.nama_lab', 'Laboratorium'),
        ]));
    }

    /**
     * @param  array<int,mixed> $bind
     * @return array<int,array<string,mixed>>
     */
    private function dataLabel(string $where, array $bind): array
    {
        $rows = Database::select(
            "SELECT s.id, s.barcode, s.status,
                    o.no_lab, o.no_order, o.prioritas, o.tgl_order, o.asal,
                    p.nama AS nama_pasien, p.no_rm, p.jk, p.tgl_lahir,
                    st.nama AS jenis_spesimen, st.container, st.warna
             FROM specimens s
             JOIN orders o   ON o.id = s.order_id
             JOIN patients p ON p.id = o.patient_id
             LEFT JOIN specimen_types st ON st.id = s.specimen_type_id
             WHERE $where
             ORDER BY s.id
             LIMIT 200",
            $bind
        );

        foreach ($rows as &$row) {
            $row['barcode_svg'] = Barcode::svg((string) $row['barcode'], 38, 1.5, false);
            $row['pemeriksaan'] = array_column(
                Database::select(
                    'SELECT t.nama_singkat, t.nama FROM order_items oi
                     JOIN tests t ON t.id = oi.test_id
                     WHERE oi.specimen_id = ? AND oi.status <> \'cancelled\'
                     ORDER BY t.urut LIMIT 12',
                    [$row['id']]
                ),
                'nama_singkat'
            );
        }

        return $rows;
    }

    /** @param array<string,mixed> $extra */
    private function ubahStatus(int $id, string $status, array $extra, string $pesan): void
    {
        $spesimen = Database::selectOne('SELECT * FROM specimens WHERE id = ?', [$id]);
        if ($spesimen === null) {
            throw new HttpException(404, 'Spesimen tidak ditemukan.');
        }

        Database::update('specimens', array_merge(['status' => $status], $extra), 'id = ?', [$id]);

        $statusItem = match ($status) {
            'collected' => 'collected',
            'received'  => 'received',
            'rejected'  => 'cancelled',
            default     => null,
        };

        if ($statusItem !== null) {
            Database::execute(
                "UPDATE order_items SET status = ?
                 WHERE specimen_id = ? AND status IN ('pending','collected','received')",
                [$statusItem, $id]
            );
        }

        OrderService::segarkanStatus((int) $spesimen['order_id']);
        Audit::log('spesimen_' . $status, 'specimen', (string) $id, $pesan);
        Flash::sukses($pesan);
    }
}
