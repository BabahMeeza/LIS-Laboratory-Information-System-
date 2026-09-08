<?php
declare(strict_types=1);

/**
 * GET|POST /khanza-connector/api/sync.php?dari=YYYY-MM-DD&sampai=YYYY-MM-DD
 *
 * Menyalin permintaan laboratorium dari database UTAMA Khanza ke database
 * bridging, berdasarkan tanggal permintaan.
 *
 * MENGAPA ENDPOINT INI ADA
 *
 * Konektor membaca sik_bridging_lab. Database itu tidak terisi sendiri —
 * ia diisi fitur bridging lab Khanza. Bila fitur tersebut tidak tersedia
 * atau tidak diaktifkan, tabelnya kosong selamanya, dan "Tarik Order" di
 * LIS menjawab HTTP 200 dengan "tidak ada permintaan baru": benar secara
 * teknis, tetapi tidak pernah membawa data.
 *
 * Endpoint ini mengambil alih pekerjaan penyalinan itu.
 *
 * BENTUK DATANYA BERBEDA — dan itulah inti pekerjaannya
 *
 * sik.permintaan_lab hanya memuat inti order:
 *
 *   noorder, no_rawat, tgl_permintaan, jam_permintaan, tgl_sampel,
 *   jam_sampel, tgl_hasil, jam_hasil, dokter_perujuk, status,
 *   informasi_tambahan, diagnosa_klinis
 *
 * Tidak ada nama pasien, nomor rekam medis, jenis kelamin, tanggal lahir,
 * ruangan, maupun cara bayar. Semua itu tersebar di reg_periksa, pasien,
 * poliklinik, penjab, dan bangsal, dan harus dirangkai lewat no_rawat.
 * sik_bridging_lab.permintaan_lab adalah bentuk PIPIH dari rangkaian itu
 * — itulah sebabnya ia ada.
 *
 * SIFAT-SIFAT YANG DIJAGA
 *
 *   1. BACA-SAJA terhadap database rumah sakit. Tidak satu pun perintah
 *      tulis menyentuh `sik`. Yang ditulis hanya database bridging.
 *
 *   2. status_ambil TIDAK PERNAH DIRESET. Order yang sudah ditarik LIS
 *      bertanda '1'; menyinkronkan ulang tidak boleh membuatnya terkirim
 *      dua kali. Nilai lama dipertahankan pada baris yang sudah ada.
 *
 *   3. NAMA KOLOM DIDETEKSI, BUKAN DIASUMSIKAN. Skema Khanza berbeda
 *      antar versi. Kolom yang tidak ada dilewati; kolom penghubung yang
 *      hilang menghentikan proses dengan pesan yang menyebut namanya —
 *      bukan menghasilkan data yang salah diam-diam.
 *
 *   4. Rincian per order dihapus lalu diisi ulang, karena
 *      detail_permintaan_lab tidak punya kunci unik sehingga
 *      ON DUPLICATE KEY UPDATE tidak dapat dipakai.
 */

require __DIR__ . '/../lib/bootstrap.php';

wajibAuth();

if (!(bool) konfig('db_sik.aktif', false)) {
    gagal(
        'db_sik.aktif bernilai false pada config.php, sehingga database utama '
        . 'Khanza tidak dapat dibaca. Aktifkan lebih dulu.',
        409
    );
}

// ---------------------------------------------------------------------
// Rentang tanggal
// ---------------------------------------------------------------------
//
// Bawaan 7 hari terakhir: cukup menampung order yang tertinggal karena
// LIS sempat mati, tanpa memindai seluruh riwayat setiap kali dipanggil.

$hariMundur = (int) konfig('sync_hari_mundur', 7);

$dari   = trim((string) ($_GET['dari']   ?? ''));
$sampai = trim((string) ($_GET['sampai'] ?? ''));

$sahTanggal = static function (string $t): bool {
    return $t !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) === 1 && strtotime($t) !== false;
};

if ($dari === '')   { $dari   = date('Y-m-d', strtotime('-' . max(0, $hariMundur) . ' days')); }
if ($sampai === '') { $sampai = date('Y-m-d'); }

