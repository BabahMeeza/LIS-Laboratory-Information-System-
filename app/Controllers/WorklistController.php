<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Response;

/**
 * Worklist: daftar pemeriksaan yang siap dikerjakan, dikelompokkan per
 * kategori/alat. Juga menjadi titik pengiriman worklist ke alat
 * bidirectional (host query).
 */
final class WorklistController extends Controller
{
    public function index(): Response
    {
        $kategoriId = $this->request->int('kategori');
        $alatId     = $this->request->int('alat');
        $prioritas  = $this->request->str('prioritas');
        $tampil     = $this->request->str('tampil', 'belum'); // belum | semua

        $where = ["o.status NOT IN ('cancelled','released')", "oi.status <> 'cancelled'", "s.status = 'received'"];
        $bind  = [];

        if ($tampil === 'belum') {
            $where[] = 'r.id IS NULL';
        }
        if ($kategoriId > 0) {
            $where[] = 't.category_id = ?';
            $bind[]  = $kategoriId;
        }
        if ($prioritas !== '') {
            $where[] = 'o.prioritas = ?';
            $bind[]  = $prioritas;
        }
        if ($alatId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM instrument_test_map m WHERE m.instrument_id = ? AND m.test_id = t.id AND m.abaikan = 0)';
            $bind[]  = $alatId;
        }

        $sqlWhere = implode(' AND ', $where);

        $rows = Database::select(
            "SELECT oi.id AS order_item_id, oi.status AS status_item,
                    o.id AS order_id, o.no_order, o.no_lab, o.prioritas, o.asal, o.tgl_order,
                    p.nama AS nama_pasien, p.no_rm, p.jk, p.tgl_lahir,
                    t.id AS test_id, t.kode AS kode_test, t.nama AS nama_test, t.satuan, t.tat_menit,
                    tc.nama AS kategori, tc.id AS kategori_id,
                    s.barcode, s.received_at,
                    r.id AS result_id, r.nilai, r.flag,
                    TIMESTAMPDIFF(MINUTE, o.tgl_order, NOW()) AS umur_menit
             FROM order_items oi
             JOIN orders o    ON o.id = oi.order_id
             JOIN patients p  ON p.id = o.patient_id
             JOIN tests t     ON t.id = oi.test_id
             LEFT JOIN test_categories tc ON tc.id = t.category_id
             JOIN specimens s ON s.id = oi.specimen_id
             LEFT JOIN results r ON r.order_item_id = oi.id
             WHERE $sqlWhere
             ORDER BY (o.prioritas = 'cito') DESC, o.tgl_order ASC, tc.urut, t.urut
             LIMIT 500",
            $bind
        );

        // Kelompokkan per order agar mudah dikerjakan sekaligus.
        $perOrder = [];
        foreach ($rows as $row) {
            $key = (int) $row['order_id'];
            if (!isset($perOrder[$key])) {
                $perOrder[$key] = [
                    'order_id'    => $key,
                    'no_order'    => $row['no_order'],
                    'no_lab'      => $row['no_lab'],
                    'barcode'     => $row['barcode'],
                    'prioritas'   => $row['prioritas'],
                    'asal'        => $row['asal'],
                    'tgl_order'   => $row['tgl_order'],
                    'nama_pasien' => $row['nama_pasien'],
                    'no_rm'       => $row['no_rm'],
                    'jk'          => $row['jk'],
                    'tgl_lahir'   => $row['tgl_lahir'],
                    'umur_menit'  => (int) $row['umur_menit'],
                    'tat_menit'   => (int) $row['tat_menit'],
                    'items'       => [],
                ];
            }
            $perOrder[$key]['items'][] = $row;
            $perOrder[$key]['tat_menit'] = max($perOrder[$key]['tat_menit'], (int) $row['tat_menit']);
        }

        $kategori = Database::select('SELECT * FROM test_categories WHERE aktif = 1 ORDER BY urut');
        $alat     = Database::select('SELECT id, kode, nama, mode FROM instruments WHERE aktif = 1 ORDER BY nama');

