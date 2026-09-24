<?php
declare(strict_types=1);

/**
 * Pasang hasil menggantung yang ordernya SUDAH ada di LIS.
 *
 * MENGAPA ADA BERKAS INI
 *
 * Tabung yang diperiksa alat sebelum ordernya tiba di LIS jatuh ke Hasil
 * Menggantung dengan alasan "sample ID tidak ditemukan". Mulai versi ini
 * hasil semacam itu dipasang otomatis saat ordernya dibuat — tetapi hasil
 * yang tertahan SEBELUM pembaruan tetap menunggu. Skrip ini membereskannya.
 *
 * Aturannya sama persis dengan pemasangan otomatis:
 *   - sample ID harus sama persis dengan barcode / no. order / no. lab /
 *     no. order Khanza;
 *   - bila alat membawa No. RM dan berbeda dengan pasien order, DILEWATI.
 *
 * HANYA CLI. Tanpa --terapkan, hanya menampilkan apa yang akan dilakukan.
 *
 *   php bin/pasang-menggantung.php               (lihat dulu)
 *   php bin/pasang-menggantung.php --terapkan    (pasang)
 *   php bin/pasang-menggantung.php --hari=14     (jangkau 14 hari ke belakang, bawaan 3)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\ResultProcessor;

$berkas = BASE_PATH . '/config/config.php';
if (!is_file($berkas)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkas);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

$terapkan = in_array('--terapkan', $argv, true);
$hari     = 3;
foreach ($argv as $a) {
    if (preg_match('/^--hari=(\d+)$/', $a, $m) === 1) {
        $hari = max(1, (int) $m[1]);
    }
}

$garis = str_repeat('=', 72);
echo "$garis\n HASIL MENGGANTUNG YANG ORDERNYA SUDAH ADA — $hari hari terakhir\n";
echo ' ' . ($terapkan ? 'MODE: MEMASANG' : 'MODE: LIHAT SAJA (tambahkan --terapkan untuk memasang)') . "\n$garis\n";

$orphans = Database::select(
    "SELECT r.id, r.sample_id, r.created_at, r.payload, i.kode AS alat
     FROM orphan_results r LEFT JOIN instruments i ON i.id = r.instrument_id
     WHERE r.status = 'menunggu' AND r.alasan = 'sample_tidak_ditemukan'
       AND r.created_at >= DATE_SUB(NOW(), INTERVAL $hari DAY)
     ORDER BY r.id ASC"
);

$perOrder     = [];
$tanpaOrder   = 0;

foreach ($orphans as $o) {
    $sid = trim((string) $o['sample_id']);
    $row = Database::selectOne(
        "SELECT o.id, o.no_order, o.khanza_noorder, p.nama, p.no_rm
         FROM orders o JOIN patients p ON p.id = o.patient_id
         WHERE o.status <> 'cancelled' AND (
               o.no_order = ? OR o.no_lab = ? OR o.khanza_noorder = ?
            OR o.id IN (SELECT s.order_id FROM specimens s WHERE s.barcode = ?))
         ORDER BY o.id DESC LIMIT 1",
        [$sid, $sid, $sid, $sid]
    );

    $payload = json_decode((string) $o['payload'], true) ?: [];
    $rmAlat  = trim((string) ($payload['patient']['id'] ?? ''));
    $nmAlat  = trim((string) ($payload['patient']['name'] ?? ''));

    if ($row === null) {
        $tanpaOrder++;
        continue;
    }

    printf("  %-18s %-8s %s  alat: %-20s -> %s (%s)\n",
        $sid, (string) $o['alat'], substr((string) $o['created_at'], 5, 11),
        $nmAlat . ($rmAlat !== '' ? " RM $rmAlat" : ''),
        $row['no_order'], $row['nama'] . ' RM ' . $row['no_rm']);

    $perOrder[(int) $row['id']] = true;
}

echo "\n  Order masih belum ada di LIS : $tanpaOrder hasil (dibiarkan menunggu)\n";
echo '  Siap dipasang                : ' . (count($orphans) - $tanpaOrder) . " hasil, " . count($perOrder) . " order\n";

if (!$terapkan) {
    echo "\n$garis\n Periksa nama di kiri dan kanan panah sudah sama, lalu jalankan ulang\n dengan --terapkan.\n$garis\n";
    exit(0);
}

$total = 0;
foreach (array_keys($perOrder) as $orderId) {
    $r = ResultProcessor::pasangOtomatisUntukOrder($orderId, null, $hari);
    $total += $r['terpasang'];
    foreach ($r['dilewati'] as $d) {
        echo "  DILEWATI  $d\n";
    }
}

echo "\n$garis\n Terpasang: $total. Yang dilewati perlu dipasang manual dari menu\n Hasil Menggantung setelah diperiksa.\n$garis\n";