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
use App\Services\OrderService;
use App\Services\ReferenceRangeService;

/**
 * Entri hasil manual dan koreksi hasil.
 *
 * Hasil dari alat masuk lewat API; layar ini dipakai untuk pemeriksaan
 * manual (mikroskopis, rapid test) dan untuk mengoreksi hasil yang keliru.
 */
final class ResultController extends Controller
{
    /** @param array<string,string> $params */
    public function form(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $order   = OrderService::detail($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }
        if (in_array((string) $order['status'], ['cancelled', 'released'], true)) {
            Flash::peringatan('Order berstatus ' . Helper::labelStatus((string) $order['status']) . ' — hasil tidak dapat diubah.');
        }

        $items = OrderService::hasilOrder($orderId);
        $umur  = Helper::umurHari($order['tgl_lahir'] ?? null);

        // Lampirkan nilai rujukan yang berlaku untuk pasien ini.
        foreach ($items as &$item) {
            $ruj                 = ReferenceRangeService::untuk((int) $item['test_id'], $order['jk'] ?? null, $umur);
            $item['rujukan']     = $ruj;
            $item['ref_tampil']  = ReferenceRangeService::teks($ruj);
            $item['opsi']        = $item['pilihan'] === null || $item['pilihan'] === ''
                ? []
                : array_map('trim', explode(',', (string) $item['pilihan']));
            $item['sebelumnya']  = $this->hasilSebelumnya((int) $order['patient_id'], (int) $item['test_id'], $orderId);
        }
        unset($item);

        return $this->view('results/form', [
            'order' => $order,
            'items' => $items,
            'umur'  => $umur,
        ], 'Entri Hasil — ' . $order['no_order']);
    }

