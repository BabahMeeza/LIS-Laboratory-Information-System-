<?php
declare(strict_types=1);

namespace App\Api\V1;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Services\ResultProcessor;

/**
 * API untuk middleware alat laboratorium.
 *
 * Middleware Node.js memakai endpoint ini untuk:
 *   1. mengambil konfigurasi koneksi seluruh alat aktif
 *   2. melaporkan denyut hidup dan status koneksi tiap alat
 *   3. mengirim hasil yang sudah dinormalisasi
 *   4. mengambil worklist untuk alat bidirectional (host query)
 */
final class InstrumentApi extends ApiController
{
    /**
     * Konfigurasi seluruh alat aktif.
     * Middleware memanggil ini saat start dan setiap kali di-reload.
     */
    public function daftar(): Response
    {
        $rows = Database::select(
            "SELECT id, kode, nama, merk, model, protokol, transport, mode,
                    host, port, serial_port, baud_rate, data_bits, stop_bits,
                    parity, flow_control, encoding,
                    folder_keluar, folder_masuk, folder_jeda_detik,
                    COALESCE(bedakan_tipe_nilai, 0) AS bedakan_tipe_nilai
             FROM instruments WHERE aktif = 1 ORDER BY nama"
        );

        $alat = [];
        foreach ($rows as $row) {
            $alat[] = [
                'id'        => (int) $row['id'],
                'code'      => (string) $row['kode'],
                'name'      => (string) $row['nama'],
                'vendor'    => $row['merk'],
                'model'     => $row['model'],
                'protocol'  => (string) $row['protokol'],
                'transport' => (string) $row['transport'],
                'mode'      => (string) $row['mode'],
                'encoding'  => (string) $row['encoding'],
                // Alat yang memakai satu kode untuk dua arti, dibedakan
                // hanya lewat OBX-2 (mis. MediGo URO: WBC sedimen vs WBC
                // carik celup). Lihat middleware/src/protocols/hl7.js.
                'bedakan_tipe_nilai' => (int) $row['bedakan_tipe_nilai'] === 1,
                'tcp'       => [
                    'host' => $row['host'],
                    'port' => $row['port'] === null ? null : (int) $row['port'],
                ],
                // Alat berbasis berkas (BioSystems A15). Jalur folder
                // dikirim apa adanya; middleware yang memeriksa
                // keterbacaannya, karena hanya mesin itu yang tahu
                // apakah berbagi jaringannya terpasang.
                'file'      => [
                    'folderKeluar' => $row['folder_keluar'],
                    'folderMasuk'  => $row['folder_masuk'],
                    'jedaMs'       => max(2, (int) ($row['folder_jeda_detik'] ?? 5)) * 1000,
                ],
                'serial'    => [
                    'path'        => $row['serial_port'],
                    'baudRate'    => (int) $row['baud_rate'],
                    'dataBits'    => (int) $row['data_bits'],
                    'stopBits'    => (int) $row['stop_bits'],
                    'parity'      => (string) $row['parity'],
                    'flowControl' => (string) $row['flow_control'],
                ],
            ];
        }

        return $this->sukses([
            'instruments'       => $alat,
            'heartbeat_seconds' => Config::settingInt('middleware.heartbeat_detik', 30),
        ], count($alat) . ' alat aktif');
    }

    /**
     * Denyut hidup middleware + status koneksi per alat.
     *
     * Body: { "instruments": [ { "code":"HEMA-01", "status":"online", "error":null } ] }
     */
    public function heartbeat(): Response
    {
        $body  = $this->body();
        $items = is_array($body['instruments'] ?? null) ? $body['instruments'] : [];
        $n     = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kode = trim((string) ($item['code'] ?? ''));
            if ($kode === '') {
                continue;
            }

            $status = (string) ($item['status'] ?? 'offline');
            if (!in_array($status, ['online', 'offline', 'error'], true)) {
                $status = 'offline';
            }

            $n += Database::update('instruments', [
                'status_koneksi' => $status,
                'last_seen_at'   => date('Y-m-d H:i:s'),
                'last_error'     => isset($item['error']) && $item['error'] !== null
                    ? mb_substr((string) $item['error'], 0, 255)
                    : null,
            ], 'kode = ?', [$kode]);
        }

