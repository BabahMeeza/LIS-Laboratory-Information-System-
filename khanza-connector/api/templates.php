<?php
declare(strict_types=1);

/**
 * GET /khanza-connector/api/templates.php
 *
 * Ekspor master pemeriksaan laboratorium Khanza (template_laboratorium
 * digabung dengan jns_perawatan_lab) agar dapat dipetakan ke master LIS
 * pada menu Integrasi → Pemetaan Pemeriksaan.
 *
 * Kueri sengaja memakai SELECT kolom yang dideteksi dinamis, karena nama
 * kolom nilai rujukan berbeda antar versi Khanza (nilai_rujukan_ld,
 * nilai_rujukan_LD, dan seterusnya).
 */

require __DIR__ . '/../lib/bootstrap.php';

wajibMetode('GET');
wajibAuth();

if (!(bool) konfig('db_sik.aktif', false)) {
    gagal(
        'Akses ke database utama Khanza dinonaktifkan pada config.php '
        . '(db_sik.aktif = false), sehingga master pemeriksaan tidak dapat diekspor.',
        409
    );
}

if (!tabelAda('template_laboratorium', 'db_sik')) {
    gagal(
        'Tabel template_laboratorium tidak ditemukan pada database '
        . konfig('db_sik.name', '') . '.',
        500
    );
}

$kolom = kolomTabel('template_laboratorium', 'db_sik');

$adaKolom = static function (string $nama) use ($kolom): ?string {
    foreach ($kolom as $k) {
        if (strcasecmp($k, $nama) === 0) {
            return $k;   // kembalikan ejaan asli agar kueri tepat
        }
    }

    return null;
};

$pilih = [];
foreach ([
    'kd_jenis_prw', 'id_template', 'Pemeriksaan', 'satuan',
    'nilai_rujukan_ld', 'nilai_rujukan_la', 'nilai_rujukan_pd', 'nilai_rujukan_pa',
    'urut', 'aktif',
] as $kandidat) {
    $asli = $adaKolom($kandidat);
    if ($asli !== null) {
        $pilih[] = 't.`' . $asli . '`';
    }
}

if ($pilih === []) {
    gagal('Struktur tabel template_laboratorium tidak dikenali.', 500);
}

$gabung = '';
if (tabelAda('jns_perawatan_lab', 'db_sik')) {
    $pilih[] = 'j.`nm_perawatan`';
    $gabung  = 'LEFT JOIN jns_perawatan_lab j ON j.kd_jenis_prw = t.kd_jenis_prw';
}

$batas = (int) ($_GET['limit'] ?? 5000);
$batas = max(1, min(20000, $batas));

try {
    $rows = ambilSemua(
        'SELECT ' . implode(', ', $pilih) . " FROM template_laboratorium t $gabung "
        . 'ORDER BY t.kd_jenis_prw, t.id_template LIMIT ' . $batas,
        [],
        'db_sik'
    );
} catch (Throwable $e) {
    catat('error', 'Ekspor template gagal: ' . $e->getMessage());
    gagal('Gagal membaca template_laboratorium: ' . $e->getMessage(), 500);
}

catat('info', count($rows) . ' template diekspor ke LIS');

sukses($rows, count($rows) . ' parameter pemeriksaan diekspor.');
