<?php
declare(strict_types=1);

/**
 * Pendorong order Khanza → LIS (mode push).
 *
 * Jalankan berkala dari Task Scheduler (Windows) atau cron (Linux/macOS):
 *
 *   Windows :  C:\xampp\php\php.exe C:\xampp\htdocs\khanza-connector\push_orders.php
 *   Linux   :  * * * * * /usr/bin/php /var/www/khanza-connector/push_orders.php
 *   macOS   :  * * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/khanza-connector/push_orders.php
 *
 * Berkas ini HANYA boleh dijalankan dari baris perintah, tidak lewat web.
 *
 * Alur: baca permintaan_lab yang belum diambil → kirim ke LIS →
 * tandai status_ambil = '1' hanya untuk order yang benar-benar diterima.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari baris perintah.');
}

require __DIR__ . '/lib/bootstrap.php';

$mulai = microtime(true);
$batas = (int) konfig('batas_order', 50);

function keluar(string $pesan): void
{
    echo $pesan . PHP_EOL;
}

keluar('== Pendorong Order Khanza → LIS ==');
keluar('Waktu   : ' . date('Y-m-d H:i:s'));
keluar('LIS     : ' . (string) konfig('lis.base_url', ''));

// ---------------------------------------------------------------------
// Ambil permintaan yang belum dikirim
// ---------------------------------------------------------------------

try {
    $orders = ambilSemua(
        "SELECT * FROM permintaan_lab WHERE status_ambil = '0'
         ORDER BY tgl_permintaan ASC, jam_permintaan ASC
         LIMIT " . max(1, min(200, $batas))
    );
} catch (Throwable $e) {
    keluar('GAGAL membaca permintaan_lab: ' . $e->getMessage());
    catat('error', 'push_orders gagal membaca permintaan_lab: ' . $e->getMessage());
    exit(1);
}

if ($orders === []) {
    keluar('Tidak ada permintaan baru. Selesai.');
    exit(0);
}

keluar('Ditemukan: ' . count($orders) . ' permintaan');

$sikAktif = (bool) konfig('db_sik.aktif', false);

foreach ($orders as &$order) {
    $noorder = (string) $order['noorder'];

    try {
        $detail = ambilSemua('SELECT * FROM detail_permintaan_lab WHERE noorder = ?', [$noorder]);
    } catch (Throwable $e) {
        catat('error', "push_orders: detail $noorder gagal — " . $e->getMessage());
        $detail = [];
    }

    if ($sikAktif && $detail !== []) {
        foreach ($detail as &$d) {
            try {
                $nama = ambilSemua(
                    'SELECT Pemeriksaan, satuan FROM template_laboratorium
                     WHERE kd_jenis_prw = ? AND id_template = ? LIMIT 1',
                    [$d['kd_jenis_prw'] ?? '', (int) ($d['id_template'] ?? 0)],
                    'db_sik'
                );
                if ($nama !== []) {
                    $d['pemeriksaan'] = $nama[0]['Pemeriksaan'] ?? null;
                    $d['satuan']      = $nama[0]['satuan'] ?? null;
                }
            } catch (Throwable $e) {
                // opsional
            }
        }
        unset($d);
    }

    $order['detail'] = $detail;
}
unset($order);

// ---------------------------------------------------------------------
// Kirim ke LIS
// ---------------------------------------------------------------------

$respons = kirimKeLis('/api/v1/khanza/orders', ['orders' => $orders]);

if (!$respons['ok'] && $respons['status'] !== 207) {
    $pesan = $respons['error'] ?? ('HTTP ' . $respons['status']);
    keluar('GAGAL menghubungi LIS: ' . $pesan);
    keluar('Order TIDAK ditandai terambil; akan dicoba lagi pada jalannya berikutnya.');
    catat('error', 'push_orders gagal menghubungi LIS: ' . $pesan);
    exit(1);
}

$hasil = $respons['data']['data'] ?? [];
if (!is_array($hasil)) {
    keluar('Jawaban LIS tidak dikenali. Order tidak ditandai.');
    catat('error', 'push_orders: jawaban LIS tidak dikenali — ' . substr($respons['body'], 0, 300));
    exit(1);
}

// ---------------------------------------------------------------------
// Tandai hanya yang benar-benar diterima
// ---------------------------------------------------------------------

$sukses = 0;
$gagal  = 0;
$tandai = (bool) konfig('tandai_terambil', true);

foreach ($hasil as $h) {
    if (!is_array($h)) {
        continue;
    }

    $noorder = (string) ($h['noorder'] ?? '');
    if ($noorder === '') {
        continue;
    }

    if (!empty($h['sukses'])) {
        $sukses++;
        keluar(sprintf(
            '  ✓ %-14s → %s (No. Lab %s)',
            $noorder,
            (string) ($h['lis_no_order'] ?? '-'),
            (string) ($h['no_lab'] ?? '-')
        ));

        if (!empty($h['tidak_dipetakan'])) {
            keluar('      catatan: kode belum dipetakan — ' . implode(', ', (array) $h['tidak_dipetakan']));
        }

        if ($tandai) {
            try {
                jalankan("UPDATE permintaan_lab SET status_ambil = '1' WHERE noorder = ?", [$noorder]);
            } catch (Throwable $e) {
                keluar('      PERINGATAN: gagal menandai status_ambil — ' . $e->getMessage());
                catat('error', "push_orders gagal menandai $noorder: " . $e->getMessage());
            }
        }
        continue;
    }

    $gagal++;
    keluar(sprintf('  ✗ %-14s ditolak: %s', $noorder, (string) ($h['pesan'] ?? 'tanpa keterangan')));
    catat('warn', "push_orders: order $noorder ditolak LIS — " . (string) ($h['pesan'] ?? ''));
}

$durasi = round((microtime(true) - $mulai) * 1000);

keluar(sprintf('Selesai: %d terkirim, %d ditolak, %d ms', $sukses, $gagal, $durasi));
catat('info', "push_orders: $sukses terkirim, $gagal ditolak");

// Order yang ditolak sengaja TIDAK ditandai, agar dapat dikirim ulang
// setelah pemetaan pemeriksaan di LIS dilengkapi.
exit($gagal > 0 ? 2 : 0);
