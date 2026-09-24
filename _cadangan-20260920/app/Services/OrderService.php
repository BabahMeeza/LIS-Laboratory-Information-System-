<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Logika bisnis order pemeriksaan: penomoran, pembuatan order beserta
 * item dan spesimen, serta pemeliharaan status agregat.
 */
final class OrderService
{
    /** Nomor order internal: LIS-260831-0007 */
    public static function nomorOrder(): string
    {
        $prefix  = Config::setting('order.prefix', 'LIS') ?? 'LIS';
        $tanggal = date('ymd');
        $urut    = Database::nextCounter('order:' . $tanggal);

        return sprintf('%s-%s-%04d', $prefix, $tanggal, $urut);
    }

    /** Nomor laboratorium, reset sesuai pengaturan (harian/bulanan/tahunan). */
    public static function nomorLab(): string
    {
        $reset = Config::setting('nolab.reset', 'harian');
        $kunci = match ($reset) {
            'bulanan' => 'nolab:' . date('Ym'),
            'tahunan' => 'nolab:' . date('Y'),
            default   => 'nolab:' . date('Ymd'),
        };

        $urut = Database::nextCounter($kunci);

        return match ($reset) {
            'bulanan' => date('ym') . sprintf('%05d', $urut),
            'tahunan' => date('y') . sprintf('%06d', $urut),
            default   => date('ymd') . sprintf('%04d', $urut),
        };
    }

    /**
     * Barcode satu spesimen, dengan nomor dari sistem luar bila disediakan.
     *
     * Untuk order Khanza, barcode-nya adalah nomor order Khanza, sebab
     * barcode inilah yang dikirim ke alat sebagai Sample ID — sehingga angka
     * di tabung, di layar alat, dan di Khanza sama persis.
     *
     * DUA HAL YANG MEMBUATNYA TIDAK SESEDERHANA "pakai saja noorder-nya":
     *
     * 1. specimens.barcode UNIQUE, dan satu order dapat menghasilkan lebih
     *    dari satu spesimen — EDTA untuk hematologi, serum untuk kimia.
     *    Keduanya tidak bisa memakai noorder yang sama; insert kedua akan
     *    gagal dan seluruh transaksi order ikut dibatalkan. Spesimen kedua
     *    dan seterusnya karena itu diberi akhiran huruf: A, B, C.
     *
     *    Huruf, bukan "-2". Code 128 sebenarnya sanggup memuat tanda hubung,
     *    tetapi medan Sample ID pada banyak analyzer hanya menerima huruf dan
     *    angka, dan sebagian scanner disetel membuang tanda baca. Satu huruf
     *    menghindari seluruh golongan masalah itu dan malah lebih pendek.
     *
     * 2. Sample ID di alat punya batas panjang. Bila nomor yang diminta
     *    melebihinya, barcode dipotong tidak boleh: potongan itu diam-diam
     *    memutus kaitan ke Khanza dan hasil dari alat akan nyasar. Lebih
     *    baik jatuh ke barcode LIS biasa dan mencatatnya di log.
     */
    public static function barcodeUntukSpesimen(?string $dasar, int $ke = 0): string
    {
        $dasar = trim((string) $dasar);

        if ($dasar === '') {
            return self::barcodeSpesimen();
        }

        // Batas kolom 30; batas alat diatur terpisah karena lebih ketat.
        $maks = max(8, min(30, Config::settingInt('barcode.panjang_maks', 20)));

        // Spesimen ke-2 dst. diberi akhiran A, B, C … (huruf ke-25 dan
        // seterusnya jatuh ke barcode LIS lewat pemeriksaan tabrakan.)
        $calon = $ke === 0 ? $dasar : $dasar . chr(65 + min($ke - 1, 25));

        if (mb_strlen($calon) > $maks) {
            Logger::warning('Barcode dari nomor luar terlalu panjang, dipakai barcode LIS.', [
                'diminta' => $calon,
                'panjang' => mb_strlen($calon),
                'maks'    => $maks,
            ]);

            return self::barcodeSpesimen();
        }

        if (Database::scalar('SELECT COUNT(*) FROM specimens WHERE barcode = ?', [$calon]) > 0) {
            Logger::warning('Barcode dari nomor luar sudah dipakai, dipakai barcode LIS.', [
                'diminta' => $calon,
            ]);

            return self::barcodeSpesimen();
        }

        return $calon;
    }

