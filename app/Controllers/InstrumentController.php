<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\ResultProcessor;

/**
 * Pengelolaan alat laboratorium: konfigurasi koneksi, pemetaan kode
 * parameter, log komunikasi mentah, dan penanganan hasil menggantung.
 */
final class InstrumentController extends Controller
{
    public function index(): Response
    {
        $alat = Database::select(
            "SELECT i.*, tc.nama AS kategori,
                    (SELECT COUNT(*) FROM instrument_test_map m WHERE m.instrument_id = i.id AND m.test_id IS NOT NULL) AS jml_pemetaan,
                    (SELECT COUNT(*) FROM instrument_test_map m WHERE m.instrument_id = i.id AND m.test_id IS NULL AND m.abaikan = 0) AS belum_dipetakan,
                    (SELECT COUNT(*) FROM instrument_messages m WHERE m.instrument_id = i.id AND DATE(m.created_at) = CURDATE()) AS pesan_hari_ini,
                    (SELECT COUNT(*) FROM instrument_messages m WHERE m.instrument_id = i.id AND m.status IN ('error','tidak_cocok') AND DATE(m.created_at) = CURDATE()) AS error_hari_ini
             FROM instruments i
             LEFT JOIN test_categories tc ON tc.id = i.category_id
             ORDER BY i.aktif DESC, i.nama"
        );

        // Status middleware (opsional — hanya informatif).
        $middleware = ['ok' => false, 'pesan' => 'Tidak diperiksa'];
        $url        = (string) Config::get('middleware.health_url', '');
        if ($url !== '') {
            $res = Http::get($url, [], (int) Config::get('middleware.timeout', 3), false);
            $middleware = [
                'ok'    => $res['ok'],
                'pesan' => $res['ok'] ? 'Middleware berjalan' : 'Middleware tidak merespons (' . ($res['error'] ?? 'HTTP ' . $res['status']) . ')',
                'data'  => $res['data'],
            ];
        }

        $menggantung = (int) Database::scalar("SELECT COUNT(*) FROM orphan_results WHERE status = 'menunggu'");

        return $this->view('instruments/index', [
            'alat'        => $alat,
            'middleware'  => $middleware,
            'menggantung' => $menggantung,
        ], 'Alat Laboratorium');
    }

    /** @param array<string,string> $params */
    public function form(array $params = []): Response
    {
        $alat = null;
        if (isset($params['id'])) {
            $alat = Database::selectOne('SELECT * FROM instruments WHERE id = ?', [(int) $params['id']]);
            if ($alat === null) {
                throw new HttpException(404, 'Alat tidak ditemukan.');
            }
        }

        $kategori = Database::select('SELECT * FROM test_categories WHERE aktif = 1 ORDER BY urut');

        return $this->view('instruments/form', [
            'alat'     => $alat,
            'kategori' => $kategori,
        ], $alat === null ? 'Tambah Alat' : 'Ubah Alat: ' . $alat['nama']);
    }