if (!$sahTanggal($dari) || !$sahTanggal($sampai)) {
    gagal('Parameter "dari" dan "sampai" harus berformat YYYY-MM-DD.', 422);
}
if (strtotime($dari) > strtotime($sampai)) {
    gagal('Tanggal "dari" tidak boleh melewati "sampai".', 422);
}

// ---------------------------------------------------------------------
// Deteksi skema
// ---------------------------------------------------------------------

/** Peta kolom tabel (huruf kecil → ejaan asli), atau null bila tabel tak ada. */
$petaKolom = static function (string $tabel): ?array {
    if (!tabelAda($tabel, 'db_sik')) {
        return null;
    }
    $peta = [];
    foreach (kolomTabel($tabel, 'db_sik') as $k) {
        $peta[strtolower($k)] = $k;
    }

    return $peta;
};

$kolPermintaan = $petaKolom('permintaan_lab');
if ($kolPermintaan === null) {
    gagal(
        'Tabel permintaan_lab tidak ditemukan pada database ' . konfig('db_sik.name', '') . '.',
        500
    );
}

// Nama tabel rincian berbeda antar versi Khanza.
$tabelDetail = null;
foreach (['permintaan_detail_permintaan_lab', 'detail_permintaan_lab'] as $kandidat) {
    if (tabelAda($kandidat, 'db_sik')) {
        $tabelDetail = $kandidat;
        break;
    }
}
if ($tabelDetail === null) {
    gagal(
        'Tabel rincian permintaan lab tidak ditemukan pada ' . konfig('db_sik.name', '')
        . '. Dicari: permintaan_detail_permintaan_lab, detail_permintaan_lab.',
        500
    );
}

$kolReg    = $petaKolom('reg_periksa');
$kolPasien = $petaKolom('pasien');
$kolPoli   = $petaKolom('poliklinik');
$kolPenjab = $petaKolom('penjab');
$kolDokter = $petaKolom('dokter');
$kolKamarInap = $petaKolom('kamar_inap');
$kolKamar     = $petaKolom('kamar');
$kolBangsal   = $petaKolom('bangsal');

// Penghubung yang benar-benar wajib.
if (!isset($kolPermintaan['noorder'])) {
    gagal('Kolom permintaan_lab.noorder tidak ditemukan — struktur tidak dikenali.', 500);
}
if (!isset($kolPermintaan['tgl_permintaan'])) {
    gagal(
        'Kolom permintaan_lab.tgl_permintaan tidak ditemukan, sehingga penyaringan '
        . 'berdasarkan tanggal tidak dapat dilakukan.',
        500
    );
}

$adaNoRawat = isset($kolPermintaan['no_rawat']) && $kolReg !== null
    && isset($kolReg['no_rawat'], $kolReg['no_rkm_medis']);

// ---------------------------------------------------------------------
// Susun kueri sumber
// ---------------------------------------------------------------------

$pilih  = [];
$gabung = '';

foreach ([
    'noorder', 'no_rawat', 'tgl_permintaan', 'jam_permintaan',
    'tgl_sampel', 'jam_sampel', 'tgl_hasil', 'jam_hasil',
    'dokter_perujuk', 'status', 'informasi_tambahan', 'diagnosa_klinis',
] as $k) {
    if (isset($kolPermintaan[$k])) {
        $pilih[] = 'p.`' . $kolPermintaan[$k] . '` AS `' . $k . '`';
    }
}

