<?php
declare(strict_types=1);

/**
 * POST /khanza-connector/api/results.php
 *
 * Terima hasil terverifikasi dari LIS dan tuliskan ke tabel bridging
 * detail_hasil_lab. Aplikasi desktop Khanza kemudian menarik baris-baris
 * tersebut ke periksa_lab / detail_periksa_lab lewat menu bridging-nya.
 *
 * Body:
 * {
 *   "noorder": "LB0001",
 *   "no_rawat": "2026/09/02/000001",
 *   "no_lab": "2609020001",
 *   "tgl_hasil": "2026-09-02",
 *   "jam_hasil": "10:15:00",
 *   "petugas": "Analis Laboratorium",
 *   "detail": [
 *     { "kd_jenis_prw": "LK001", "id_template": 12,
 *       "nilai": "6.4", "nilai_rujukan": "13.2 - 17.3", "keterangan": "L!" }
 *   ]
 * }
 *
 * Operasi bersifat idempoten: memanggil ulang dengan noorder yang sama
 * akan menimpa hasil sebelumnya, bukan menggandakannya. Ini penting agar
 * koreksi hasil dari LIS ikut terbawa ke Khanza.
 */

require __DIR__ . '/../lib/bootstrap.php';

wajibMetode('POST');
wajibAuth();

$body    = bodyJson();
$noorder = trim((string) ($body['noorder'] ?? ''));
$detail  = $body['detail'] ?? [];

if ($noorder === '') {
    gagal('Field "noorder" wajib diisi.', 422);
}
if (!is_array($detail) || $detail === []) {
    gagal('Field "detail" harus berisi minimal satu hasil.', 422);
}

if (!tabelAda('detail_hasil_lab')) {
    gagal(
        'Tabel detail_hasil_lab tidak ditemukan. Jalankan install.sql pada database '
        . konfig('db_bridging.name', '') . '.',
        500
    );
}

// Pastikan order memang dikenal di sisi Khanza. Ini menangkap kesalahan
// pemetaan lebih awal, sebelum hasil menumpuk tanpa induk.
$adaOrder = ambilSemua('SELECT noorder FROM permintaan_lab WHERE noorder = ? LIMIT 1', [$noorder]);
if ($adaOrder === []) {
    catat('warn', "Hasil untuk noorder tidak dikenal: $noorder");
    gagal('Nomor order "' . $noorder . '" tidak ditemukan pada permintaan_lab.', 404);
}

$pdo = db();
$tersimpan = 0;
$dilewati  = [];

try {
    $pdo->beginTransaction();

    // Idempoten: bersihkan hasil lama untuk order ini lebih dulu.
    jalankan('DELETE FROM detail_hasil_lab WHERE noorder = ?', [$noorder]);

    $stmt = $pdo->prepare(
        'INSERT INTO detail_hasil_lab (noorder, kd_jenis_prw, id_template, nilai, nilai_rujukan, keterangan)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($detail as $d) {
        if (!is_array($d)) {
            continue;
        }

        $kd = trim((string) ($d['kd_jenis_prw'] ?? ''));
        if ($kd === '') {
            $dilewati[] = 'baris tanpa kd_jenis_prw';
            continue;
        }

        // Panjang kolom pada Khanza terbatas; potong agar INSERT tidak
        // gagal pada MySQL mode ketat.
        $stmt->execute([
            mb_substr($noorder, 0, 15),
            mb_substr($kd, 0, 15),
            (int) ($d['id_template'] ?? 0),
            mb_substr((string) ($d['nilai'] ?? ''), 0, 60),
            mb_substr((string) ($d['nilai_rujukan'] ?? ''), 0, 30),
            mb_substr((string) ($d['keterangan'] ?? ''), 0, 60),
        ]);

        $tersimpan++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    catat('error', "Gagal menyimpan hasil $noorder: " . $e->getMessage());
    gagal('Gagal menyimpan hasil: ' . $e->getMessage(), 500);
}

// Tandai permintaan sudah selesai bila kolomnya tersedia.
$kolom = kolomTabel('permintaan_lab');
foreach ($kolom as $k) {
    if (strcasecmp($k, 'status_ambil') === 0) {
        try {
            jalankan("UPDATE permintaan_lab SET status_ambil = '1' WHERE noorder = ?", [$noorder]);
        } catch (Throwable $e) {
            catat('warn', 'Gagal memperbarui status_ambil: ' . $e->getMessage());
        }
        break;
    }
}

catat('info', "Hasil $noorder tersimpan: $tersimpan baris");

sukses([
    'noorder'   => $noorder,
    'tersimpan' => $tersimpan,
    'dilewati'  => $dilewati,
], $tersimpan . ' hasil tersimpan ke detail_hasil_lab.');