    /** @param array<string,string> $params */
    public function simpan(array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        $data = $this->validasi([
            'kode'      => 'required|max:30',
            'nama'      => 'required|max:100',
            'protokol'  => 'required|in:astm,hl7,raw',
            'transport' => 'required|in:tcp_server,tcp_client,serial',
            'mode'      => 'required|in:unidirectional,bidirectional',
        ], ['kode' => 'Kode alat', 'nama' => 'Nama alat']);

        if ($data === null) {
            return $this->redirect($id > 0 ? "/alat/$id/edit" : '/alat/baru');
        }

        $transport = $this->request->str('transport');

        $simpan = [
            'kode'          => $this->request->str('kode'),
            'nama'          => $this->request->str('nama'),
            'merk'          => $this->request->str('merk') ?: null,
            'model'         => $this->request->str('model') ?: null,
            'serial_number' => $this->request->str('serial_number') ?: null,
            'category_id'   => $this->request->int('category_id') ?: null,
            'protokol'      => $this->request->str('protokol'),
            'transport'     => $transport,
            'mode'          => $this->request->str('mode'),
            'host'          => $transport === 'serial' ? null : ($this->request->str('host') ?: null),
            'port'          => $transport === 'serial' ? null : ($this->request->int('port') ?: null),
            'serial_port'   => $transport === 'serial' ? ($this->request->str('serial_port') ?: null) : null,
            'baud_rate'     => $this->request->int('baud_rate', 9600),
            'data_bits'     => $this->request->int('data_bits', 8),
            'stop_bits'     => $this->request->int('stop_bits', 1),
            'parity'        => $this->request->str('parity', 'none'),
            'flow_control'  => $this->request->str('flow_control', 'none'),
            'encoding'      => $this->request->str('encoding', 'latin1'),
            'auto_verify'   => $this->request->bool('auto_verify') ? 1 : 0,
            'auto_verify_max_flag' => $this->request->str('auto_verify_max_flag', 'N'),
            'simpan_raw'    => $this->request->bool('simpan_raw', true) ? 1 : 0,
            'aktif'         => $this->request->bool('aktif') ? 1 : 0,
        ];

        // Validasi khusus per jenis transport.
        if ($transport !== 'serial' && ($simpan['port'] === null || $simpan['port'] <= 0)) {
            Flash::error('Port TCP wajib diisi untuk transport TCP.');

            return $this->redirect($id > 0 ? "/alat/$id/edit" : '/alat/baru');
        }
        if ($transport === 'serial' && $simpan['serial_port'] === null) {
            Flash::error('Nama port serial wajib diisi (mis. COM3 atau /dev/tty.usbserial-1410).');

            return $this->redirect($id > 0 ? "/alat/$id/edit" : '/alat/baru');
        }

        $bentrok = Database::selectOne(
            'SELECT id FROM instruments WHERE kode = ? AND id <> ?',
            [$simpan['kode'], $id]
        );
        if ($bentrok !== null) {
            Flash::error('Kode alat "' . $simpan['kode'] . '" sudah dipakai.');

            return $this->redirect($id > 0 ? "/alat/$id/edit" : '/alat/baru');
        }

        // Medan Host pada tcp_server berarti antarmuka jaringan SERVER INI
        // yang didengarkan — bukan alamat analyzer. Diisi alamat analyzer,
        // middleware gagal dengan EADDRNOTAVAIL jauh dari layar ini sehingga
        // sebabnya sulit dikaitkan. Karena itu ditolak di sini.
        if ($transport === 'tcp_server'
            && $simpan['host'] !== null
            && !in_array($simpan['host'], ['0.0.0.0', '127.0.0.1', 'localhost', '::', '::1'], true)
        ) {
            Flash::error(
                'Host "' . $simpan['host'] . '" tidak dapat dipakai untuk TCP Server. '
                . 'Medan ini adalah antarmuka jaringan server LIS yang didengarkan, '
                . 'BUKAN alamat IP analyzer — isi 0.0.0.0 agar seluruh antarmuka '
                . 'didengarkan. Alamat analyzer tidak diisi di LIS sama sekali; '
                . 'justru alamat server inilah yang dimasukkan sebagai "Host IP" '
                . 'pada menu komunikasi analyzer.'
            );

            return $this->redirect($id > 0 ? "/alat/$id/edit" : '/alat/baru');
        }

        // Satu port hanya bisa didengarkan satu alat. Tanpa pemeriksaan ini,
        // alat kedua gagal dengan EADDRINUSE dan "tidak pernah menerima apa
        // pun" — gejala yang sulit dikenali karena alat pertama tampak sehat.
        if ($transport === 'tcp_server' && (int) $simpan['aktif'] === 1) {
            $portBentrok = Database::selectOne(
                "SELECT kode, nama FROM instruments
                 WHERE transport = 'tcp_server' AND port = ? AND aktif = 1 AND id <> ?
                 LIMIT 1",
                [$simpan['port'], $id]
            );

            if ($portBentrok !== null) {
                Flash::error(
                    'Port ' . $simpan['port'] . ' sudah dipakai alat aktif "'
                    . $portBentrok['kode'] . ' — ' . $portBentrok['nama']
                    . '". Setiap alat memerlukan port sendiri, mis. 5100, 5200, 5300.'
                );

                return $this->redirect($id > 0 ? "/alat/$id/edit" : '/alat/baru');
            }
        }

        if ($id > 0) {
            Database::update('instruments', $simpan, 'id = ?', [$id]);
            Audit::log('ubah_alat', 'instrument', (string) $id, $simpan['nama']);
            Flash::sukses('Konfigurasi alat diperbarui. Muat ulang middleware agar perubahan koneksi diterapkan.');
        } else {
            $id = Database::insert('instruments', $simpan);
            Audit::log('tambah_alat', 'instrument', (string) $id, $simpan['nama']);
            Flash::sukses('Alat ditambahkan. Jalankan ulang middleware agar koneksi dibuka.');
        }

        Flash::bersihkanInput();

        return $this->redirect('/alat/' . $id . '/pemetaan');
    }