if ($adaNoRawat) {
    $gabung .= ' LEFT JOIN reg_periksa r ON r.`' . $kolReg['no_rawat'] . '` = p.`'
        . $kolPermintaan['no_rawat'] . '`';
    $pilih[] = 'r.`' . $kolReg['no_rkm_medis'] . '` AS `no_rkm_medis`';

    if ($kolPasien !== null && isset($kolPasien['no_rkm_medis'])) {
        $gabung .= ' LEFT JOIN pasien ps ON ps.`' . $kolPasien['no_rkm_medis'] . '` = r.`'
            . $kolReg['no_rkm_medis'] . '`';

        foreach (['nm_pasien', 'jk', 'tmp_lahir', 'tgl_lahir', 'alamat', 'email'] as $k) {
            if (isset($kolPasien[$k])) {
                $pilih[] = 'ps.`' . $kolPasien[$k] . '` AS `' . $k . '`';
            }
        }
    }

    if ($kolPoli !== null && isset($kolPoli['kd_poli'], $kolPoli['nm_poli']) && isset($kolReg['kd_poli'])) {
        $gabung .= ' LEFT JOIN poliklinik pl ON pl.`' . $kolPoli['kd_poli'] . '` = r.`'
            . $kolReg['kd_poli'] . '`';
        $pilih[] = 'r.`' . $kolReg['kd_poli'] . '` AS `kode_ruang`';
        $pilih[] = 'pl.`' . $kolPoli['nm_poli'] . '` AS `nama_ruang`';
    }

    if ($kolPenjab !== null && isset($kolPenjab['kd_pj'], $kolPenjab['png_jawab']) && isset($kolReg['kd_pj'])) {
        $gabung .= ' LEFT JOIN penjab pj ON pj.`' . $kolPenjab['kd_pj'] . '` = r.`'
            . $kolReg['kd_pj'] . '`';
        $pilih[] = 'r.`' . $kolReg['kd_pj'] . '` AS `kode_carabayar`';
        $pilih[] = 'pj.`' . $kolPenjab['png_jawab'] . '` AS `nama_carabayar`';
    }

    // Pasien rawat inap: ruangan sesungguhnya ada di bangsal, bukan poli.
    if ($kolKamarInap !== null && $kolKamar !== null && $kolBangsal !== null
        && isset($kolKamarInap['no_rawat'], $kolKamarInap['kd_kamar'])
        && isset($kolKamar['kd_kamar'], $kolKamar['kd_bangsal'])
        && isset($kolBangsal['kd_bangsal'], $kolBangsal['nm_bangsal'])) {

        $gabung .= ' LEFT JOIN kamar_inap ki ON ki.`' . $kolKamarInap['no_rawat'] . '` = p.`'
            . $kolPermintaan['no_rawat'] . '`'
            . ' LEFT JOIN kamar km ON km.`' . $kolKamar['kd_kamar'] . '` = ki.`'
            . $kolKamarInap['kd_kamar'] . '`'
            . ' LEFT JOIN bangsal bg ON bg.`' . $kolBangsal['kd_bangsal'] . '` = km.`'
            . $kolKamar['kd_bangsal'] . '`';
        $pilih[] = 'bg.`' . $kolBangsal['nm_bangsal'] . '` AS `nama_bangsal`';
    }
}

// Nama dokter perujuk — kolomnya di permintaan_lab menyimpan KODE.
if ($kolDokter !== null && isset($kolDokter['kd_dokter'], $kolDokter['nm_dokter'])
    && isset($kolPermintaan['dokter_perujuk'])) {
    $gabung .= ' LEFT JOIN dokter dk ON dk.`' . $kolDokter['kd_dokter'] . '` = p.`'
        . $kolPermintaan['dokter_perujuk'] . '`';
    $pilih[] = 'dk.`' . $kolDokter['nm_dokter'] . '` AS `nama_dokter_perujuk`';
}

$sql = 'SELECT ' . implode(', ', $pilih)
    . ' FROM permintaan_lab p' . $gabung
    . ' WHERE p.`' . $kolPermintaan['tgl_permintaan'] . '` BETWEEN ? AND ?'
    . ' ORDER BY p.`' . $kolPermintaan['tgl_permintaan'] . '`, p.`' . $kolPermintaan['noorder'] . '`';

try {
    $orders = ambilSemua($sql, [$dari, $sampai], 'db_sik');
} catch (Throwable $e) {
    catat('error', 'Sync: gagal membaca permintaan_lab: ' . $e->getMessage());
    gagal('Gagal membaca permintaan_lab dari database utama: ' . $e->getMessage(), 500);
}

if ($orders === []) {
    sukses([], sprintf(
        'Tidak ada permintaan lab pada %s s.d. %s di database utama Khanza.',
        $dari, $sampai
    ), ['dari' => $dari, 'sampai' => $sampai, 'ditemukan' => 0]);
}

