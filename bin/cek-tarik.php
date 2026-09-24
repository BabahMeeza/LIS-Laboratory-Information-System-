<?php
declare(strict_types=1);

/**
 * Periksa mengapa "Ambil Hasil dari LIS" tidak mengembalikan apa pun
 * untuk satu nomor order Khanza.
 *
 * MENGAPA ADA BERKAS INI
 *
 * Di layar LIS hasilnya terlihat ada, tetapi penarikan menjawab kosong.
 * Dua pemandangan itu memakai syarat yang berbeda, dan perbedaannya tidak
 * terlihat dari layar mana pun:
 *
 *   Layar hasil  menampilkan tiap baris hasil apa adanya.
 *   Penarikan    menuntut EMPAT syarat sekaligus terpenuhi.
 *
 * Berkas ini menjalankan keempat syarat itu satu per satu dengan urutan
 * yang sama seperti KhanzaApi::hasilSiapKirim() dan KhanzaService::
 * payloadHasil(), lalu berhenti pada syarat pertama yang gagal dan
 * menyebutkan persis apa yang harus diperbaiki.
 *
 * HANYA CLI. Tidak mengubah apa pun.
 *
 *   php bin/cek-tarik.php PK202609190066
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

$berkas = BASE_PATH . '/config/config.php';
if (!is_file($berkas)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkas);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

$noorder = trim((string) ($argv[1] ?? ''));
if ($noorder === '') {
    exit("Sebutkan nomor ordernya:  php bin/cek-tarik.php PK202609190066\n");
}

$garis = str_repeat('=', 70);
echo "$garis\n PERIKSA PENARIKAN HASIL — $noorder\n$garis\n";

// ---------------------------------------------------------------------
// Syarat 1 — ordernya ada, dan dikenal berasal dari Khanza
// ---------------------------------------------------------------------

$o = Database::selectOne(
    'SELECT id, no_lab, status, khanza_noorder, khanza_no_rawat
     FROM orders WHERE khanza_noorder = ? LIMIT 1',
    [$noorder]
);

if ($o === null) {
    echo "\n[1] GAGAL — nomor order ini tidak ada di LIS.\n\n";

    // Sering kali ordernya ada tetapi nomornya beda tipis. Tunjukkan
    // yang mirip supaya salah ketik langsung ketahuan.
    $mirip = Database::select(
        'SELECT khanza_noorder, status FROM orders
         WHERE khanza_noorder LIKE ? ORDER BY id DESC LIMIT 5',
        ['%' . substr($noorder, -6) . '%']
    );
    if ($mirip !== []) {
        echo "    Nomor serupa yang ADA di LIS:\n";
        foreach ($mirip as $m) {
            echo '      ' . $m['khanza_noorder'] . '  (' . $m['status'] . ")\n";
        }
    } else {
        echo "    Tidak ada nomor yang mirip. Ordernya memang belum pernah masuk\n";
        echo "    dari Khanza — periksa sisi kirim order, bukan sisi ambil hasil.\n";
    }
    exit(1);
}

$idOrder = (int) $o['id'];
echo "\n[1] OK — order ditemukan.  LIS id $idOrder, no_lab {$o['no_lab']}, status \"{$o['status']}\"\n";

// ---------------------------------------------------------------------
// Syarat 2 — STATUS ORDER, bukan status tiap hasil
// ---------------------------------------------------------------------

$lolosStatus = in_array((string) $o['status'], ['verified', 'released'], true);

if (!$lolosStatus) {
    echo "\n[2] GAGAL — penarikan hanya melayani status order \"verified\" atau \"released\".\n";
    echo "    Inilah sebab yang paling sering disalahpahami: di layar, tiap baris hasil\n";
    echo "    boleh sudah hijau \"Terverifikasi\", tetapi yang menentukan penarikan adalah\n";
    echo "    status ORDER — dan itu baru berubah setelah SELURUH item selesai.\n";
} else {
    echo "\n[2] OK — status order memenuhi syarat.\n";
}

// ---------------------------------------------------------------------
// Rincian item: mana yang menahan
// ---------------------------------------------------------------------

$items = Database::select(
    "SELECT oi.id, oi.urut, t.kode, t.nama,
            oi.khanza_kd_jenis_prw, oi.khanza_id_template,
            r.id AS result_id, r.status AS status_hasil, r.nilai
     FROM order_items oi
     JOIN tests t ON t.id = oi.test_id
     LEFT JOIN results r ON r.order_item_id = oi.id
     WHERE oi.order_id = ?
     ORDER BY oi.urut, oi.id",
    [$idOrder]
);

$siap    = 0;
$menahan = [];

echo "\n" . str_repeat('-', 70) . "\n";
printf("  %-14s %-26s %-12s %s\n", 'KODE', 'PEMERIKSAAN', 'HASIL', 'KETERANGAN');
echo str_repeat('-', 70) . "\n";

foreach ($items as $it) {
    $ket = '';
    $ok  = true;

    if ($it['result_id'] === null) {
        $ket = 'belum ada hasil';
        $ok  = false;
    } elseif (!in_array((string) $it['status_hasil'], ['verified', 'corrected'], true)) {
        $ket = 'hasil "' . $it['status_hasil'] . '" — belum diverifikasi';
        $ok  = false;
    } elseif ($it['khanza_kd_jenis_prw'] === null) {
        // Item ini tidak berasal dari Khanza; dilewati payloadHasil()
        // tanpa suara. Bukan kesalahan, tetapi harus terlihat.
        $ket = 'bukan dari Khanza — tidak ikut dikirim';
        $ok  = false;
    }

    if ($ok) {
        $siap++;
        $ket = 'siap kirim (template ' . $it['khanza_id_template'] . ')';
    } else {
        $menahan[] = (string) $it['nama'];
    }

    printf(
        "  %-14s %-26s %-12s %s\n",
        mb_substr((string) $it['kode'], 0, 14),
        mb_substr((string) $it['nama'], 0, 26),
        $it['nilai'] === null ? '—' : mb_substr((string) $it['nilai'], 0, 12),
        $ket
    );
}

echo str_repeat('-', 70) . "\n";

// ---------------------------------------------------------------------
// Syarat 3 — minimal satu baris layak kirim
// ---------------------------------------------------------------------

echo "\n[3] " . ($siap > 0 ? "OK — $siap baris layak dikirim." : 'GAGAL — tidak ada satu pun baris yang layak dikirim.') . "\n";

// ---------------------------------------------------------------------
// Syarat 4 — pada penarikan MASSAL, order yang sudah pernah terkirim
//            dilewati. Pada penarikan per-nomor, syarat ini dilepas.
// ---------------------------------------------------------------------

$pernah = Database::selectOne(
    "SELECT COUNT(*) AS n FROM khanza_sync_log
     WHERE ref_type='order' AND ref_id = ? AND jenis='hasil' AND status='sukses'",
    [(string) $idOrder]
);

$sudahTerkirim = (int) ($pernah['n'] ?? 0) > 0;
echo '[4] ' . ($sudahTerkirim
    ? 'Order ini SUDAH pernah terkirim sukses — pada penarikan massal ia dilewati,'
      . "\n    tetapi pada penarikan per-nomor tetap dilayani."
    : 'Belum pernah terkirim sukses — tidak ada yang melewatinya.') . "\n";

// ---------------------------------------------------------------------
// Kesimpulan
// ---------------------------------------------------------------------

echo "\n$garis\n";

if ($lolosStatus && $siap > 0) {
    echo " HASIL: penarikan SEHARUSNYA mengembalikan $siap baris untuk order ini.\n\n";
    echo " Bila di Khanza tetap kosong, masalahnya bukan di data melainkan di\n";
    echo " pemanggilan. Yang paling sering: nomor order tidak ikut terkirim,\n";
    echo " sehingga LIS menganggapnya permintaan massal dan menjawab order lain.\n";
    echo " Uji langsung dari baris perintah:\n\n";
    echo "   curl -s -H 'X-API-Key: <kunci khanza>' \\\n";
    echo "     '<alamat LIS>/api/v1/khanza/hasil?noorder=$noorder'\n\n";
    echo " Bila curl mengembalikan datanya tetapi tombol di Khanza tidak,\n";
    echo " yang salah ada di sisi Java — bukan di LIS.\n";
} elseif (!$lolosStatus) {
    echo " HASIL: penarikan menjawab KOSONG karena status order belum verified.\n\n";
    if ($menahan !== []) {
        echo " Yang menahan (" . count($menahan) . " item):\n";
        foreach (array_slice($menahan, 0, 15) as $m) {
            echo "   - $m\n";
        }
        if (count($menahan) > 15) {
            echo '   … dan ' . (count($menahan) - 15) . " lainnya\n";
        }
        echo "\n Selesaikan dan verifikasi item-item itu — termasuk yang harus diisi\n";
        echo " manual seperti hitung jenis (Batang, Segmen) yang tidak dikirim alat.\n";
    }
} else {
    echo " HASIL: order sudah verified tetapi tidak ada baris yang layak kirim.\n";
    echo " Periksa kolom KETERANGAN di atas — biasanya karena itemnya tidak\n";
    echo " berasal dari Khanza, sehingga sengaja tidak ikut dikirim.\n";
}

echo "$garis\n";