    /**
     * Layar pemetaan kode parameter alat → pemeriksaan LIS.
     *
     * @param array<string,string> $params
     */
    public function pemetaan(array $params): Response
    {
        $id   = (int) $params['id'];
        $alat = Database::selectOne('SELECT * FROM instruments WHERE id = ?', [$id]);

        if ($alat === null) {
            throw new HttpException(404, 'Alat tidak ditemukan.');
        }

        $pemetaan = Database::select(
            'SELECT m.*, t.kode AS kode_test, t.nama AS nama_test, t.satuan AS satuan_test
             FROM instrument_test_map m
             LEFT JOIN tests t ON t.id = m.test_id
             WHERE m.instrument_id = ?
             ORDER BY (m.test_id IS NULL AND m.abaikan = 0) DESC, m.kode_alat',
            [$id]
        );

        $tests = Database::select(
            'SELECT t.id, t.kode, t.nama, t.satuan, tc.nama AS kategori
             FROM tests t LEFT JOIN test_categories tc ON tc.id = t.category_id
             WHERE t.aktif = 1 ORDER BY tc.urut, t.urut, t.nama'
        );

        return $this->view('instruments/pemetaan', [
            'alat'     => $alat,
            'pemetaan' => $pemetaan,
            'tests'    => $tests,
        ], 'Pemetaan Parameter — ' . $alat['nama']);
    }

    /** @param array<string,string> $params */
    public function simpanPemetaan(array $params): Response
    {
        $id = (int) $params['id'];

        // Baris yang sudah ada.
        $testIds = $this->request->arr('test_id');
        $faktor  = $this->request->arr('faktor');
        $abaikan = $this->request->arr('abaikan');

        $diperbarui = 0;
        foreach ($testIds as $mapId => $testId) {
            $mapId  = (int) $mapId;
            $testId = (int) $testId;

            Database::update('instrument_test_map', [
                'test_id' => $testId > 0 ? $testId : null,
                'faktor'  => isset($faktor[$mapId]) && is_numeric($faktor[$mapId]) ? (float) $faktor[$mapId] : 1,
                'abaikan' => isset($abaikan[$mapId]) ? 1 : 0,
            ], 'id = ? AND instrument_id = ?', [$mapId, $id]);
            $diperbarui++;
        }

        // Baris baru yang diketik manual.
        $kodeBaru = trim($this->request->str('kode_baru'));
        $testBaru = $this->request->int('test_baru');
        if ($kodeBaru !== '' && $testBaru > 0) {
            Database::execute(
                'INSERT INTO instrument_test_map (instrument_id, kode_alat, test_id)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE test_id = VALUES(test_id), abaikan = 0',
                [$id, $kodeBaru, $testBaru]
            );
            $diperbarui++;
        }

        Audit::log('simpan_pemetaan_alat', 'instrument', (string) $id, $diperbarui . ' baris pemetaan');
        Flash::sukses('Pemetaan parameter disimpan (' . $diperbarui . ' baris).');

        return $this->redirect('/alat/' . $id . '/pemetaan');
    }

    /** Log komunikasi mentah dari alat. */
    public function log(): Response
    {
        $alatId = $this->request->int('alat');
        $status = $this->request->str('status');
        $cari   = $this->request->str('q');

        $where = ['1=1'];
        $bind  = [];

        if ($alatId > 0) {
            $where[] = 'm.instrument_id = ?';
            $bind[]  = $alatId;
        }
        if ($status !== '') {
            $where[] = 'm.status = ?';
            $bind[]  = $status;
        }
        if ($cari !== '') {
            $where[] = '(m.sample_id LIKE ? OR m.raw LIKE ?)';
            $like    = '%' . $cari . '%';
            array_push($bind, $like, $like);
        }

        $sqlWhere = implode(' AND ', $where);

        $log = Database::select(
            "SELECT m.id, m.instrument_id, m.kode_alat, m.arah, m.protokol, m.sample_id,
                    m.status, m.jml_hasil, m.jml_tersimpan, m.pesan_error, m.created_at,
                    i.nama AS nama_alat,
                    CHAR_LENGTH(COALESCE(m.raw,'')) AS panjang_raw
             FROM instrument_messages m
             LEFT JOIN instruments i ON i.id = m.instrument_id
             WHERE $sqlWhere
             ORDER BY m.id DESC
             LIMIT 200",
            $bind
        );

        $alat = Database::select('SELECT id, nama FROM instruments ORDER BY nama');

        return $this->view('instruments/log', [
            'log'    => $log,
            'alat'   => $alat,
            'filter' => compact('alatId', 'status', 'cari'),
        ], 'Log Komunikasi Alat');
    }

