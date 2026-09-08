<?php
declare(strict_types=1);

/**
 * Selaraskan barcode spesimen order Khanza dengan nomor order Khanza,
 * dan kembalikan nomor laboratorium ke nomor LIS bila sempat tertimpa.
 *
 * LATAR
 * -----
 * Barcode spesimen adalah yang dikirim ke alat sebagai Sample ID. Untuk
 * order yang datang dari Khanza, barcode itu kini memakai
 * permintaan_lab.noorder, sehingga angka yang tertempel di tabung, terbaca
 * di layar alat, dan tercatat di Khanza adalah satu angka yang sama.
 *
 * Nomor laboratorium TIDAK ikut — itu tetap nomor urut LIS.
 *
 * Skrip ini mengurus data yang sudah terlanjur ada. Untuk order baru,
 * penyesuaiannya sudah terjadi sendiri di KhanzaService::terimaOrder().
 *
 * DUA PEKERJAAN
 * -------------
 * A. Barcode spesimen  -> nomor order Khanza.
 *    Hanya spesimen yang masih "pending" dan ordernya belum punya hasil.
 *    Spesimen yang sudah diambil, diterima, atau sudah dikirim ke alat
 *    tidak disentuh: barcode-nya sudah tertempel di tabung dan sudah
 *    dikenal alat, dan menggantinya di belakang layar membuat hasil yang
 *    kembali tidak lagi menemukan induknya.
 *
 * B. Nomor laboratorium.
 *    Versi migrasi saya sebelumnya (05_nolab_khanza.sql) keliru: ia
 *    menimpa orders.no_lab dengan noorder Khanza. Bila berkas itu sempat
 *    dijalankan, skrip ini mengenalinya dari no_lab yang sama persis
 *    dengan khanza_noorder, lalu menerbitkan nomor LIS yang baru lewat
 *    OrderService::nomorLab(). Nomor lama tidak dapat dikembalikan — ia
 *    memang sudah hilang — jadi yang dilakukan adalah menerbitkan nomor
 *    yang sah, bukan berpura-pura memulihkan.
 *
 * HANYA CLI. Bawaannya hanya melapor.
 *
 *   php bin/barcode-khanza.php              -- lihat saja
 *   php bin/barcode-khanza.php --terapkan   -- kerjakan
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\OrderService;

$berkasConfig = BASE_PATH . '/config/config.php';
if (!is_file($berkasConfig)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkasConfig);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

if (Config::get('db.host') === null) {
    exit("config/config.php termuat tetapi db.host tidak terbaca. Berhenti.\n");
}

$terapkan = in_array('--terapkan', array_slice($argv, 1), true);

echo "=====================================================================\n";
echo " SELARASKAN BARCODE KHANZA   " . date('Y-m-d H:i:s T') . "\n";
echo " mode: " . ($terapkan ? 'TERAPKAN' : 'LIHAT SAJA') . "\n";
echo "=====================================================================\n";

// ---------------------------------------------------------------------
// A. Barcode spesimen
// ---------------------------------------------------------------------
echo "\nA. Barcode spesimen -> nomor order Khanza\n";
echo str_repeat('-', 69) . "\n";

$calon = Database::select(
    "SELECT s.id           AS specimen_id,
            s.barcode      AS barcode_sekarang,
            s.status       AS status_spesimen,
            o.id           AS order_id,
            o.no_order,
            o.no_lab,
            o.khanza_noorder,
            o.status       AS status_order,
            (SELECT COUNT(*) FROM specimens s2 WHERE s2.order_id = o.id) AS jml_spesimen,
            (SELECT COUNT(*) FROM results  r  WHERE r.order_id  = o.id) AS jml_hasil
       FROM specimens s
       JOIN orders    o ON o.id = s.order_id
      WHERE o.sumber = 'khanza'
        AND o.khanza_noorder IS NOT NULL
        AND o.khanza_noorder <> ''
        AND s.barcode <> o.khanza_noorder
      ORDER BY o.id, s.id"
);

$dikerjakan = 0;
$dilewati   = 0;

if ($calon === []) {
    echo "   Tidak ada yang perlu diselaraskan.\n";
}

// Urutkan per order supaya spesimen kedua dan seterusnya dapat akhiran -2, -3.
$perOrder = [];
foreach ($calon as $r) {
    $perOrder[(int) $r['order_id']][] = $r;
}

foreach ($perOrder as $orderId => $baris) {
    $ke = 0;

    foreach ($baris as $r) {
        $alasan = null;

        if ((int) $r['jml_hasil'] > 0) {
            $alasan = 'order sudah punya hasil';
        } elseif ($r['status_spesimen'] !== 'pending') {
            $alasan = 'spesimen sudah ' . $r['status_spesimen'];
        } elseif (in_array((string) $r['status_order'], ['resulted', 'verified', 'released', 'cancelled'], true)) {
            $alasan = 'order sudah ' . $r['status_order'];
        }

        if ($alasan !== null) {
            printf(
                "   lewat  %-16s %-14s  %s\n",
                (string) $r['no_order'],
                (string) $r['barcode_sekarang'],
                $alasan
            );
            $dilewati++;
            continue;
        }

        // Pakai penentu yang sama dengan jalur order baru, sehingga aturan
        // panjang dan tabrakan berlaku sama persis di sini.
        $baru = OrderService::barcodeUntukSpesimen((string) $r['khanza_noorder'], $ke);
        $ke++;

        printf(
            "   %-6s %-16s %-14s -> %s\n",
            $terapkan ? 'ubah' : 'akan',
            (string) $r['no_order'],
            (string) $r['barcode_sekarang'],
            $baru
        );

        if ($terapkan) {
            Database::update('specimens', ['barcode' => $baru], 'id = ?', [(int) $r['specimen_id']]);
        }
        $dikerjakan++;
    }
}

printf("\n   ringkas: %d diselaraskan, %d dilewati\n", $dikerjakan, $dilewati);

// ---------------------------------------------------------------------
// B. Nomor laboratorium yang sempat tertimpa
// ---------------------------------------------------------------------
echo "\nB. Nomor lab yang sempat tertimpa noorder Khanza\n";
echo str_repeat('-', 69) . "\n";

$tertimpa = Database::select(
    "SELECT id, no_order, no_lab, khanza_noorder, status
       FROM orders
      WHERE sumber = 'khanza'
        AND khanza_noorder IS NOT NULL
        AND khanza_noorder <> ''
        AND no_lab = khanza_noorder
      ORDER BY id"
);

if ($tertimpa === []) {
    echo "   Tidak ada. Migrasi 05_nolab_khanza.sql tampaknya tidak pernah dijalankan.\n";
} else {
    echo "   " . count($tertimpa) . " order memakai noorder Khanza sebagai nomor lab.\n";
    echo "   Nomor lab lamanya sudah hilang; yang diterbitkan adalah nomor LIS baru.\n\n";

    foreach ($tertimpa as $o) {
        $baru = $terapkan ? OrderService::nomorLab() : '(nomor LIS baru)';

        printf(
            "   %-6s %-16s %-16s -> %s   [%s]\n",
            $terapkan ? 'ubah' : 'akan',
            (string) $o['no_order'],
            (string) $o['no_lab'],
            $baru,
            (string) $o['status']
        );

        if ($terapkan) {
            Database::update('orders', ['no_lab' => $baru], 'id = ?', [(int) $o['id']]);
        }
    }
}

// ---------------------------------------------------------------------
// C. Saklar pengaturan
// ---------------------------------------------------------------------
echo "\nC. Pengaturan\n";
echo str_repeat('-', 69) . "\n";

$setelan = [
    'khanza.barcode_dari_noorder' => [
        '1',
        'khanza',
        'Barcode spesimen order Khanza memakai nomor order Khanza (Sample ID di alat).',
    ],
    'barcode.panjang_maks' => [
        '20',
        'barcode',
        'Panjang maksimum Sample ID yang diterima alat. Nomor yang melebihi ini tidak dipotong, melainkan diganti barcode LIS.',
    ],
    'khanza.auto_terima' => [
        '1',
        'khanza',
        'Terima spesimen order Khanza otomatis, tanpa menunggu petugas menandainya. '
        . 'Melewatkan pemeriksaan kondisi spesimen (lisis, ikterik, volume kurang) — matikan bila setiap tabung harus discan saat diterima.',
    ],
];

foreach ($setelan as $kunci => [$nilai, $grup, $ket]) {
    $ada = Database::selectOne('SELECT `key`, `value` FROM settings WHERE `key` = ? LIMIT 1', [$kunci]);

    if ($ada !== null) {
        printf("   %-30s sudah ada (nilai: %s)\n", $kunci, (string) $ada['value']);
        continue;
    }

    printf("   %-30s %s\n", $kunci, $terapkan ? 'ditambahkan (' . $nilai . ')' : 'akan ditambahkan (' . $nilai . ')');

    if ($terapkan) {
        Database::insert('settings', [
            'key'        => $kunci,
            'value'      => $nilai,
            'grup'       => $grup,
            'keterangan' => $ket,
        ]);
    }
}

echo "\n" . str_repeat('=', 69) . "\n";

if ($terapkan) {
    echo " Selesai.\n";
} else {
    echo " Tidak ada yang diubah. Tambahkan --terapkan untuk mengerjakannya.\n";
}