        return $this->view('worklist/index', [
            'perOrder' => $perOrder,
            'kategori' => $kategori,
            'alat'     => $alat,
            'filter'   => compact('kategoriId', 'alatId', 'prioritas', 'tampil'),
        ], 'Worklist');
    }

    /**
     * Kirim daftar pemeriksaan ke alat bidirectional.
     * Middleware akan mengambilnya lewat GET /api/v1/instruments/{kode}/worklist
     * atau menjawab host query (ASTM record Q) dari alat.
     */
    public function kirimKeAlat(): Response
    {
        $instrumentId = $this->request->int('instrument_id');
        $orderIds     = array_map('intval', $this->request->arr('order_ids'));

        $alat = Database::selectOne('SELECT * FROM instruments WHERE id = ? AND aktif = 1', [$instrumentId]);
        if ($alat === null) {
            Flash::error('Alat tidak ditemukan atau nonaktif.');

            return $this->back('/worklist');
        }
        if ((string) $alat['mode'] !== 'bidirectional') {
            Flash::error('Alat "' . $alat['nama'] . '" tidak dikonfigurasi sebagai bidirectional, worklist tidak dapat dikirim.');

            return $this->back('/worklist');
        }
        if ($orderIds === []) {
            Flash::error('Pilih minimal satu order.');

            return $this->back('/worklist');
        }

        $tanda = implode(',', array_fill(0, count($orderIds), '?'));
        $rows  = Database::select(
            "SELECT s.id AS specimen_id, s.barcode, o.id AS order_id,
                    m.kode_alat, t.kode AS kode_test, t.nama AS nama_test,
                    p.nama AS nama_pasien, p.jk, p.tgl_lahir
             FROM order_items oi
             JOIN orders o    ON o.id = oi.order_id
             JOIN patients p  ON p.id = o.patient_id
             JOIN specimens s ON s.id = oi.specimen_id
             JOIN tests t     ON t.id = oi.test_id
             JOIN instrument_test_map m ON m.test_id = t.id AND m.instrument_id = ? AND m.abaikan = 0
             WHERE o.id IN ($tanda) AND oi.status <> 'cancelled'
             ORDER BY s.barcode, t.urut",
            array_merge([$instrumentId], $orderIds)
        );

        if ($rows === []) {
            Flash::peringatan('Tidak ada pemeriksaan pada order terpilih yang dipetakan ke alat ini.');

            return $this->back('/worklist');
        }

        $perSampel = [];
        foreach ($rows as $row) {
            $barcode = (string) $row['barcode'];
            $perSampel[$barcode]['specimen_id'] = (int) $row['specimen_id'];
            $perSampel[$barcode]['pasien']      = [
                'nama'      => $row['nama_pasien'],
                'jk'        => $row['jk'],
                'tgl_lahir' => $row['tgl_lahir'],
            ];
            $perSampel[$barcode]['tests'][] = $row['kode_alat'];
        }

        $dikirim = 0;
        foreach ($perSampel as $barcode => $data) {
            // Hindari duplikat antrian yang belum terkirim.
            $sudahAda = (int) Database::scalar(
                "SELECT COUNT(*) FROM instrument_worklist
                 WHERE instrument_id = ? AND sample_id = ? AND status = 'menunggu'",
                [$instrumentId, $barcode]
            );
            if ($sudahAda > 0) {
                continue;
            }

            Database::insert('instrument_worklist', [
                'instrument_id' => $instrumentId,
                'specimen_id'   => $data['specimen_id'],
                'sample_id'     => $barcode,
                'payload'       => (string) json_encode([
                    'sample_id' => $barcode,
                    'patient'   => $data['pasien'],
                    'tests'     => array_values(array_unique($data['tests'])),
                ], JSON_UNESCAPED_UNICODE),
                'status'        => 'menunggu',
            ]);
            $dikirim++;
        }

        Flash::sukses($dikirim . ' sampel dimasukkan ke antrian worklist alat "' . $alat['nama'] . '".');

        return $this->back('/worklist');
    }
}
