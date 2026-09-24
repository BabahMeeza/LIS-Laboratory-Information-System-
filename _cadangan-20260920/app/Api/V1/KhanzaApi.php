<?php
declare(strict_types=1);

namespace App\Api\V1;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Services\KhanzaService;
use App\Services\OrderService;

/**
 * API yang dipanggil konektor SIMRS Khanza.
 *
 * Mode push  : konektor memanggil POST /api/v1/khanza/orders setiap kali
 *              ada permintaan lab baru di Khanza.
 * Mode pull  : konektor menyediakan endpoint sendiri; LIS yang menarik.
 *              Endpoint di sini tetap berguna untuk konektor mengambil
 *              hasil (GET /api/v1/khanza/hasil) bila arah pengiriman dibalik.
 */
final class KhanzaApi extends ApiController
{
    /**
     * Terima satu atau beberapa permintaan lab dari Khanza.
     *
     * Body: satu objek order, atau { "orders": [ {…}, {…} ] }
     */
    public function terimaOrder(): Response
    {
        $body   = $this->body();
        $daftar = is_array($body['orders'] ?? null) ? $body['orders'] : [$body];

        if ($daftar === []) {
            return $this->gagal('Tidak ada order pada payload.', 422);
        }

        $hasil  = [];
        $sukses = 0;

        foreach ($daftar as $order) {
            if (!is_array($order)) {
                continue;
            }

            try {
                $r = KhanzaService::terimaOrder($order);
            } catch (\Throwable $e) {
                Logger::exception($e);
                $r = [
                    'sukses'          => false,
                    'pesan'           => 'Kesalahan internal: ' . $e->getMessage(),
                    'order_id'        => null,
                    'no_order'        => null,
                    'no_lab'          => null,
                    'barcode'         => [],
                    'tidak_dipetakan' => [],
                ];
            }

            if ($r['sukses']) {
                $sukses++;
            }

            $hasil[] = [
                'noorder'         => (string) ($order['noorder'] ?? ''),
                'sukses'          => $r['sukses'],
                'pesan'           => $r['pesan'],
                'lis_order_id'    => $r['order_id'],
                'lis_no_order'    => $r['no_order'],
                'no_lab'          => $r['no_lab'],
                'barcode'         => $r['barcode'],
                'tidak_dipetakan' => $r['tidak_dipetakan'],
            ];
        }

        $status = match (true) {
            $sukses === count($hasil) => 200,
            $sukses === 0             => 422,
            default                   => 207,
        };

        return $this->sukses(
            $hasil,
            sprintf('%d dari %d order diterima', $sukses, count($hasil)),
            $status
        );
    }

    /**
     * Pembatalan order dari sisi Khanza.
     *
     * Body: { "noorder": "LB0001", "alasan": "Dibatalkan dokter" }
     */
    public function batalkanOrder(): Response
    {
        $body    = $this->body();
        $noorder = trim((string) ($body['noorder'] ?? ''));
        $alasan  = trim((string) ($body['alasan'] ?? 'Dibatalkan dari SIMRS Khanza'));

        if ($noorder === '') {
            return $this->gagal('Field "noorder" wajib diisi.', 422);
        }

        $order = Database::selectOne(
            'SELECT id, status FROM orders WHERE khanza_noorder = ? LIMIT 1',
            [$noorder]
        );

        if ($order === null) {
            return $this->gagal('Order dengan noorder "' . $noorder . '" tidak ditemukan di LIS.', 404);
        }
        if ((string) $order['status'] === 'released') {
            return $this->gagal('Order sudah dirilis dan tidak dapat dibatalkan.', 409);
        }
        if ((string) $order['status'] === 'cancelled') {
            return $this->sukses(['order_id' => (int) $order['id']], 'Order memang sudah dibatalkan.');
        }

        OrderService::batal((int) $order['id'], $alasan);
        Audit::log('batal_order_khanza', 'order', (string) $order['id'], $alasan);

        return $this->sukses(['order_id' => (int) $order['id']], 'Order dibatalkan.');
    }

