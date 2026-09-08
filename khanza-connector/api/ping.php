<?php
declare(strict_types=1);

/**
 * GET /khanza-connector/api/ping.php
 *
 * Pemeriksaan kesehatan sekaligus diagnosa pemasangan: memastikan
 * database bridging terjangkau dan tabel yang dibutuhkan sudah ada.
 */

require __DIR__ . '/../lib/bootstrap.php';

wajibMetode('GET');
wajibAuth();

$tabelWajib = ['permintaan_lab', 'detail_permintaan_lab', 'detail_hasil_lab'];
$statusTabel = [];
$semuaAda = true;

foreach ($tabelWajib as $t) {
    $ada = tabelAda($t);
    $statusTabel[$t] = $ada;
    if (!$ada) {
        $semuaAda = false;
    }
}

$menunggu = 0;
if ($statusTabel['permintaan_lab']) {
    try {
        $baris = ambilSemua("SELECT COUNT(*) AS n FROM permintaan_lab WHERE status_ambil = '0'");
        $menunggu = (int) ($baris[0]['n'] ?? 0);
    } catch (Throwable $e) {
        $menunggu = -1;
    }
}

$sikAktif = (bool) konfig('db_sik.aktif', false);
$sikOk = false;
if ($sikAktif) {
    try {
        $sikOk = tabelAda('template_laboratorium', 'db_sik');
    } catch (Throwable $e) {
        $sikOk = false;
    }
}

sukses([
    'versi'              => KONEKTOR_VERSI,
    'database'           => (string) konfig('db_bridging.name', ''),
    'tabel'              => $statusTabel,
    'siap'               => $semuaAda,
    'order_menunggu'     => $menunggu,
    'db_sik_aktif'       => $sikAktif,
    'db_sik_terjangkau'  => $sikOk,
    'php'                => PHP_VERSION,
    'signature_wajib'    => (bool) konfig('wajib_signature', false),
], $semuaAda
    ? 'Konektor siap.'
    : 'Konektor berjalan, tetapi sebagian tabel bridging belum ada. Jalankan install.sql.');