        return $this->sukses(['diperbarui' => $n], 'Heartbeat diterima');
    }

    /**
     * Terima hasil dari alat.
     *
     * Body menerima satu payload atau daftar payload:
     *   { "instrument_code":"...", "protocol":"astm", "raw":"...", "samples":[ ... ] }
     *   atau { "messages": [ {…}, {…} ] }
     */
    public function hasil(): Response
    {
        $body = $this->body();

        $daftar = is_array($body['messages'] ?? null) ? $body['messages'] : [$body];
        $hasil  = [];
        $errors = 0;

        foreach ($daftar as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            try {
                $hasil[] = ResultProcessor::proses($payload);
            } catch (\Throwable $e) {
                $errors++;
                Logger::exception($e);
                $hasil[] = [
                    'message_id' => 0,
                    'status'     => 'error',
                    'total'      => 0,
                    'tersimpan'  => 0,
                    'detail'     => [],
                    'error'      => $e->getMessage(),
                ];
            }
        }

        if ($hasil === []) {
            return $this->gagal('Payload kosong atau tidak dikenali.', 422);
        }

        $totalTersimpan = array_sum(array_column($hasil, 'tersimpan'));
        $totalHasil     = array_sum(array_column($hasil, 'total'));

        // 207 dipakai bila sebagian pesan gagal — middleware tetap
        // menganggapnya terkirim dan tidak perlu mengulang.
        $status = $errors > 0 ? 207 : 200;

        return $this->sukses([
            'pesan_diproses' => count($hasil),
            'total_hasil'    => $totalHasil,
            'tersimpan'      => $totalTersimpan,
            'rincian'        => $hasil,
        ], sprintf('%d dari %d hasil tersimpan', $totalTersimpan, $totalHasil), $status);
    }

    /**
     * Simpan potongan komunikasi mentah tanpa memproses hasil.
     * Berguna untuk merekam sesi ASTM/HL7 saat penelusuran masalah.
     */
    public function pesanMentah(): Response
    {
        $body = $this->body();
        $kode = trim((string) ($body['instrument_code'] ?? ''));

        $instrumentId = $kode === '' ? null : Database::scalar(
            'SELECT id FROM instruments WHERE kode = ? LIMIT 1',
            [$kode]
        );

        $id = Database::insert('instrument_messages', [
            'instrument_id' => $instrumentId === null ? null : (int) $instrumentId,
            'kode_alat'     => $kode !== '' ? $kode : null,
            'arah'          => ($body['direction'] ?? 'in') === 'out' ? 'out' : 'in',
            'protokol'      => isset($body['protocol']) ? mb_substr((string) $body['protocol'], 0, 10) : null,
            'sample_id'     => isset($body['sample_id']) ? mb_substr((string) $body['sample_id'], 0, 60) : null,
            'raw'           => (string) ($body['raw'] ?? ''),
            'status'        => 'diabaikan',
        ]);

        return $this->sukses(['message_id' => $id], 'Pesan mentah tersimpan');
    }

    /**
     * Host query: alat menanyakan pemeriksaan apa yang harus dikerjakan
     * untuk sebuah sample ID.
     *
     * @param array<string,string> $params
     */
    public function worklistSampel(array $params): Response
    {
        $sampleId = trim((string) ($params['sampleId'] ?? ''));
        $kodeAlat = $this->request->str('instrument');

        if ($sampleId === '') {
            return $this->gagal('Sample ID kosong.', 422);
        }

        // ----------------------------------------------------------------
        // Pencarian bertingkat — JANGAN disatukan menjadi satu OR.
        //
        // Satu deret angka yang sama dapat menjadi barcode tabung milik
        // seorang pasien SEKALIGUS nomor laboratorium milik pasien lain.
        // Penomoran keduanya berjalan sendiri-sendiri, jadi tabrakan itu
        // bukan kemungkinan teoretis — pada data uji ini pun terjadi:
        //
        //   2609020006  → barcode tabung pada order LIS-260902-0003
        //   2609020006  → no_lab pada order LIS-260902-0006
        //
        // Kueri yang meng-OR ketiganya lalu memilih "ORDER BY o.id DESC
        // LIMIT 1" akan mengembalikan order yang lebih baru — yaitu
        // pasien yang SALAH — tanpa satu pun tanda bahwa ada tabrakan.
        // Alat kemudian menampilkan nama pasien lain untuk tabung itu.
        //
        // Karena itu: barcode diperiksa lebih dulu dan sendirian. Barcode
        // adalah identitas fisik tabung dan bersifat unik; itulah yang
        // benar-benar ditempel pada spesimen yang sedang dihisap alat.
        // Nomor lab dan nomor order hanya dipakai sebagai cadangan bila
        // barcode tidak cocok sama sekali.
        // ----------------------------------------------------------------
        $pilih = "SELECT o.id AS order_id, o.no_order, o.no_lab, o.prioritas,
                         o.dokter_perujuk, o.nama_ruang, o.kode_ruang, o.tgl_order,
                         o.diagnosa_klinis, o.informasi_tambahan,
                         p.nama AS nama_pasien, p.no_rm, p.jk, p.tgl_lahir,
                         s.id AS specimen_id, s.barcode,
                         s.collected_at, s.received_at,
                         st.kode AS kode_spesimen, st.nama AS nama_spesimen,
                         uk.nama AS nama_pengambil, ut.nama AS nama_penerima
                    FROM specimens s
                    JOIN orders o   ON o.id = s.order_id
                    JOIN patients p ON p.id = o.patient_id
               LEFT JOIN specimen_types st ON st.id = s.specimen_type_id
               LEFT JOIN users uk ON uk.id = s.collected_by
               LEFT JOIN users ut ON ut.id = s.received_by
                   WHERE %s
                     AND o.status NOT IN ('cancelled','released')";

        $konteks = Database::selectOne(
            sprintf($pilih, 's.barcode = ?') . ' LIMIT 1',
            [$sampleId]
        );

        if ($konteks === null) {
            // Cadangan: nomor lab / nomor order. Di sini satu nilai dapat
            // menaungi beberapa tabung, jadi keduaduaan harus diperiksa —
            // bila menunjuk lebih dari satu ORDER, permintaan ditolak.
            // Menebak di antara dua pasien jauh lebih berbahaya daripada
            // menyuruh petugas memindai ulang barcodenya.
            $calon = Database::select(
                sprintf($pilih, '(o.no_lab = ? OR o.no_order = ?)')
                . ' ORDER BY s.id ASC',
                [$sampleId, $sampleId]
            );

            $orderUnik = array_unique(array_map(
                static fn (array $r): int => (int) $r['order_id'],
                $calon
            ));

            if (count($orderUnik) > 1) {
                Logger::warning(
                    'Permintaan worklist ditolak: Sample ID menunjuk lebih dari satu order.',
                    [
                        'sample_id' => $sampleId,
                        'order'     => array_values($orderUnik),
                        'instrument'=> $kodeAlat,
                    ]
                );

                return $this->gagal(
                    'Sample ID "' . $sampleId . '" menunjuk lebih dari satu order — '
                    . 'permintaan tidak dijawab agar tidak tertukar pasien. '
                    . 'Pindai barcode tabung, bukan nomor laboratorium.',
                    409,
                    ['sample_id' => $sampleId, 'tests' => []]
                );
            }

            $konteks = $calon[0] ?? null;
        }

        if ($konteks === null) {
            return $this->gagal('Sample ID "' . $sampleId . '" tidak ditemukan.', 404, [
                'sample_id' => $sampleId,
                'tests'     => [],
            ]);
        }

        $instrumentId = $kodeAlat === '' ? null : Database::scalar(
            'SELECT id FROM instruments WHERE kode = ? LIMIT 1',
            [$kodeAlat]
        );

        // Bila alat diketahui, kembalikan kode versi alat; jika tidak,
        // kembalikan kode LIS agar tetap berguna.
        if ($instrumentId !== null) {
            $rows = Database::select(
                "SELECT DISTINCT m.kode_alat AS kode, t.nama
                 FROM order_items oi
                 JOIN tests t ON t.id = oi.test_id
                 JOIN instrument_test_map m ON m.test_id = t.id AND m.instrument_id = ? AND m.abaikan = 0
                 LEFT JOIN results r ON r.order_item_id = oi.id
                 WHERE oi.order_id = ? AND oi.status <> 'cancelled' AND r.id IS NULL
                 ORDER BY m.kode_alat",
                [(int) $instrumentId, $konteks['order_id']]
            );
        } else {
            $rows = Database::select(
                "SELECT t.kode, t.nama
                 FROM order_items oi
                 JOIN tests t ON t.id = oi.test_id
                 LEFT JOIN results r ON r.order_item_id = oi.id
                 WHERE oi.order_id = ? AND oi.status <> 'cancelled' AND r.id IS NULL
                 ORDER BY t.urut",
                [$konteks['order_id']]
            );
        }

        return $this->sukses([
            'sample_id' => (string) $konteks['barcode'],
            'order_no'  => (string) $konteks['no_order'],
            'lab_no'    => (string) $konteks['no_lab'],
            'priority'  => (string) $konteks['prioritas'] === 'cito' ? 'S' : 'R',
            'patient'   => [
                'id'        => (string) $konteks['no_rm'],
                'name'      => (string) $konteks['nama_pasien'],
                'sex'       => (string) $konteks['jk'],
                'birthdate' => $konteks['tgl_lahir'],
            ],
            // Analyzer seperti Mindray BC-5000 menampilkan medan Clinician,
            // Department, Draw Time, dan Delivery Time pada layar entri
            // sampelnya. Data itu sudah ada di LIS, jadi ikut dikirim agar
            // operator tidak mengetik ulang apa yang sudah tercatat.
            'clinician'  => (string) ($konteks['dokter_perujuk'] ?? ''),
            'department' => (string) ($konteks['nama_ruang'] ?? ''),
            'bed'        => '',
            'diagnosis'  => (string) ($konteks['diagnosa_klinis'] ?? ''),
            'remark'     => (string) ($konteks['informasi_tambahan'] ?? ''),

            // Waktu pengambilan dan waktu penyerahan spesimen.
            //
            // Sebelumnya "drawn_at" diisi dari orders.tgl_order — itu waktu
            // DOKTER MEMESAN, bukan waktu darah diambil. Keduanya bisa
            // berselisih berjam-jam pada pasien rawat inap. Alat menampilkan
            // nilai itu sebagai "Draw Time", jadi mengisinya dengan waktu
            // order berarti menampilkan angka yang salah dengan percaya diri.
            //
            // Sumber yang benar ada pada spesimen: collected_at (diambil)
            // dan received_at (diserahkan ke lab).
            'drawn_at'     => $konteks['collected_at'],
            'delivered_at' => $konteks['received_at'],
            'collector'    => (string) ($konteks['nama_pengambil'] ?? ''),
            'deliverer'    => (string) ($konteks['nama_penerima'] ?? ''),

            // Sumber spesimen — dipakai alat pada OBR-15 (BLDV/BLDC).
            'specimen_code' => (string) ($konteks['kode_spesimen'] ?? ''),
            'specimen_name' => (string) ($konteks['nama_spesimen'] ?? ''),

            'tests'      => array_column($rows, 'kode'),
            'test_info'  => $rows,
        ], count($rows) . ' pemeriksaan menunggu');
    }

    /**
     * Antrian worklist yang menunggu dikirim ke sebuah alat.
     * Middleware menandainya terkirim lewat parameter ?ack=1.
     *
     * @param array<string,string> $params
     */
    public function worklistAlat(array $params): Response
    {
        $kode = trim((string) ($params['kode'] ?? ''));
        $alat = Database::selectOne('SELECT * FROM instruments WHERE kode = ? AND aktif = 1', [$kode]);

        if ($alat === null) {
            return $this->gagal('Alat "' . $kode . '" tidak ditemukan atau nonaktif.', 404);
        }

        // Konfirmasi per baris, dipakai alat berbasis berkas (A15).
        //
        // ?ack=1 menandai terkirim SEMUA yang baru diambil, sebelum
        // middleware sempat menulisnya. Bagi soket itu cukup, karena
        // pengiriman terjadi seketika. Bagi berkas tidak: bila import.txt
        // gagal ditulis (folder putus, izin ditolak), sampel itu hilang
        // dari antrian padahal tidak pernah sampai ke alat. Karena itu
        // middleware mengambil tanpa ack, menulis, lalu melaporkan id mana
        // yang benar-benar tertulis dan mana yang ditolak.
        $ackIds   = $this->daftarId($this->request->str('ack_ids'));
        $gagalIds = $this->daftarId($this->request->str('gagal_ids'));

        if ($ackIds !== [] || $gagalIds !== []) {
            foreach (['terkirim' => $ackIds, 'gagal' => $gagalIds] as $status => $ids) {
                if ($ids === []) {
                    continue;
                }
                $tanda = implode(',', array_fill(0, count($ids), '?'));
                Database::execute(
                    "UPDATE instrument_worklist SET status = ?, dikirim_at = NOW()
                     WHERE instrument_id = ? AND status = 'menunggu' AND id IN ($tanda)",
                    array_merge([$status, $alat['id']], $ids)
                );
            }

            return $this->sukses([], count($ackIds) . ' terkirim, ' . count($gagalIds) . ' gagal');
        }

        $limit = max(1, min(200, $this->request->int('limit', 50)));

        $rows = Database::select(
            "SELECT id, sample_id, payload FROM instrument_worklist
             WHERE instrument_id = ? AND status = 'menunggu'
             ORDER BY id ASC LIMIT $limit",
            [$alat['id']]
        );

        $data = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $data[]  = [
                'queue_id'  => (int) $row['id'],
                'sample_id' => (string) $row['sample_id'],
                'patient'   => $payload['patient'] ?? null,
                'tests'     => $payload['tests'] ?? [],
            ];
        }

        // Tandai terkirim bila middleware meminta.
        if ($this->request->bool('ack') && $rows !== []) {
            $ids   = array_column($rows, 'id');
            $tanda = implode(',', array_fill(0, count($ids), '?'));
            Database::execute(
                "UPDATE instrument_worklist SET status = 'terkirim', dikirim_at = NOW() WHERE id IN ($tanda)",
                $ids
            );
        }

        return $this->sukses($data, count($data) . ' sampel dalam antrian');
    }

    /** @return list<int> "1,2,3" -> [1,2,3]; yang bukan angka dibuang. */
    private function daftarId(string $teks): array
    {
        if (trim($teks) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $teks)),
            static fn (int $x): bool => $x > 0
        )));
    }
}