// ---------------------------------------------------------------------
// Kolom yang tersedia pada tabel tujuan
// ---------------------------------------------------------------------

$kolTujuan = [];
foreach (kolomTabel('permintaan_lab') as $k) {
    $kolTujuan[strtolower($k)] = $k;
}

$baru      = 0;
$diperbarui = 0;
$rincian   = 0;
$dilewati  = [];

foreach ($orders as $o) {
    $noorder = trim((string) ($o['noorder'] ?? ''));
    if ($noorder === '') {
        $dilewati[] = 'satu baris tanpa noorder';
        continue;
    }

    // Nilai untuk tabel bridging, hanya kolom yang benar-benar ada.
    $nilai = [];

    $isi = static function (string $kolom, $v) use (&$nilai, $kolTujuan): void {
        if (isset($kolTujuan[$kolom]) && $v !== null) {
            $nilai[$kolTujuan[$kolom]] = $v;
        }
    };

    // Tanggal Khanza kerap bernilai '0000-00-00' untuk "belum ada".
    $tanggal = static function ($v) {
        $v = trim((string) $v);
        return ($v === '' || strpos($v, '0000-00-00') === 0) ? null : $v;
    };

    $isi('noorder',            $noorder);
    $isi('no_rawat',           $o['no_rawat'] ?? null);
    $isi('no_rkm_medis',       $o['no_rkm_medis'] ?? null);
    $isi('nm_pasien',          $o['nm_pasien'] ?? null);
    $isi('email',              $o['email'] ?? '');
    $isi('jk',                 $o['jk'] ?? null);
    $isi('tmp_lahir',          $o['tmp_lahir'] ?? null);
    $isi('tgl_lahir',          $tanggal($o['tgl_lahir'] ?? null));
    $isi('alamat',             $o['alamat'] ?? null);
    $isi('tgl_permintaan',     $tanggal($o['tgl_permintaan'] ?? null));
    $isi('jam_permintaan',     $o['jam_permintaan'] ?? null);
    $isi('kode_dokter_perujuk', $o['dokter_perujuk'] ?? null);

    // dokter_perujuk pada bridging berisi NAMA; pada sik berisi KODE.
    $isi('dokter_perujuk', trim((string) ($o['nama_dokter_perujuk'] ?? '')) !== ''
        ? $o['nama_dokter_perujuk']
        : ($o['dokter_perujuk'] ?? null));

    $isi('status',             $o['status'] ?? null);

    // Rawat inap memakai nama bangsal bila ada; rawat jalan memakai poli.
    $ruang = trim((string) ($o['nama_bangsal'] ?? '')) !== ''
        ? $o['nama_bangsal']
        : ($o['nama_ruang'] ?? null);

    $isi('kode_ruang',         $o['kode_ruang'] ?? null);
    $isi('nama_ruang',         $ruang);
    $isi('kode_carabayar',     $o['kode_carabayar'] ?? null);
    $isi('nama_carabayar',     $o['nama_carabayar'] ?? null);
    $isi('informasi_tambahan', $o['informasi_tambahan'] ?? null);
    $isi('diagnosa_klinis',    $o['diagnosa_klinis'] ?? null);

    // Waktu pengambilan sampel, bila Khanza sudah mencatatnya.
    $isi('tgl_sampel',         $tanggal($o['tgl_sampel'] ?? null));
    $isi('jam_sampel',         $o['jam_sampel'] ?? null);

    try {
        $sudahAda = ambilSemua(
            'SELECT noorder FROM permintaan_lab WHERE noorder = ? LIMIT 1',
            [$noorder]
        ) !== [];

        $kolomTulis = array_keys($nilai);
        $tanda      = implode(',', array_fill(0, count($kolomTulis), '?'));

        // status_ambil hanya diisi saat baris BARU. Pada pembaruan ia
        // sengaja tidak disentuh: order yang sudah ditarik LIS bertanda
        // '1', dan meresetnya akan membuatnya terkirim dua kali.
        $kolomInsert = $kolomTulis;
        $nilaiInsert = array_values($nilai);
        if (!$sudahAda && isset($kolTujuan['status_ambil'])) {
            $kolomInsert[] = $kolTujuan['status_ambil'];
            $nilaiInsert[] = '0';
            $tanda        .= ',?';
        }

        $setPerbarui = [];
        foreach ($kolomTulis as $k) {
            if (strcasecmp($k, 'noorder') === 0) {
                continue;
            }
            $setPerbarui[] = '`' . $k . '` = VALUES(`' . $k . '`)';
        }

        jalankan(
            'INSERT INTO permintaan_lab (`' . implode('`,`', $kolomInsert) . '`) VALUES (' . $tanda . ')'
            . ($setPerbarui === [] ? '' : ' ON DUPLICATE KEY UPDATE ' . implode(', ', $setPerbarui)),
            $nilaiInsert
        );

        if ($sudahAda) {
            $diperbarui++;
        } else {
            $baru++;
        }
    } catch (Throwable $e) {
        $dilewati[] = $noorder . ': ' . $e->getMessage();
        catat('error', "Sync: gagal menulis $noorder: " . $e->getMessage());
        continue;
    }

    // -----------------------------------------------------------------
    // Rincian pemeriksaan — hapus lalu isi ulang.
    //
    // detail_permintaan_lab tidak memiliki kunci unik, sehingga upsert
    // tidak mungkin. Menghapus dulu juga membuat pemeriksaan yang
    // DIBATALKAN di Khanza ikut hilang di sini, bukan tertinggal.
    // -----------------------------------------------------------------
    try {
        $detail = ambilSemua(
            'SELECT kd_jenis_prw, id_template FROM `' . $tabelDetail . '` WHERE noorder = ?',
            [$noorder],
            'db_sik'
        );

        jalankan('DELETE FROM detail_permintaan_lab WHERE noorder = ?', [$noorder]);

        foreach ($detail as $d) {
            $kd  = trim((string) ($d['kd_jenis_prw'] ?? ''));
            $idt = isset($d['id_template']) ? (int) $d['id_template'] : 0;
            if ($kd === '') {
                continue;
            }
            jalankan(
                'INSERT INTO detail_permintaan_lab (noorder, kd_jenis_prw, id_template) VALUES (?,?,?)',
                [$noorder, $kd, $idt]
            );
            $rincian++;
        }
    } catch (Throwable $e) {
        $dilewati[] = $noorder . ' (rincian): ' . $e->getMessage();
        catat('error', "Sync: gagal menulis rincian $noorder: " . $e->getMessage());
    }
}