    /** @param array<string,string> $params */
    public function simpan(array $params): Response
    {
        $orderId = (int) $params['orderId'];
        $order   = OrderService::detail($orderId);

        if ($order === null) {
            throw new HttpException(404, 'Order tidak ditemukan.');
        }
        if (in_array((string) $order['status'], ['cancelled', 'released'], true)) {
            Flash::error('Order sudah dirilis atau dibatalkan — hasil tidak dapat diubah.');

            return $this->redirect('/order/' . $orderId);
        }

        /** @var array<int|string,mixed> $nilaiInput */
        $nilaiInput   = $this->request->arr('nilai');
        $catatanInput = $this->request->arr('catatan');

        $umur         = Helper::umurHari($order['tgl_lahir'] ?? null);
        $rentangHari  = Config::settingInt('hasil.delta_check_hari', 7);
        $ambangPersen = (float) Config::settingInt('hasil.delta_ambang_persen', 30);

        $disimpan = 0;
        $kritis   = [];

        Database::transaction(function () use (
            $orderId, $order, $nilaiInput, $catatanInput, $umur,
            $rentangHari, $ambangPersen, &$disimpan, &$kritis
        ): void {
            foreach ($nilaiInput as $orderItemId => $nilai) {
                $orderItemId = (int) $orderItemId;
                $nilai       = is_string($nilai) ? trim($nilai) : $nilai;

                $item = Database::selectOne(
                    'SELECT oi.*, t.tipe_hasil, t.desimal, t.satuan, t.kode AS kode_test, t.nama AS nama_test
                     FROM order_items oi JOIN tests t ON t.id = oi.test_id
                     WHERE oi.id = ? AND oi.order_id = ? AND oi.status <> \'cancelled\'',
                    [$orderItemId, $orderId]
                );
                if ($item === null) {
                    continue;
                }

                $existing = Database::selectOne(
                    'SELECT * FROM results WHERE order_item_id = ? LIMIT 1',
                    [$orderItemId]
                );

                // Hasil terverifikasi hanya boleh diubah lewat jalur koreksi.
                if ($existing !== null && in_array((string) $existing['status'], ['verified', 'corrected'], true)) {
                    continue;
                }

                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $testId   = (int) $item['test_id'];
                $ruj      = ReferenceRangeService::untuk($testId, $order['jk'] ?? null, $umur);
                $nilaiNum = null;
                $nilaiStr = (string) $nilai;

                $normal = str_replace(',', '.', $nilaiStr);
                if ($item['tipe_hasil'] === 'numerik' && is_numeric($normal)) {
                    $nilaiNum = (float) $normal;
                    $nilaiStr = Helper::nilai($nilaiNum, (int) $item['desimal']);
                }

                $flag = $nilaiNum !== null
                    ? ReferenceRangeService::flagNumerik($nilaiNum, $ruj)
                    : ReferenceRangeService::flagTeks($nilaiStr, $ruj);

                $delta = ReferenceRangeService::deltaCheck(
                    (int) $order['patient_id'],
                    $testId,
                    $nilaiNum,
                    $rentangHari,
                    $ambangPersen,
                    $orderId
                );

                $adalahKritis = ReferenceRangeService::kritis($flag);

                $data = [
                    'nilai'        => mb_substr($nilaiStr, 0, 255),
                    'nilai_num'    => $nilaiNum,
                    'satuan'       => $item['satuan'],
                    'flag'         => $flag,
                    'ref_low'      => $ruj['low']  ?? null,
                    'ref_high'     => $ruj['high'] ?? null,
                    'ref_teks'     => ReferenceRangeService::teks($ruj),
                    'is_manual'    => 1,
                    'status'       => 'final',
                    'delta_check'  => $delta['status'],
                    'delta_persen' => $delta['persen'],
                    'is_kritis'    => $adalahKritis ? 1 : 0,
                    'catatan'      => isset($catatanInput[$orderItemId])
                        ? mb_substr(trim((string) $catatanInput[$orderItemId]), 0, 255)
                        : null,
                    'entered_by'   => Auth::id(),
                    'entered_at'   => date('Y-m-d H:i:s'),
                ];

                if ($existing === null) {
                    $data['order_id']      = $orderId;
                    $data['order_item_id'] = $orderItemId;
                    $data['test_id']       = $testId;
                    $data['specimen_id']   = $item['specimen_id'];
                    $resultId              = Database::insert('results', $data);
                } else {
                    $resultId = (int) $existing['id'];
                    Database::update('results', $data, 'id = ?', [$resultId]);

                    if ((string) $existing['nilai'] !== (string) $data['nilai']) {
                        Database::insert('result_history', [
                            'result_id'   => $resultId,
                            'nilai_lama'  => $existing['nilai'],
                            'nilai_baru'  => $data['nilai'],
                            'status_lama' => $existing['status'],
                            'status_baru' => 'final',
                            'alasan'      => 'Perubahan pada entri manual',
                            'user_id'     => Auth::id(),
                            'sumber'      => 'manual',
                        ]);
                    }
                }

                Database::update('order_items', ['status' => 'resulted'], 'id = ?', [$orderItemId]);
                $disimpan++;

                if ($adalahKritis) {
                    $kritis[] = $item['nama_test'] . ' = ' . $nilaiStr;
                }
            }
        });

        OrderService::segarkanStatus($orderId);
        Audit::log('entri_hasil', 'order', (string) $orderId, $disimpan . ' hasil disimpan');

        if ($disimpan === 0) {
            Flash::peringatan('Tidak ada hasil yang tersimpan. Hasil yang sudah diverifikasi harus diubah lewat menu Koreksi.');
        } else {
            Flash::sukses($disimpan . ' hasil tersimpan.');
        }

        if ($kritis !== []) {
            Flash::peringatan('NILAI KRITIS terdeteksi: ' . implode('; ', $kritis) . '. Wajib dilaporkan ke DPJP dan dicatat.');
        }

        return $this->redirect($this->request->str('lanjut') === 'verifikasi'
            ? '/verifikasi/' . $orderId
            : '/hasil/' . $orderId . '/entri');
    }