    /** Barcode spesimen — dipakai sebagai Sample ID di alat. */
    public static function barcodeSpesimen(): string
    {
        $prefix = Config::setting('barcode.prefix', '') ?? '';
        $format = Config::setting('barcode.format', 'YMD-SEQ');
        $panjang = max(3, Config::settingInt('barcode.panjang_seq', 4));

        $urut = Database::nextCounter('barcode:' . date('Ymd'));

        $inti = match ($format) {
            'SEQ'   => str_pad((string) Database::nextCounter('barcode:global'), 8, '0', STR_PAD_LEFT),
            default => date('ymd') . str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT),
        };

        $barcode = $prefix . $inti;

        // Jaga-jaga bila terjadi tabrakan (mis. counter di-reset manual).
        $percobaan = 0;
        while (Database::scalar('SELECT COUNT(*) FROM specimens WHERE barcode = ?', [$barcode]) > 0) {
            $percobaan++;
            $barcode = $prefix . $inti . $percobaan;
            if ($percobaan > 50) {
                $barcode = $prefix . $inti . substr((string) microtime(true), -4);
                break;
            }
        }

        return $barcode;
    }

    /**
     * Buat order baru beserta item dan spesimen.
     *
     * @param array<string,mixed> $data    Data header order
     * @param array<int,int>      $testIds Daftar id pemeriksaan
     * @param array<int,int>      $panelIds Daftar id paket (item-nya ikut ditambahkan)
     * @return array{order_id:int,no_order:string,no_lab:string,barcode:array<int,string>}
     */
    public static function buat(array $data, array $testIds, array $panelIds = []): array
    {
        return Database::transaction(static function () use ($data, $testIds, $panelIds): array {

            // Kembangkan paket menjadi item pemeriksaan.
            $semuaTest = $testIds;
            foreach ($panelIds as $panelId) {
                $rows = Database::select(
                    'SELECT test_id FROM test_panel_items WHERE panel_id = ? ORDER BY urut',
                    [$panelId]
                );
                foreach ($rows as $row) {
                    $semuaTest[] = (int) $row['test_id'];
                }
            }
            $semuaTest = array_values(array_unique(array_map('intval', $semuaTest)));

            if ($semuaTest === []) {
                throw new \RuntimeException('Order harus memuat minimal satu pemeriksaan.');
            }

            $noOrder = $data['no_order'] ?? self::nomorOrder();
            $noLab   = $data['no_lab']   ?? self::nomorLab();

            $orderId = Database::insert('orders', [
                'no_order'           => $noOrder,
                'no_lab'             => $noLab,
                'patient_id'         => (int) $data['patient_id'],
                'khanza_noorder'     => $data['khanza_noorder']  ?? null,
                'khanza_no_rawat'    => $data['khanza_no_rawat'] ?? null,
                'asal'               => $data['asal']            ?? 'ralan',
                'kode_ruang'         => $data['kode_ruang']      ?? null,
                'nama_ruang'         => $data['nama_ruang']      ?? null,
                'kode_carabayar'     => $data['kode_carabayar']  ?? null,
                'nama_carabayar'     => $data['nama_carabayar']  ?? null,
                'dokter_perujuk'     => $data['dokter_perujuk']  ?? null,
                'diagnosa_klinis'    => $data['diagnosa_klinis'] ?? null,
                'informasi_tambahan' => $data['informasi_tambahan'] ?? null,
                'prioritas'          => $data['prioritas']       ?? 'rutin',
                'status'             => 'ordered',
                'tgl_order'          => $data['tgl_order']       ?? date('Y-m-d H:i:s'),
                'catatan'            => $data['catatan']         ?? null,
                'sumber'             => $data['sumber']          ?? 'manual',
                'created_by'         => $data['created_by']      ?? Auth::id(),
            ]);

            // Buat satu spesimen per jenis tabung yang diperlukan.
            $tests = Database::select(
                'SELECT id, harga, specimen_type_id, khanza_kd_jenis_prw, khanza_id_template, urut
                 FROM tests WHERE id IN (' . implode(',', array_fill(0, count($semuaTest), '?')) . ')',
                $semuaTest
            );

            $spesimenPerJenis = [];
            $barcodes         = [];
            $total            = 0.0;

            foreach ($tests as $test) {
                $jenis = $test['specimen_type_id'] === null ? 0 : (int) $test['specimen_type_id'];

                if (!isset($spesimenPerJenis[$jenis])) {
                    $barcode                 = self::barcodeUntukSpesimen(
                        isset($data['barcode_dasar']) ? (string) $data['barcode_dasar'] : null,
                        count($barcodes)
                    );
                    $spesimenPerJenis[$jenis] = Database::insert('specimens', [
                        'order_id'         => $orderId,
                        'barcode'          => $barcode,
                        'specimen_type_id' => $jenis === 0 ? null : $jenis,
                        'status'           => 'pending',
                    ]);
                    $barcodes[] = $barcode;
                }

                Database::insert('order_items', [
                    'order_id'            => $orderId,
                    'test_id'             => (int) $test['id'],
                    'specimen_id'         => $spesimenPerJenis[$jenis],
                    'harga'               => (float) $test['harga'],
                    'status'              => 'pending',
                    'urut'                => (int) $test['urut'],
                    'khanza_kd_jenis_prw' => $test['khanza_kd_jenis_prw'],
                    'khanza_id_template'  => $test['khanza_id_template'],
                ]);

                $total += (float) $test['harga'];
            }

            Database::update('orders', ['total_harga' => $total], 'id = ?', [$orderId]);

            Audit::log('buat_order', 'order', (string) $orderId, 'Order ' . $noOrder . ' dengan ' . count($tests) . ' pemeriksaan');

            return [
                'order_id' => $orderId,
                'no_order' => $noOrder,
                'no_lab'   => $noLab,
                'barcode'  => $barcodes,
            ];
        });
    }

    /**
     * Hitung ulang status order dari status item-itemnya.
     * Dipanggil setiap kali hasil masuk atau spesimen berubah status.
     */
    public static function segarkanStatus(int $orderId): string
    {
        $order = Database::selectOne('SELECT status FROM orders WHERE id = ? LIMIT 1', [$orderId]);
        if ($order === null || in_array((string) $order['status'], ['cancelled', 'released'], true)) {
            return (string) ($order['status'] ?? '');
        }

        $rekap = Database::selectOne(
            'SELECT
                COUNT(*)                                                    AS total,
                SUM(oi.status = \'pending\')                                AS pending,
                SUM(r.id IS NOT NULL)                                       AS ada_hasil,
                SUM(r.status IN (\'verified\',\'corrected\'))               AS terverifikasi
             FROM order_items oi
             LEFT JOIN results r ON r.order_item_id = oi.id
             WHERE oi.order_id = ? AND oi.status <> \'cancelled\'',
            [$orderId]
        );

        if ($rekap === null || (int) $rekap['total'] === 0) {
            return (string) $order['status'];
        }

        $total         = (int) $rekap['total'];
        $adaHasil      = (int) $rekap['ada_hasil'];
        $terverifikasi = (int) $rekap['terverifikasi'];

        $spesimenDiterima = (int) Database::scalar(
            'SELECT COUNT(*) FROM specimens WHERE order_id = ? AND status = \'received\'',
            [$orderId]
        );
        $spesimenDiambil = (int) Database::scalar(
            'SELECT COUNT(*) FROM specimens WHERE order_id = ? AND status IN (\'collected\',\'received\')',
            [$orderId]
        );

        $status = match (true) {
            $terverifikasi === $total       => 'verified',
            $adaHasil === $total            => 'resulted',
            $adaHasil > 0                   => 'in_progress',
            $spesimenDiterima > 0           => 'received',
            $spesimenDiambil > 0            => 'collected',
            default                         => 'ordered',
        };

        if ($status !== (string) $order['status']) {
            Database::update('orders', ['status' => $status], 'id = ?', [$orderId]);
        }

        return $status;
    }

    /**
     * Turn Around Time order dalam menit (order → rilis).
     */
    public static function tatMenit(int $orderId): ?int
    {
        $row = Database::selectOne(
            'SELECT TIMESTAMPDIFF(MINUTE, tgl_order, COALESCE(tgl_selesai, NOW())) AS menit
             FROM orders WHERE id = ? LIMIT 1',
            [$orderId]
        );

        return $row === null ? null : (int) $row['menit'];
    }

    /**
     * Ringkasan hasil satu order untuk ditampilkan / dicetak / dikirim.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function hasilOrder(int $orderId): array
    {
        return Database::select(
            'SELECT oi.id AS order_item_id, oi.status AS status_item,
                    oi.khanza_kd_jenis_prw, oi.khanza_id_template,
                    t.id AS test_id, t.kode AS kode_test, t.nama AS nama_test,
                    t.nama_singkat, t.satuan AS satuan_master, t.tipe_hasil, t.pilihan,
                    t.desimal, t.metode, t.urut,
                    tc.nama AS kategori, tc.urut AS kategori_urut,
                    r.id AS result_id, r.nilai, r.nilai_num, r.satuan, r.flag, r.flag_alat,
                    r.ref_teks, r.status AS status_hasil, r.catatan, r.is_kritis,
                    r.delta_check, r.delta_persen, r.is_manual,
                    r.verified_at, r.entered_at, r.kritis_dilapor_at,
                    i.nama AS nama_alat,
                    u.nama AS diperiksa_oleh, uv.nama AS diverifikasi_oleh
             FROM order_items oi
             JOIN tests t ON t.id = oi.test_id
             LEFT JOIN test_categories tc ON tc.id = t.category_id
             LEFT JOIN results r ON r.order_item_id = oi.id
             LEFT JOIN instruments i ON i.id = r.instrument_id
             LEFT JOIN users u ON u.id = r.entered_by
             LEFT JOIN users uv ON uv.id = r.verified_by
             WHERE oi.order_id = ? AND oi.status <> \'cancelled\'
             ORDER BY COALESCE(tc.urut, 999), t.urut, t.nama',
            [$orderId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function detail(int $orderId): ?array
    {
        return Database::selectOne(
            'SELECT o.*, p.no_rm, p.nama AS nama_pasien, p.jk, p.tgl_lahir, p.alamat,
                    p.nik, p.telepon, p.khanza_no_rkm_medis,
                    u.nama AS dibuat_oleh
             FROM orders o
             JOIN patients p ON p.id = o.patient_id
             LEFT JOIN users u ON u.id = o.created_by
             WHERE o.id = ? LIMIT 1',
            [$orderId]
        );
    }

    public static function batal(int $orderId, string $alasan): bool
    {
        return Database::transaction(static function () use ($orderId, $alasan): bool {
            $order = Database::selectOne('SELECT * FROM orders WHERE id = ? LIMIT 1', [$orderId]);
            if ($order === null) {
                return false;
            }
            if (in_array((string) $order['status'], ['released', 'cancelled'], true)) {
                return false;
            }

            Database::update('orders', [
                'status'        => 'cancelled',
                'alasan_batal'  => mb_substr($alasan, 0, 255),
            ], 'id = ?', [$orderId]);

            Database::update('order_items', ['status' => 'cancelled'], 'order_id = ?', [$orderId]);

            Audit::log('batal_order', 'order', (string) $orderId, 'Alasan: ' . $alasan);

            return true;
        });
    }
}