    /** @param array<string,string> $params */
    public function logDetail(array $params): Response
    {
        $id  = (int) $params['id'];
        $row = Database::selectOne(
            'SELECT m.*, i.nama AS nama_alat FROM instrument_messages m
             LEFT JOIN instruments i ON i.id = m.instrument_id
             WHERE m.id = ?',
            [$id]
        );

        if ($row === null) {
            throw new HttpException(404, 'Log tidak ditemukan.');
        }

        return $this->view('instruments/log_detail', ['row' => $row], 'Detail Pesan #' . $id);
    }

    /**
     * Proses ulang sebuah pesan alat — berguna setelah pemetaan
     * parameter dilengkapi atau order yang hilang sudah dibuat.
     *
     * @param array<string,string> $params
     */
    public function prosesUlang(array $params): Response
    {
        $id  = (int) $params['id'];
        $row = Database::selectOne('SELECT * FROM instrument_messages WHERE id = ?', [$id]);

        if ($row === null) {
            throw new HttpException(404, 'Log tidak ditemukan.');
        }

        $payload = json_decode((string) $row['parsed'], true);
        if (!is_array($payload)) {
            Flash::error('Pesan ini tidak menyimpan payload terparse, tidak dapat diproses ulang.');

            return $this->redirect('/alat/log/' . $id);
        }

        $hasil = ResultProcessor::proses($payload);
        Audit::log('proses_ulang_pesan', 'instrument_message', (string) $id, 'Tersimpan: ' . $hasil['tersimpan']);

        Flash::sukses(sprintf(
            'Diproses ulang: %d dari %d hasil tersimpan (status: %s). Pesan baru #%d.',
            $hasil['tersimpan'],
            $hasil['total'],
            $hasil['status'],
            $hasil['message_id']
        ));

        return $this->redirect('/alat/log/' . $hasil['message_id']);
    }

    /** Hasil dari alat yang belum menemukan order. */
    public function menggantung(): Response
    {
        $rows = Database::select(
            "SELECT o.*, i.nama AS nama_alat
             FROM orphan_results o
             LEFT JOIN instruments i ON i.id = o.instrument_id
             WHERE o.status = 'menunggu'
             ORDER BY o.id DESC LIMIT 200"
        );

        foreach ($rows as &$row) {
            $payload           = json_decode((string) $row['payload'], true);
            $row['ringkasan']  = is_array($payload['results'] ?? null)
                ? array_slice(array_map(
                    static fn ($r) => ($r['code'] ?? '?') . '=' . ($r['value'] ?? ''),
                    $payload['results']
                ), 0, 12)
                : [];
            $row['jml_hasil']  = is_array($payload['results'] ?? null) ? count($payload['results']) : 0;
        }
        unset($row);

        // Kandidat order untuk dipasangkan: order aktif hari ini.
        $kandidat = Database::select(
            "SELECT o.id, o.no_order, o.no_lab, p.nama AS nama_pasien, p.no_rm,
                    (SELECT GROUP_CONCAT(s.barcode) FROM specimens s WHERE s.order_id = o.id) AS barcode
             FROM orders o JOIN patients p ON p.id = o.patient_id
             WHERE o.status NOT IN ('cancelled','released')
               AND o.tgl_order >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY o.tgl_order DESC LIMIT 300"
        );

        return $this->view('instruments/menggantung', [
            'rows'     => $rows,
            'kandidat' => $kandidat,
        ], 'Hasil Belum Terpetakan');
    }

    /** @param array<string,string> $params */
    public function pasangMenggantung(array $params): Response
    {
        $id      = (int) $params['id'];
        $orderId = $this->request->int('order_id');

        if ($orderId <= 0) {
            Flash::error('Order tujuan belum dipilih.');

            return $this->redirect('/alat/menggantung');
        }

        $hasil = ResultProcessor::pasangMenggantung($id, $orderId, (int) Auth::id());

        if ($hasil['sukses']) {
            Flash::sukses($hasil['pesan']);
        } else {
            Flash::error($hasil['pesan']);
        }

        return $this->redirect('/alat/menggantung');
    }

    /** @param array<string,string> $params */
    public function buangMenggantung(array $params): Response
    {
        $id = (int) $params['id'];

        Database::update('orphan_results', [
            'status'      => 'dibuang',
            'resolved_by' => Auth::id(),
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        Audit::log('buang_hasil_menggantung', 'orphan_result', (string) $id, 'Dibuang oleh pengguna');
        Flash::sukses('Data hasil menggantung ditandai dibuang.');

        return $this->redirect('/alat/menggantung');
    }
}