    /**
     * Koreksi hasil yang sudah diverifikasi. Wajib menyertakan alasan;
     * seluruh perubahan tercatat pada result_history.
     *
     * @param array<string,string> $params
     */
    public function koreksi(array $params): Response
    {
        $resultId = (int) $params['id'];
        $nilai    = trim($this->request->str('nilai'));
        $alasan   = trim($this->request->str('alasan'));

        $result = Database::selectOne(
            'SELECT r.*, t.desimal, t.tipe_hasil, o.patient_id, p.jk, p.tgl_lahir, o.id AS oid
             FROM results r
             JOIN tests t ON t.id = r.test_id
             JOIN orders o ON o.id = r.order_id
             JOIN patients p ON p.id = o.patient_id
             WHERE r.id = ? LIMIT 1',
            [$resultId]
        );

        if ($result === null) {
            throw new HttpException(404, 'Hasil tidak ditemukan.');
        }
        if ($nilai === '' || $alasan === '') {
            Flash::error('Nilai baru dan alasan koreksi wajib diisi.');

            return $this->back('/order/' . $result['oid']);
        }

        $umur     = Helper::umurHari($result['tgl_lahir'] ?? null);
        $ruj      = ReferenceRangeService::untuk((int) $result['test_id'], $result['jk'] ?? null, $umur);
        $nilaiNum = null;
        $normal   = str_replace(',', '.', $nilai);

        if ($result['tipe_hasil'] === 'numerik' && is_numeric($normal)) {
            $nilaiNum = (float) $normal;
            $nilai    = Helper::nilai($nilaiNum, (int) $result['desimal']);
        }

        $flag = $nilaiNum !== null
            ? ReferenceRangeService::flagNumerik($nilaiNum, $ruj)
            : ReferenceRangeService::flagTeks($nilai, $ruj);

        Database::transaction(static function () use ($resultId, $result, $nilai, $nilaiNum, $flag, $ruj, $alasan): void {
            Database::update('results', [
                'nilai'       => mb_substr($nilai, 0, 255),
                'nilai_num'   => $nilaiNum,
                'flag'        => $flag,
                'ref_low'     => $ruj['low']  ?? null,
                'ref_high'    => $ruj['high'] ?? null,
                'ref_teks'    => ReferenceRangeService::teks($ruj),
                'is_kritis'   => ReferenceRangeService::kritis($flag) ? 1 : 0,
                'status'      => 'corrected',
                'verified_by' => Auth::id(),
                'verified_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$resultId]);

            Database::insert('result_history', [
                'result_id'   => $resultId,
                'nilai_lama'  => $result['nilai'],
                'nilai_baru'  => $nilai,
                'status_lama' => $result['status'],
                'status_baru' => 'corrected',
                'alasan'      => mb_substr($alasan, 0, 255),
                'user_id'     => Auth::id(),
                'sumber'      => 'koreksi',
            ]);
        });

        Audit::log(
            'koreksi_hasil',
            'result',
            (string) $resultId,
            'Dari "' . $result['nilai'] . '" menjadi "' . $nilai . '". Alasan: ' . $alasan
        );

        Flash::sukses('Hasil dikoreksi. Perubahan tercatat pada riwayat hasil.');
        Flash::peringatan('Bila lembar hasil sudah dicetak/dikirim ke SIMRS, kirim ulang hasil yang telah dikoreksi.');

        return $this->back('/order/' . $result['oid']);
    }

    /**
     * Catat pelaporan nilai kritis ke DPJP.
     *
     * @param array<string,string> $params
     */
    public function laporKritis(array $params): Response
    {
        $resultId = (int) $params['id'];
        $kepada   = trim($this->request->str('dilapor_ke'));

        if ($kepada === '') {
            Flash::error('Nama penerima laporan wajib diisi.');

            return $this->back('/');
        }

        $result = Database::selectOne('SELECT * FROM results WHERE id = ?', [$resultId]);
        if ($result === null) {
            throw new HttpException(404, 'Hasil tidak ditemukan.');
        }

        Database::update('results', [
            'kritis_dilapor_ke' => mb_substr($kepada, 0, 100),
            'kritis_dilapor_at' => date('Y-m-d H:i:s'),
            'kritis_dilapor_by' => Auth::id(),
        ], 'id = ?', [$resultId]);

        Audit::log('lapor_nilai_kritis', 'result', (string) $resultId, 'Dilaporkan kepada: ' . $kepada);
        Flash::sukses('Pelaporan nilai kritis tercatat.');

        return $this->back('/');
    }

    /** @param array<string,string> $params */
    public function riwayat(array $params): Response
    {
        $resultId = (int) $params['id'];

        $result = Database::selectOne(
            'SELECT r.*, t.nama AS nama_test, t.satuan AS satuan_master,
                    o.no_order, o.id AS oid, p.nama AS nama_pasien
             FROM results r
             JOIN tests t ON t.id = r.test_id
             JOIN orders o ON o.id = r.order_id
             JOIN patients p ON p.id = o.patient_id
             WHERE r.id = ?',
            [$resultId]
        );

        if ($result === null) {
            throw new HttpException(404, 'Hasil tidak ditemukan.');
        }

        $riwayat = Database::select(
            'SELECT h.*, u.nama AS oleh FROM result_history h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.result_id = ? ORDER BY h.id DESC',
            [$resultId]
        );

        return $this->view('results/riwayat', [
            'result'  => $result,
            'riwayat' => $riwayat,
        ], 'Riwayat Perubahan Hasil');
    }

    /** @return array<int,array<string,mixed>> */
    private function hasilSebelumnya(int $patientId, int $testId, int $kecualiOrderId): array
    {
        return Database::select(
            'SELECT r.nilai, r.flag, r.created_at, o.no_lab
             FROM results r JOIN orders o ON o.id = r.order_id
             WHERE o.patient_id = ? AND r.test_id = ? AND r.order_id <> ?
               AND r.status IN (\'verified\',\'corrected\',\'final\')
             ORDER BY r.created_at DESC LIMIT 3',
            [$patientId, $testId, $kecualiOrderId]
        );
    }
}