    /**
     * Daftar hasil siap kirim — dipakai konektor bila memakai pola tarik.
     *
     * Query: ?limit=20&sejak=2026-08-31T00:00:00
     */
    public function hasilSiapKirim(): Response
    {
        $limit = max(1, min(100, $this->request->int('limit', 20)));
        $sejak = $this->request->str('sejak');

        $where = [
            "o.khanza_noorder IS NOT NULL",
            "o.status IN ('verified','released')",
            "NOT EXISTS (
                SELECT 1 FROM khanza_sync_log k
                WHERE k.ref_type = 'order' AND k.ref_id = CAST(o.id AS CHAR)
                  AND k.jenis = 'hasil' AND k.status = 'sukses')",
        ];
        $bind = [];

        if ($sejak !== '') {
            $where[] = 'o.updated_at >= ?';
            $bind[]  = date('Y-m-d H:i:s', strtotime($sejak) ?: time());
        }

        $sqlWhere = implode(' AND ', $where);

        $orders = Database::select(
            "SELECT o.id FROM orders o WHERE $sqlWhere ORDER BY o.id ASC LIMIT $limit",
            $bind
        );

        $data = [];
        foreach ($orders as $row) {
            $payload = KhanzaService::payloadHasil((int) $row['id']);
            if ($payload !== null) {
                $payload['lis_order_id'] = (int) $row['id'];
                $data[]                  = $payload;
            }
        }

        return $this->sukses($data, count($data) . ' hasil siap kirim');
    }

    /**
     * Konfirmasi bahwa hasil sudah tersimpan di Khanza.
     *
     * Body: { "lis_order_id": [12, 13] } atau { "noorder": ["LB0001"] }
     */
    public function ackHasil(): Response
    {
        $body      = $this->body();
        $orderIds  = is_array($body['lis_order_id'] ?? null) ? $body['lis_order_id'] : [];
        $noorders  = is_array($body['noorder'] ?? null) ? $body['noorder'] : [];
        $ditandai  = 0;

        foreach ($noorders as $noorder) {
            $id = Database::scalar('SELECT id FROM orders WHERE khanza_noorder = ? LIMIT 1', [(string) $noorder]);
            if ($id !== null) {
                $orderIds[] = (int) $id;
            }
        }

        foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
            if ($orderId <= 0) {
                continue;
            }

            Database::insert('khanza_sync_log', [
                'arah'      => 'keluar',
                'jenis'     => 'hasil',
                'ref_type'  => 'order',
                'ref_id'    => (string) $orderId,
                'endpoint'  => 'ack-dari-konektor',
                'http_code' => 200,
                'status'    => 'sukses',
                'pesan'     => 'Dikonfirmasi tersimpan oleh konektor Khanza',
            ]);

            // Batalkan entri antrian yang masih menunggu untuk order ini.
            Database::execute(
                "UPDATE khanza_sync_log SET status = 'sukses', pesan = 'Diselesaikan lewat ack konektor'
                 WHERE ref_type = 'order' AND ref_id = ? AND jenis = 'hasil' AND status = 'antri'",
                [(string) $orderId]
            );

            $ditandai++;
        }

        return $this->sukses(['ditandai' => $ditandai], $ditandai . ' order ditandai terkirim');
    }

    /** Status ringkas integrasi — dipakai konektor untuk pemantauan. */
    public function status(): Response
    {
        $statistik = Database::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM orders WHERE khanza_noorder IS NOT NULL AND DATE(tgl_order) = CURDATE()) AS order_khanza_hari_ini,
                (SELECT COUNT(*) FROM orders WHERE khanza_noorder IS NOT NULL AND status = 'verified')          AS menunggu_kirim,
                (SELECT COUNT(*) FROM khanza_sync_log WHERE status = 'antri')                                    AS antrian,
                (SELECT COUNT(*) FROM khanza_templates WHERE test_id IS NULL)                                    AS template_belum_dipetakan"
        ) ?? [];

        return $this->sukses($statistik, 'Status integrasi');
    }
}