$menunggu = 0;
try {
    $b = ambilSemua("SELECT COUNT(*) AS n FROM permintaan_lab WHERE status_ambil = '0'");
    $menunggu = (int) ($b[0]['n'] ?? 0);
} catch (Throwable $e) {
    $menunggu = -1;
}

catat('info', sprintf(
    'Sync %s..%s: %d ditemukan, %d baru, %d diperbarui, %d rincian, %d dilewati',
    $dari, $sampai, count($orders), $baru, $diperbarui, $rincian, count($dilewati)
));

$pesan = sprintf(
    '%d permintaan pada %s s.d. %s: %d baru, %d diperbarui, %d rincian pemeriksaan.',
    count($orders), $dari, $sampai, $baru, $diperbarui, $rincian
);

if ($dilewati !== []) {
    $pesan .= sprintf(' %d dilewati (%s).', count($dilewati), implode('; ', array_slice($dilewati, 0, 3)));
}
if ($menunggu >= 0) {
    $pesan .= sprintf(' Kini %d menunggu diambil LIS.', $menunggu);
}

sukses(
    ['baru' => $baru, 'diperbarui' => $diperbarui, 'rincian' => $rincian],
    $pesan,
    [
        'dari'       => $dari,
        'sampai'     => $sampai,
        'ditemukan'  => count($orders),
        'baru'       => $baru,
        'diperbarui' => $diperbarui,
        'rincian'    => $rincian,
        'dilewati'   => count($dilewati),
        'menunggu'   => $menunggu,
        'tabel_detail_sumber' => $tabelDetail,
    ]
);
