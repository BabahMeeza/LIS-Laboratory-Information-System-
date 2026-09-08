<?php
declare(strict_types=1);

/**
 * Permintaan laboratorium dari SIMRS Khanza.
 *
 * GET  /api/orders.php?limit=50
 *      Kembalikan permintaan yang belum diambil LIS (status_ambil = '0'),
 *      lengkap dengan detail pemeriksaannya.
 *
 * POST /api/orders.php?aksi=ack
 *      Body: { "noorder": ["LB0001", "LB0002"] }
 *      Tandai permintaan sebagai sudah diambil agar tidak terkirim ulang.
 *
 * Struktur tabel mengikuti database bridging resmi Khanza
 * (sik_bridging_lab): permintaan_lab dan detail_permintaan_lab.
 */

require __DIR__ . '/../lib/bootstrap.php';

wajibAuth();

$aksi = strtolower((string) ($_GET['aksi'] ?? ''));

// ---------------------------------------------------------------------
// Konfirmasi pengambilan
// ---------------------------------------------------------------------
if ($aksi === 'ack') {
    wajibMetode('POST');

    $body    = bodyJson();
    $noorder = $body['noorder'] ?? [];

    if (is_string($noorder)) {
        $noorder = [$noorder];
    }
    if (!is_array($noorder) || $noorder === []) {
        gagal('Field "noorder" harus berupa daftar nomor order.', 422);
    }

    if (!(bool) konfig('tandai_terambil', true)) {
        sukses(['ditandai' => 0], 'Penandaan dimatikan pada konfigurasi.');
    }

    $ditandai = 0;
    foreach ($noorder as $no) {
        $no = trim((string) $no);
        if ($no === '') {
            continue;
        }

        try {
            $ditandai += jalankan(
                "UPDATE permintaan_lab SET status_ambil = '1' WHERE noorder = ?",
                [$no]
            );
        } catch (Throwable $e) {
            catat('error', 'Gagal menandai ' . $no . ': ' . $e->getMessage());
        }
    }

    catat('info', "Menandai $ditandai order sebagai sudah diambil LIS");
    sukses(['ditandai' => $ditandai], $ditandai . ' order ditandai sudah diambil.');
}

// ---------------------------------------------------------------------
// Pengambilan order baru
// ---------------------------------------------------------------------
wajibMetode('GET');

$batas = (int) ($_GET['limit'] ?? konfig('batas_order', 50));
$batas = max(1, min(200, $batas));

// Beberapa instalasi menamai kolom sedikit berbeda; bangun daftar kolom
// yang benar-benar ada agar konektor tidak pecah antar versi Khanza.
$kolom = kolomTabel('permintaan_lab');
if ($kolom === []) {
    gagal(
        'Tabel permintaan_lab tidak ditemukan pada database ' . konfig('db_bridging.name', '') . '. '
        . 'Jalankan install.sql atau periksa konfigurasi database.',
        500
    );
}

$adaKolom = static function (string $nama) use ($kolom): bool {
    foreach ($kolom as $k) {
        if (strcasecmp($k, $nama) === 0) {
            return true;
        }
    }

    return false;
};

$pilih = [];
foreach ([
    'noorder', 'no_rawat', 'no_rkm_medis', 'nm_pasien', 'email', 'jk',
    'tmp_lahir', 'tgl_lahir', 'alamat', 'tgl_permintaan', 'jam_permintaan',
    'kode_dokter_perujuk', 'dokter_perujuk', 'status', 'kode_ruang', 'nama_ruang',
    'kode_carabayar', 'nama_carabayar', 'informasi_tambahan', 'diagnosa_klinis',
] as $k) {
    if ($adaKolom($k)) {
        $pilih[] = '`' . $k . '`';
    }
}

if ($pilih === []) {
    gagal('Struktur tabel permintaan_lab tidak dikenali.', 500);
}

$where = $adaKolom('status_ambil') ? "WHERE status_ambil = '0'" : '';
$urut  = $adaKolom('tgl_permintaan')
    ? 'ORDER BY tgl_permintaan ASC, jam_permintaan ASC'
    : 'ORDER BY noorder ASC';

try {
    $orders = ambilSemua(
        'SELECT ' . implode(', ', $pilih) . " FROM permintaan_lab $where $urut LIMIT $batas"
    );
} catch (Throwable $e) {
    catat('error', 'Query permintaan_lab gagal: ' . $e->getMessage());
    gagal('Gagal membaca permintaan_lab: ' . $e->getMessage(), 500);
}

if ($orders === []) {
    sukses([], 'Tidak ada permintaan baru.');
}

// Detail pemeriksaan per order.
$kolomDetail = kolomTabel('detail_permintaan_lab');
$adaTemplate = false;
foreach ($kolomDetail as $k) {
    if (strcasecmp($k, 'id_template') === 0) {
        $adaTemplate = true;
        break;
    }
}

$sikAktif = (bool) konfig('db_sik.aktif', false);

foreach ($orders as &$order) {
    $noorder = (string) $order['noorder'];

    try {
        $detail = ambilSemua(
            'SELECT * FROM detail_permintaan_lab WHERE noorder = ?',
            [$noorder]
        );
    } catch (Throwable $e) {
        catat('error', "Gagal membaca detail $noorder: " . $e->getMessage());
        $detail = [];
    }

    // Lengkapi nama pemeriksaan dari database utama bila tersedia —
    // memudahkan petugas mengenali kode yang belum dipetakan di LIS.
    if ($sikAktif && $detail !== [] && $adaTemplate) {
        foreach ($detail as &$d) {
            try {
                $nama = ambilSemua(
                    'SELECT t.Pemeriksaan, t.satuan, j.nm_perawatan
                     FROM template_laboratorium t
                     LEFT JOIN jns_perawatan_lab j ON j.kd_jenis_prw = t.kd_jenis_prw
                     WHERE t.kd_jenis_prw = ? AND t.id_template = ? LIMIT 1',
                    [$d['kd_jenis_prw'] ?? '', (int) ($d['id_template'] ?? 0)],
                    'db_sik'
                );

                if ($nama !== []) {
                    $d['pemeriksaan']  = $nama[0]['Pemeriksaan'] ?? null;
                    $d['satuan']       = $nama[0]['satuan'] ?? null;
                    $d['nm_perawatan'] = $nama[0]['nm_perawatan'] ?? null;
                }
            } catch (Throwable $e) {
                // Database sik opsional — kegagalan di sini tidak fatal.
            }
        }
        unset($d);
    }

    $order['detail'] = $detail;
}
unset($order);

catat('info', count($orders) . ' order dikirim ke LIS');

sukses($orders, count($orders) . ' permintaan siap diambil.');
