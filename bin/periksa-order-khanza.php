<?php
declare(strict_types=1);

/**
 * Terangkan mengapa sebuah order Khanza tidak masuk seluruhnya ke LIS.
 *
 * CARA KERJA
 * ----------
 * Setiap order yang diterima disimpan utuh di khanza_sync_log.payload.
 * Skrip ini membaca payload itu kembali, menjalankan pemetaan yang sama
 * persis dengan KhanzaService::cariTest() untuk setiap baris rincian, lalu
 * membandingkannya dengan order_items yang benar-benar terbentuk.
 *
 * Pemetaan dipanggil lewat Reflection ke metode aslinya, bukan disalin
 * ulang di sini. Salinan kedua akan menyimpang dari yang asli, dan alat
 * pemeriksa yang menyimpang lebih buruk daripada tidak punya alat.
 *
 * YANG DICARI
 *   - baris rincian yang tidak menemukan pasangan di master LIS
 *   - baris yang terpetakan tetapi ke pemeriksaan nonaktif
 *   - hasil alat yang menganggur karena pemeriksaannya tidak ada di order
 *
 * HANYA CLI. Tidak mengubah apa pun.
 *
 *   php bin/periksa-order-khanza.php                  -- 5 order terakhir
 *   php bin/periksa-order-khanza.php PK202609040001   -- satu noorder
 *   php bin/periksa-order-khanza.php --jumlah=20
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
use App\Services\KhanzaService;

$berkasConfig = BASE_PATH . '/config/config.php';
if (!is_file($berkasConfig)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkasConfig);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

if (Config::get('db.host') === null) {
    exit("config/config.php termuat tetapi db.host tidak terbaca. Berhenti.\n");
}

$noorderDicari = '';
$jumlah        = 5;

foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--jumlah=')) {
        $jumlah = max(1, (int) substr($a, 9));
        continue;
    }
    if (!str_starts_with($a, '--')) {
        $noorderDicari = $a;
    }
}

// Pemetaan yang asli, dipanggil apa adanya.
$cariTest = new ReflectionMethod(KhanzaService::class, 'cariTest');
$cariTest->setAccessible(true);

echo "=====================================================================\n";
echo " PERIKSA ORDER KHANZA   " . date('Y-m-d H:i:s T') . "\n";
echo "=====================================================================\n";

$sql = "SELECT o.id, o.no_order, o.no_lab, o.khanza_noorder, o.status, o.tgl_order
          FROM orders o
         WHERE o.sumber = 'khanza'";
$par = [];

if ($noorderDicari !== '') {
    $sql .= ' AND o.khanza_noorder = ?';
    $par[] = $noorderDicari;
}
$sql .= ' ORDER BY o.id DESC LIMIT ' . $jumlah;

$orders = Database::select($sql, $par);

if ($orders === []) {
    exit("\nTidak ada order Khanza yang cocok.\n");
}

foreach ($orders as $o) {
    $orderId = (int) $o['id'];

    echo "\n" . str_repeat('-', 69) . "\n";
    printf(
        "Order %s   no_lab %s   Khanza %s   [%s]  %s\n",
        (string) $o['no_order'],
        (string) $o['no_lab'],
        (string) $o['khanza_noorder'],
        (string) $o['status'],
        (string) $o['tgl_order']
    );
    echo str_repeat('-', 69) . "\n";

    // --- payload asli --------------------------------------------------
    $log = Database::selectOne(
        "SELECT payload FROM khanza_sync_log
          WHERE arah = 'masuk' AND jenis = 'order' AND ref_type = 'order' AND ref_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $orderId]
    );

    if ($log === null || ($log['payload'] ?? null) === null) {
        echo "  Payload asli tidak tersimpan — tidak dapat direkonstruksi.\n";
        continue;
    }

    $payload = json_decode((string) $log['payload'], true);
    $detail  = is_array($payload['detail'] ?? null) ? $payload['detail'] : [];

    if ($detail === []) {
        echo "  Payload tersimpan tetapi tanpa rincian pemeriksaan.\n";
        continue;
    }

    // --- item yang benar-benar terbentuk -------------------------------
    $items = Database::select(
        'SELECT oi.test_id, t.kode, t.nama
           FROM order_items oi
           JOIN tests t ON t.id = oi.test_id
          WHERE oi.order_id = ?',
        [$orderId]
    );
    $testDiOrder = array_map(static fn (array $r): int => (int) $r['test_id'], $items);

    printf("  diminta Khanza : %d baris\n", count($detail));
    printf("  masuk ke LIS   : %d pemeriksaan\n\n", count($items));

    $hilang = 0;

    foreach ($detail as $d) {
        if (!is_array($d)) {
            continue;
        }

        $kd    = trim((string) ($d['kd_jenis_prw'] ?? ''));
        $idTpl = isset($d['id_template']) ? (int) $d['id_template'] : null;
        $nama  = trim((string) ($d['pemeriksaan'] ?? $d['nm_perawatan'] ?? ''));

        $testId = $cariTest->invoke(null, $kd, $idTpl);

        if ($testId === null) {
            $hilang++;

            // Bedakan "belum dipetakan" dari "terpetakan ke pemeriksaan mati".
            $sebab = 'tidak ada pasangan di master LIS';

            $mati = Database::selectOne(
                'SELECT id, kode, nama, aktif FROM tests
                  WHERE khanza_kd_jenis_prw = ? AND aktif = 0 LIMIT 1',
                [$kd]
            );
            if ($mati !== null) {
                $sebab = 'terpetakan ke ' . $mati['kode'] . ' (' . $mati['nama'] . ') tetapi pemeriksaan itu NONAKTIF';
            } else {
                $tpl = Database::selectOne(
                    'SELECT test_id FROM khanza_templates WHERE kd_jenis_prw = ? AND id_template = ? LIMIT 1',
                    [$kd, $idTpl]
                );
                if ($tpl !== null && ($tpl['test_id'] ?? null) === null) {
                    $sebab = 'tercatat di khanza_templates tetapi test_id masih kosong';
                } elseif ($tpl === null && $idTpl === null) {
                    $sebab = 'tanpa id_template — tidak dapat dicatat untuk dipetakan';
                }
            }

            printf("  HILANG  %-12s %-6s %-34s %s\n", $kd, $idTpl === null ? '-' : (string) $idTpl, mb_substr($nama, 0, 34), $sebab);
            continue;
        }

        if (!in_array((int) $testId, $testDiOrder, true)) {
            $hilang++;
            printf("  HILANG  %-12s %-6s %-34s terpetakan (test #%d) tetapi tidak ada di order\n",
                $kd, $idTpl === null ? '-' : (string) $idTpl, mb_substr($nama, 0, 34), (int) $testId);
            continue;
        }

        printf("  masuk   %-12s %-6s %-34s test #%d\n", $kd, $idTpl === null ? '-' : (string) $idTpl, mb_substr($nama, 0, 34), (int) $testId);
    }

    if ($hilang > 0) {
        printf("\n  >> %d baris tidak masuk. Petakan di menu Integrasi > Pemetaan Pemeriksaan.\n", $hilang);
    } else {
        echo "\n  Seluruh baris permintaan masuk.\n";
    }

    // --- hasil alat yang menganggur ------------------------------------
    // Satu baris orphan_results memuat seluruh nilai satu sampel dalam
    // payload JSON, masing-masing dengan _alasan sendiri.
    $menggantung = Database::select(
        "SELECT id, sample_id, payload, alasan, status, created_at
           FROM orphan_results
          WHERE sample_id IN (SELECT barcode FROM specimens WHERE order_id = ?)
            AND status = 'menunggu'
          ORDER BY id",
        [$orderId]
    );

    foreach ($menggantung as $m) {
        $isi   = json_decode((string) ($m['payload'] ?? ''), true);
        $nilai = is_array($isi['results'] ?? null) ? $isi['results'] : [];

        printf(
            "\n  Hasil alat menganggur — sampel %s, %d nilai (%s)\n",
            (string) $m['sample_id'],
            count($nilai),
            (string) $m['alasan']
        );

        foreach (array_slice($nilai, 0, 30) as $n) {
            printf(
                "     %-16s %-12s %-10s %s\n",
                (string) ($n['code'] ?? '?'),
                (string) ($n['value'] ?? ''),
                (string) ($n['unit'] ?? ''),
                (string) ($n['_alasan'] ?? '')
            );
        }

        echo "     'tidak_diminta_pada_order' = alat mengukurnya, tetapi\n";
        echo "     pemeriksaan itu tidak ada di order — sisi Khanza tidak memintanya,\n";
        echo "     atau permintaannya gugur karena belum terpetakan (lihat daftar di atas).\n";
    }
}

echo "\n" . str_repeat('=', 69) . "\n";
echo "Selesai. Tidak ada yang diubah.\n";
