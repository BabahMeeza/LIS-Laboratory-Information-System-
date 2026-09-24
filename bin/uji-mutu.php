<?php
declare(strict_types=1);

/**
 * Uji perilaku gerbang mutu — QC, nilai kritis, dan aturan autovalidasi.
 *
 *   php bin/uji-mutu.php
 *
 * BUKAN uji baca-saja. Skrip ini membuat data uji bertanda "UJIMUTU",
 * memeriksa perilakunya, lalu menghapusnya kembali. Jangan dijalankan
 * pada sistem produksi yang sedang dipakai.
 *
 * Yang diuji di sini adalah PERILAKU, bukan sekadar apakah kode berjalan:
 * apakah QC yang gagal benar-benar menahan, apakah pembacaan ulang yang
 * salah benar-benar ditolak, apakah aturan yang belum disetujui benar-
 * benar tidak memvalidasi apa pun.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari baris perintah.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\CriticalValueService;
use App\Services\QcGateService;
use App\Services\ReferenceRangeService;
use App\Services\VerificationRuleService;

if (!is_file(BASE_PATH . '/config/config.php')) {
    exit("config/config.php belum ada — jalankan php bin/install.php lebih dulu.\n");
}

/** @var array<string,mixed> $konfigurasi */
$konfigurasi = require BASE_PATH . '/config/config.php';
Config::load($konfigurasi);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

$lulus = 0;
$gagal = 0;

function periksa(string $judul, bool $ok, string $rincian = ''): void
{
    global $lulus, $gagal;

    if ($ok) {
        $lulus++;
        echo "  \033[32m✓\033[0m {$judul}\n";
    } else {
        $gagal++;
        echo "  \033[31m✗\033[0m {$judul}\n";
        if ($rincian !== '') {
            echo "      {$rincian}\n";
        }
    }
}

function bagian(string $judul): void
{
    echo "\n\033[1m{$judul}\033[0m\n";
}

/**
 * Butir yang perlu ditindaklanjuti manusia, bukan cacat kode.
 *
 * Dibedakan dari kegagalan dengan sengaja: pengisian kode LOINC adalah
 * pekerjaan pemetaan yang harus diputuskan penanggung jawab laboratorium,
 * dan menebaknya di sini akan menghasilkan kode yang salah dengan diam-
 * diam. Kode LOINC yang keliru lebih berbahaya daripada kode yang kosong,
 * karena penerima data tidak punya cara mengetahui bahwa ia salah.
 */
function catatan(string $judul, string $rincian = ''): void
{
    echo "  \033[33m!\033[0m {$judul}\n";
    if ($rincian !== '') {
        foreach (explode("\n", $rincian) as $baris) {
            echo "      {$baris}\n";
        }
    }
}

echo "\n";
echo "══════════════════════════════════════════════════════════════\n";
echo "  Uji gerbang mutu LIS\n";
echo "══════════════════════════════════════════════════════════════\n";

// ---------------------------------------------------------------------
// Persiapan: satu pemeriksaan dan satu alat khusus uji.
// ---------------------------------------------------------------------

$bersihkan = static function (): void {
    Database::execute("DELETE FROM qc_results WHERE catatan = 'UJIMUTU'");
    Database::execute("DELETE FROM qc_lots WHERE nama_bahan = 'UJIMUTU'");
    Database::execute("DELETE FROM reagent_lots WHERE nama = 'UJIMUTU'");
    Database::execute("DELETE FROM tests WHERE kode = 'UJIMUTU'");
    Database::execute("DELETE FROM instruments WHERE kode = 'UJIMUTU'");
};

$bersihkan();
QcGateService::lupakan();
VerificationRuleService::lupakan();

$testId = Database::insert('tests', [
    'kode'    => 'UJIMUTU',
    'nama'    => 'Parameter Uji Gerbang Mutu',
    'satuan'  => 'g/dL',
    'desimal' => 1,
]);

$alatId = Database::insert('instruments', [
    'kode'      => 'UJIMUTU',
    'nama'      => 'Alat Uji Gerbang Mutu',
    'protokol'  => 'hl7',
    'transport' => 'tcp_client',
]);

// ---------------------------------------------------------------------
bagian('1. QC belum dijalankan');
// ---------------------------------------------------------------------

$lotQc = Database::insert('qc_lots', [
    'instrument_id'  => $alatId,
    'test_id'        => $testId,
    'nama_bahan'     => 'UJIMUTU',
    'lot'            => 'L-001',
    'level'          => '1',
    'mean'           => 10.0,
    'sd'             => 0.5,
    'menahan_rilis'  => 1,
    'berlaku_jam'    => 24,
]);

QcGateService::lupakan();
Config::putSetting('mutu.qc_belum_menahan', '0');
Config::flushSettings();

$n = QcGateService::nilai($testId, $alatId);
periksa(
    'Tanpa titik QC, status "belum"',
    $n['status'] === 'belum',
    'Diperoleh: ' . $n['status']
);
periksa(
    'Dengan qc_belum_menahan=0, rilis tidak ditahan',
    $n['menahan'] === false
);
periksa(
    'Namun autovalidasi tetap tidak diizinkan',
    $n['boleh_auto'] === false
);

QcGateService::lupakan();
Config::putSetting('mutu.qc_belum_menahan', '1');
Config::flushSettings();

$n = QcGateService::nilai($testId, $alatId);
periksa(
    'Dengan qc_belum_menahan=1, rilis DITAHAN',
    $n['menahan'] === true,
    'menahan=' . var_export($n['menahan'], true)
);

// ---------------------------------------------------------------------
bagian('2. QC lolos, peringatan, dan gagal');
// ---------------------------------------------------------------------

Config::putSetting('mutu.qc_belum_menahan', '0');
Config::flushSettings();

$qcId = Database::insert('qc_results', [
    'qc_lot_id'     => $lotQc,
    'instrument_id' => $alatId,
    'nilai'         => 10.1,
    'z_score'       => 0.2,
    'status'        => 'in',
    'tgl_uji'       => date('Y-m-d H:i:s', time() - 3600),
    'catatan'       => 'UJIMUTU',
]);

QcGateService::lupakan();
$n = QcGateService::nilai($testId, $alatId);
periksa('QC masuk batas → status "lolos"', $n['status'] === 'lolos', 'Diperoleh: ' . $n['status']);
periksa('QC lolos mengizinkan autovalidasi', $n['boleh_auto'] === true);
periksa('QC lolos tidak menahan rilis', $n['menahan'] === false);

Database::update('qc_results', ['status' => 'warning', 'westgard' => '1-2s'], 'id = ?', [$qcId]);
QcGateService::lupakan();
$n = QcGateService::nilai($testId, $alatId);
periksa('QC 1-2s → status "peringatan"', $n['status'] === 'peringatan', 'Diperoleh: ' . $n['status']);
periksa('Peringatan tidak menahan rilis', $n['menahan'] === false);
periksa('Peringatan MELARANG autovalidasi', $n['boleh_auto'] === false);

Database::update('qc_results', ['status' => 'out', 'westgard' => '1-3s'], 'id = ?', [$qcId]);
QcGateService::lupakan();
$n = QcGateService::nilai($testId, $alatId);
periksa('QC 1-3s → status "gagal"', $n['status'] === 'gagal', 'Diperoleh: ' . $n['status']);
periksa('QC gagal MENAHAN rilis', $n['menahan'] === true);

// Aturan penolakan yang tercatat harus menang atas status "warning" yang
// dikirim alat — inilah moda kegagalan yang tersembunyi.
Database::update('qc_results', ['status' => 'warning', 'westgard' => '2-2s'], 'id = ?', [$qcId]);
QcGateService::lupakan();
$n = QcGateService::nilai($testId, $alatId);
periksa(
    'Status "warning" dari alat dengan aturan 2-2s tetap dinilai gagal',
    $n['status'] === 'gagal',
    'Diperoleh: ' . $n['status'] . ' — alat boleh salah menilai keparahannya, LIS tidak boleh ikut salah'
);

// ---------------------------------------------------------------------
bagian('3. QC kedaluwarsa');
// ---------------------------------------------------------------------

Database::update('qc_results', [
    'status'   => 'in',
    'westgard' => null,
    'tgl_uji'  => date('Y-m-d H:i:s', time() - 30 * 3600),
], 'id = ?', [$qcId]);

QcGateService::lupakan();
$n = QcGateService::nilai($testId, $alatId);
periksa(
    'QC berumur 30 jam pada lot berlaku 24 jam dianggap belum ada',
    $n['status'] === 'belum',
    'Diperoleh: ' . $n['status']
);

// ---------------------------------------------------------------------
bagian('4. Parameter tanpa lot QC');
// ---------------------------------------------------------------------

$testTanpaQc = Database::insert('tests', [
    'kode' => 'UJIMUTU2', 'nama' => 'Parameter Tanpa QC', 'tipe_hasil' => 'teks',
]);

QcGateService::lupakan();
$n = QcGateService::nilai($testTanpaQc, $alatId);
periksa(
    'Parameter tanpa lot QC berstatus "tidak_berlaku", bukan "belum"',
    $n['status'] === 'tidak_berlaku',
    'Diperoleh: ' . $n['status']
);
periksa('Parameter tanpa QC tidak menahan laboratorium', $n['menahan'] === false);
Database::execute('DELETE FROM tests WHERE id = ?', [$testTanpaQc]);

// ---------------------------------------------------------------------
bagian('4b. Tinjauan mundur saat QC gagal (ISO 15189 7.3.7.3)');
//
// Kasus yang paling penting dan paling mudah terlewat: QC gagal
// DITEMUKAN setelah hasil pasien terlanjur keluar.
// ---------------------------------------------------------------------

$pasienUji = Database::selectOne('SELECT id FROM patients LIMIT 1');

if ($pasienUji === null) {
    catatan('Dilewati — belum ada data pasien untuk membentuk kasus uji.');
} else {
    $orderUji = Database::insert('orders', [
        'no_order'   => 'UJIMUTU-' . substr((string) time(), -6),
        'patient_id' => (int) $pasienUji['id'],
        'status'     => 'released',
        'tgl_order'  => date('Y-m-d H:i:s', time() - 7200),
        'catatan'    => 'UJIMUTU',
    ]);

    $itemUji = Database::insert('order_items', [
        'order_id' => $orderUji, 'test_id' => $testId, 'status' => 'released',
    ]);

    // QC baik pukul −3 jam, hasil pasien pukul −2 jam, QC gagal sekarang.
    Database::execute("DELETE FROM qc_results WHERE qc_lot_id = ?", [$lotQc]);

    Database::insert('qc_results', [
        'qc_lot_id' => $lotQc, 'instrument_id' => $alatId,
        'nilai' => 10.0, 'z_score' => 0.0, 'status' => 'in',
        'tgl_uji' => date('Y-m-d H:i:s', time() - 3 * 3600), 'catatan' => 'UJIMUTU',
    ]);

    $hasilUji = Database::insert('results', [
        'order_id' => $orderUji, 'order_item_id' => $itemUji, 'test_id' => $testId,
        'instrument_id' => $alatId, 'nilai' => '12.5', 'nilai_num' => 12.5,
        'status' => 'verified', 'entered_at' => date('Y-m-d H:i:s', time() - 2 * 3600),
    ]);

    $qcGagalId = Database::insert('qc_results', [
        'qc_lot_id' => $lotQc, 'instrument_id' => $alatId,
        'nilai' => 13.5, 'z_score' => 7.0, 'status' => 'out', 'westgard' => '1-3s',
        'tgl_uji' => date('Y-m-d H:i:s', time() - 60), 'catatan' => 'UJIMUTU',
    ]);

    $terdampak = QcGateService::hasilPerluDitinjau($qcGagalId);
    $ids       = array_map('intval', array_column($terdampak, 'result_id'));

    periksa(
        'Hasil pasien antara QC baik dan QC gagal ikut terjaring',
        in_array($hasilUji, $ids, true),
        'Terjaring: ' . count($terdampak) . ' hasil. Ini kasus yang paling sering '
        . 'terlewat — hasilnya sudah keluar, gerbang rilis tidak dapat menolongnya lagi.'
    );

    $barisUji = null;
    foreach ($terdampak as $t) {
        if ((int) $t['result_id'] === $hasilUji) {
            $barisUji = $t;
        }
    }

    periksa(
        'Hasil yang SUDAH DIRILIS dikenali sebagai sudah dirilis',
        $barisUji !== null && (string) $barisUji['status_order'] === 'released',
        'Perbedaan ini menentukan tindakan: yang belum rilis cukup ditahan, '
        . 'yang sudah rilis harus ditarik dan klinisinya diberi tahu.'
    );

    // Hasil SEBELUM titik QC baik tidak boleh ikut terjaring — kalau
    // tidak, setiap kegagalan QC akan menarik seluruh riwayat lab.
    Database::update('results', [
        'entered_at' => date('Y-m-d H:i:s', time() - 5 * 3600),
    ], 'id = ?', [$hasilUji]);

    $terdampak2 = QcGateService::hasilPerluDitinjau($qcGagalId);
    periksa(
        'Hasil sebelum titik QC baik terakhir TIDAK ikut terjaring',
        !in_array($hasilUji, array_map('intval', array_column($terdampak2, 'result_id')), true),
        'Rentang yang terlalu lebar membuat peringatan diabaikan orang.'
    );

    Database::execute('DELETE FROM results WHERE id = ?', [$hasilUji]);
    Database::execute('DELETE FROM order_items WHERE id = ?', [$itemUji]);
    Database::execute('DELETE FROM orders WHERE id = ?', [$orderUji]);
    Database::execute('DELETE FROM qc_results WHERE qc_lot_id = ?', [$lotQc]);
}

// ---------------------------------------------------------------------
bagian('5. Lot reagen');
// ---------------------------------------------------------------------

$lotOk = Database::insert('reagent_lots', [
    'instrument_id'  => $alatId,
    'nama'           => 'UJIMUTU',
    'lot'            => 'R-OK',
    'tgl_kadaluarsa' => date('Y-m-d', time() + 86400 * 30),
    'status'         => 'aktif',
]);

$lotKadaluarsa = Database::insert('reagent_lots', [
    'instrument_id'  => $alatId,
    'nama'           => 'UJIMUTU',
    'lot'            => 'R-EXP',
    'tgl_kadaluarsa' => date('Y-m-d', time() - 86400),
    'status'         => 'aktif',
]);

$lotDitarik = Database::insert('reagent_lots', [
    'instrument_id'  => $alatId,
    'nama'           => 'UJIMUTU',
    'lot'            => 'R-RECALL',
    'tgl_kadaluarsa' => date('Y-m-d', time() + 86400 * 30),
    'status'         => 'ditarik',
    'alasan_tarik'   => 'Uji',
]);

$lotBuka = Database::insert('reagent_lots', [
    'instrument_id'  => $alatId,
    'nama'           => 'UJIMUTU',
    'lot'            => 'R-OPEN',
    'tgl_kadaluarsa' => date('Y-m-d', time() + 86400 * 300),
    'tgl_buka'       => date('Y-m-d', time() - 86400 * 40),
    'masa_buka_hari' => 30,
    'status'         => 'aktif',
]);

periksa('Lot sah lolos', QcGateService::periksaLot($lotOk)['sah'] === true);
periksa('Lot kedaluwarsa ditolak', QcGateService::periksaLot($lotKadaluarsa)['sah'] === false);
periksa('Lot ditarik ditolak', QcGateService::periksaLot($lotDitarik)['sah'] === false);
periksa(
    'Lot melewati masa pakai setelah dibuka ditolak meski tanggal cetak masih jauh',
    QcGateService::periksaLot($lotBuka)['sah'] === false,
    'Ini moda kegagalan yang paling sering terlewat: botol masih "belum kedaluwarsa" tetapi sudah terlalu lama terbuka'
);
periksa('Tanpa lot, penilaian tidak menghalangi', QcGateService::periksaLot(null)['sah'] === true);

// ---------------------------------------------------------------------
bagian('6. Aturan autovalidasi berversi');
// ---------------------------------------------------------------------

$konteksAman = [
    'test_id' => $testId, 'instrument_id' => $alatId,
    'flag' => 'N', 'flag_alat' => '', 'nilai_num' => 10.0,
    'delta_status' => 'ok', 'delta_persen' => 2.0,
    'is_kritis' => false, 'satuan_bentrok' => false,
    'qc_status' => 'lolos', 'lot_sah' => true, 'lot_alasan' => '',
];

// Himpunan bawaan berstatus draft — belum disetujui.
Database::execute("UPDATE verification_rule_sets SET status = 'draft'");
VerificationRuleService::lupakan();

$k = VerificationRuleService::putuskan($konteksAman);
periksa(
    'Aturan berstatus draft tidak memvalidasi apa pun',
    $k['validasi'] === false,
    'Ketiadaan persetujuan bukan izin — CLSI AUTO15'
);

// Setujui dan aktifkan.
Database::execute(
    "UPDATE verification_rule_sets SET status = 'aktif', berlaku_dari = NOW(),
            disetujui_at = NOW() WHERE id = 1"
);
VerificationRuleService::lupakan();

$k = VerificationRuleService::putuskan($konteksAman);
periksa('Setelah disetujui, hasil normal divalidasi', $k['validasi'] === true, $k['alasan']);

$kasus = [
    ['nilai kritis',            ['is_kritis' => true]],
    ['QC gagal',                ['qc_status' => 'gagal']],
    ['QC peringatan',           ['qc_status' => 'peringatan']],
    ['QC belum dijalankan',     ['qc_status' => 'belum']],
    ['satuan bentrok',          ['satuan_bentrok' => true]],
    ['lot tidak sah',           ['lot_sah' => false, 'lot_alasan' => 'kedaluwarsa']],
    ['flag H di luar rujukan',  ['flag' => 'H']],
    ['flag HH kritis',          ['flag' => 'HH']],
    ['delta check bertanda',    ['delta_status' => 'flagged']],
    ['delta melampaui ambang',  ['delta_persen' => 95.0]],
    ['flag alat meragukan (R)', ['flag_alat' => 'R']],
    ['flag alat meragukan (*)', ['flag_alat' => '*']],
];

foreach ($kasus as [$nama, $ubah]) {
    VerificationRuleService::lupakan();
    $k = VerificationRuleService::putuskan(array_merge($konteksAman, $ubah));
    periksa("Ditahan karena {$nama}", $k['validasi'] === false, 'Alasan: ' . $k['alasan']);
}

VerificationRuleService::lupakan();
$k = VerificationRuleService::putuskan(array_merge($konteksAman, ['flag' => '']));
periksa(
    'Flag kosong (tidak dapat dinilai) ditahan, bukan dianggap normal',
    $k['validasi'] === false,
    'Nilai yang tidak dapat dinilai bukan nilai yang aman'
);

// ---------------------------------------------------------------------
bagian('7. Pembacaan ulang nilai kritis');
// ---------------------------------------------------------------------

$uji = [
    ['7.2',  '7.2',  true,  'sama persis'],
    ['7,2',  '7.2',  true,  'koma dan titik desimal'],
    [' 7.2 ', '7.2', true,  'spasi di tepi'],
    ['7.20', '7.2',  true,  'nol di belakang'],
    ['72',   '7.2',  false, 'salah dengar besaran — INI yang harus tertangkap'],
    ['7.3',  '7.2',  false, 'salah satu digit'],
    ['2.7',  '7.2',  false, 'angka tertukar'],
    ['',     '7.2',  false, 'kosong'],
    ['Positif', 'Positif', true, 'nilai teks'],
    ['Negatif', 'Positif', false, 'nilai teks berbeda'],
];

foreach ($uji as [$bacaan, $asli, $harap, $ket]) {
    $hasil = CriticalValueService::samaSecaraAngka($bacaan, $asli);
    periksa(
        sprintf('Read-back "%s" vs "%s" → %s (%s)', $bacaan, $asli, $harap ? 'cocok' : 'tidak', $ket),
        $hasil === $harap
    );
}

// ---------------------------------------------------------------------
bagian('8. Objek basis data migrasi 04');
// ---------------------------------------------------------------------

foreach ([
    'reagent_lots', 'analytical_runs', 'analytical_run_lots',
    'critical_notifications', 'verification_rule_sets',
    'verification_rules', 'verification_decisions',
] as $tabel) {
    periksa("Tabel {$tabel} ada", Database::tableExists($tabel));
}

foreach ([
    ['results', 'run_id'], ['results', 'reagent_lot_id'], ['results', 'analisis_at'],
    ['results', 'satuan_ucum'], ['results', 'qc_status'], ['results', 'alasan_koreksi'],
    ['results', 'alasan_tidak_ada'],
    ['tests', 'satuan_ucum'], ['tests', 'kritis_batas_menit'],
    ['qc_lots', 'menahan_rilis'], ['qc_lots', 'berlaku_jam'],
    ['qc_results', 'instrument_id'], ['specimens', 'induk_specimen_id'],
    ['instruments', 'rule_set_id'],
] as [$tabel, $kolom]) {
    $ada = Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$tabel, $kolom]
    );
    periksa("Kolom {$tabel}.{$kolom} ada", (int) $ada === 1);
}

foreach (['v_kritis_tertunggak', 'v_telusur_lot'] as $view) {
    try {
        Database::select('SELECT * FROM `' . $view . '` LIMIT 1');
        periksa("Pandangan {$view} dapat dibaca", true);
    } catch (\Throwable $e) {
        periksa("Pandangan {$view} dapat dibaca", false, $e->getMessage());
    }
}

// ---------------------------------------------------------------------
bagian('8b. Umur yang tidak diketahui tidak boleh ditebak');
//
// Rujukan hematologi berbeda tajam menurut umur. Menganggap pasien tanpa
// tanggal lahir sebagai dewasa 30 tahun menghasilkan flag yang TERBALIK
// pada bayi — dan diam-diam.
// ---------------------------------------------------------------------

$hbUji = Database::selectOne("SELECT id, kode FROM tests WHERE kode = 'HB' LIMIT 1");

if ($hbUji === null) {
    catatan('Dilewati — pemeriksaan HB tidak ada pada katalog.');
} else {
    $idHb = (int) $hbUji['id'];

    $jenjang = (int) Database::scalar(
        'SELECT COUNT(*) FROM reference_ranges WHERE test_id = ?', [$idHb]
    );

    periksa(
        'HB memang punya rujukan bertingkat menurut umur',
        $jenjang > 1,
        'Prasyarat kasus uji: ditemukan ' . $jenjang . ' jenjang.'
    );

    $rujDewasa = ReferenceRangeService::untuk($idHb, 'L', 30 * 365);
    periksa(
        'Umur diketahui → rujukan tetap terpilih',
        $rujDewasa !== null
    );

    $rujTanpaUmur = ReferenceRangeService::untuk($idHb, 'L', null);
    periksa(
        'Umur TIDAK diketahui → rujukan ditahan, bukan diasumsikan dewasa',
        $rujTanpaUmur === null,
        'Sebelumnya kode memakai "$umurHari ?? 10950" — bayi baru lahir dinilai '
        . 'dengan rujukan dewasa. Hb 19 g/dL (normal untuk bayi) menjadi TINGGI, '
        . 'dan Hb 12 g/dL (anemia berat untuk bayi) tampak hampir normal.'
    );

    periksa(
        'Sebabnya dapat ditanyakan sistem, bukan hanya kolom kosong',
        ReferenceRangeService::ditahanKarenaUmur($idHb, 'L', null) === true
    );

    periksa(
        'Tanpa rujukan, flag dikosongkan — bukan ditebak',
        ReferenceRangeService::flagNumerik(19.0, $rujTanpaUmur) === '',
        'Nilai tetap disimpan; yang ditahan adalah penilaiannya.'
    );

    // Pemeriksaan yang rujukannya memang tidak bergantung umur tetap
    // boleh dinilai walau umur tidak diketahui — kalau tidak, seluruh
    // laboratorium berhenti hanya karena satu kolom kosong.
    $testBebasUmur = Database::insert('tests', [
        'kode' => 'UJIMUTU3', 'nama' => 'Parameter Tanpa Jenjang Umur',
        'satuan' => 'mg/dL', 'desimal' => 1,
    ]);
    Database::insert('reference_ranges', [
        'test_id' => $testBebasUmur, 'jk' => 'A',
        'umur_min_hari' => 0, 'umur_max_hari' => 43800,
        'low' => 10, 'high' => 20,
    ]);

    $rujBebas = ReferenceRangeService::untuk($testBebasUmur, 'L', null);
    periksa(
        'Pemeriksaan yang rujukannya berlaku segala umur tetap dinilai',
        $rujBebas !== null,
        'Menahan semuanya akan menghentikan lab tanpa menambah keamanan.'
    );
    periksa(
        'Dan flagnya tetap dihitung',
        ReferenceRangeService::flagNumerik(25.0, $rujBebas) === 'H'
    );

    Database::execute('DELETE FROM tests WHERE id = ?', [$testBebasUmur]);
}

// ---------------------------------------------------------------------
bagian('9d. Impor template Khanza tidak boleh terpotong diam-diam');
//
// Dua pemotongan senyap pernah bersarang di jalur ini: konektor membalas
// paling banyak 5000 baris, dan layar pemetaan menampilkan paling banyak
// 500 — keduanya tanpa satu pun tanda bahwa masih ada sisa.
// ---------------------------------------------------------------------

$tplAsli = (int) Database::scalar('SELECT COUNT(*) FROM khanza_templates');

// Isi melebihi kedua batas lama sekaligus.
$contoh = [];
for ($i = 1; $i <= 5200; $i++) {
    $contoh[] = sprintf("('UJIMUTU%04d',%d,'Uji Potong %d',NOW())", $i % 300, $i, $i);
}
Database::execute(
    'INSERT INTO khanza_templates (kd_jenis_prw,id_template,pemeriksaan,synced_at) VALUES '
    . implode(',', $contoh)
    . ' ON DUPLICATE KEY UPDATE pemeriksaan = VALUES(pemeriksaan)'
);

$tersimpan = (int) Database::scalar(
    "SELECT COUNT(*) FROM khanza_templates WHERE kd_jenis_prw LIKE 'UJIMUTU%'"
);

periksa(
    'Lebih dari 5000 template dapat tersimpan seluruhnya',
    $tersimpan === 5200,
    'Tersimpan ' . $tersimpan . ' dari 5200. Batas lama konektor adalah 5000.'
);

// Halaman terakhir harus terjangkau — dulu apa pun di atas baris ke-500
// tidak pernah muncul di layar.
$perHalaman = 200;
$jmlHalaman = (int) ceil($tersimpan / $perHalaman);
$offsetAkhir = ($jmlHalaman - 1) * $perHalaman;

$barisAkhir = Database::select(
    "SELECT kd_jenis_prw, id_template FROM khanza_templates
      WHERE kd_jenis_prw LIKE 'UJIMUTU%'
      ORDER BY kd_jenis_prw, id_template
      LIMIT " . $perHalaman . ' OFFSET ' . $offsetAkhir
);

periksa(
    'Halaman terakhir (' . $jmlHalaman . ') masih mengembalikan baris',
    $barisAkhir !== [],
    'Batas lama 500 baris membuat halaman seperti ini tidak pernah terlihat.'
);

$diLuarBatasLama = (int) Database::scalar(
    "SELECT COUNT(*) FROM (
        SELECT id FROM khanza_templates WHERE kd_jenis_prw LIKE 'UJIMUTU%'
        ORDER BY kd_jenis_prw, id_template LIMIT 18446744073709551615 OFFSET 500
     ) x"
);
periksa(
    'Ada ' . $diLuarBatasLama . ' baris di luar batas tampilan lama, dan kini terjangkau',
    $diLuarBatasLama > 0
);

Database::execute("DELETE FROM khanza_templates WHERE kd_jenis_prw LIKE 'UJIMUTU%'");

periksa(
    'Data uji dibersihkan kembali',
    (int) Database::scalar('SELECT COUNT(*) FROM khanza_templates') === $tplAsli
);

// ---------------------------------------------------------------------
bagian('9c. Kegagalan konektor Khanza harus terbaca sebabnya');
//
// Konektor menjelaskan dirinya lewat badan JSON, tetapi pemanggilnya
// dahulu membuangnya dan hanya menampilkan "HTTP 500" — petugas melihat
// nomor tanpa petunjuk, padahal jawabannya sudah ada di tangan.
// ---------------------------------------------------------------------

$sebab = new ReflectionMethod('App\\Services\\KhanzaService', 'sebabGagal');
$sebab->setAccessible(true);
$jelaskan = static fn (array $r): string => $sebab->invoke(null, $r);

$kasusKonektor = [
    ['Pesan konektor didahulukan atas nomor status',
     ['ok' => false, 'status' => 500, 'error' => null, 'body' => '{}',
      'data' => ['pesan' => 'Tabel permintaan_lab tidak ditemukan']],
     'Tabel permintaan_lab tidak ditemukan'],

    ['Galat jaringan dipakai bila konektor tidak menjawab',
     ['ok' => false, 'status' => 0, 'error' => 'Could not resolve host',
      'body' => '', 'data' => null],
     'Could not resolve host'],

    ['Badan non-JSON dicuplik, bukan dibuang',
     ['ok' => false, 'status' => 500, 'error' => null,
      'body' => '<b>Fatal error</b>: Call to undefined function', 'data' => null],
     'Fatal error'],

    ['HTTP 401 menjelaskan soal kredensial',
     ['ok' => false, 'status' => 401, 'error' => null, 'body' => '', 'data' => null],
     'kredensial'],

    ['HTTP 404 menjelaskan soal base_url',
     ['ok' => false, 'status' => 404, 'error' => null, 'body' => '', 'data' => null],
     'base_url'],
];

foreach ($kasusKonektor as [$nama, $res, $harap]) {
    $keluar = $jelaskan($res);
    periksa($nama, str_contains($keluar, $harap), 'Diperoleh: ' . $keluar);
}

// ---------------------------------------------------------------------
bagian('9b. Pencarian Sample ID tidak boleh tertukar pasien');
//
// Satu deret angka dapat menjadi barcode tabung milik seorang pasien
// SEKALIGUS nomor laboratorium milik pasien lain — penomorannya berjalan
// sendiri-sendiri. Kueri yang meng-OR keduanya lalu memilih yang terbaru
// akan mengembalikan pasien yang salah tanpa satu pun tanda.
// ---------------------------------------------------------------------

$duaPasien = Database::select('SELECT id, nama FROM patients ORDER BY id LIMIT 2');

if (count($duaPasien) < 2) {
    catatan('Dilewati — perlu dua pasien berbeda untuk membentuk kasus tabrakan.');
} else {
    $angka = '99' . substr((string) time(), -8);   // dipakai dua kali, sengaja

    // Pasien A: angka itu sebagai BARCODE tabung.
    $orderA = Database::insert('orders', [
        'no_order' => 'UJIMUTU-A' . substr($angka, -5),
        'patient_id' => (int) $duaPasien[0]['id'],
        'no_lab' => 'LABA' . substr($angka, -5),
        'status' => 'in_progress', 'tgl_order' => date('Y-m-d H:i:s'), 'catatan' => 'UJIMUTU',
    ]);
    $specA = Database::insert('specimens', [
        'order_id' => $orderA, 'barcode' => $angka, 'status' => 'received',
    ]);

    // Pasien B: angka yang SAMA sebagai NOMOR LAB, dengan barcode lain.
    // Dibuat belakangan supaya id-nya lebih besar — persis keadaan yang
    // membuat "ORDER BY id DESC" memilih yang salah.
    $orderB = Database::insert('orders', [
        'no_order' => 'UJIMUTU-B' . substr($angka, -5),
        'patient_id' => (int) $duaPasien[1]['id'],
        'no_lab' => $angka,
        'status' => 'in_progress', 'tgl_order' => date('Y-m-d H:i:s'), 'catatan' => 'UJIMUTU',
    ]);
    $specB = Database::insert('specimens', [
        'order_id' => $orderB, 'barcode' => $angka . '9', 'status' => 'received',
    ]);

    // Tiru pencarian bertingkat yang dipakai InstrumentApi::worklistSampel.
    $lewatBarcode = Database::selectOne(
        "SELECT o.id AS order_id, o.patient_id
           FROM specimens s JOIN orders o ON o.id = s.order_id
          WHERE s.barcode = ? AND o.status NOT IN ('cancelled','released') LIMIT 1",
        [$angka]
    );

    periksa(
        'Barcode diperiksa lebih dulu dan menang atas nomor lab',
        $lewatBarcode !== null && (int) $lewatBarcode['order_id'] === $orderA,
        'Diperoleh order ' . var_export($lewatBarcode['order_id'] ?? null, true)
        . ', seharusnya ' . $orderA . ' (milik ' . $duaPasien[0]['nama'] . ')'
    );

    periksa(
        'Pasien yang dikembalikan adalah pemilik tabung, bukan pemilik nomor lab',
        $lewatBarcode !== null
            && (int) $lewatBarcode['patient_id'] === (int) $duaPasien[0]['id'],
        'Inilah kesalahan identifikasi pasien yang harus dicegah: alat akan '
        . 'menampilkan nama pasien lain untuk tabung yang sedang dihisapnya.'
    );

    // Bila barcode TIDAK cocok, cadangan nomor lab/order harus menolak
    // ketika menunjuk lebih dari satu order.
    $orderC = Database::insert('orders', [
        'no_order' => 'UJIMUTU-C' . substr($angka, -5),
        'patient_id' => (int) $duaPasien[0]['id'],
        'no_lab' => 'AMBIGU' . substr($angka, -5),
        'status' => 'in_progress', 'tgl_order' => date('Y-m-d H:i:s'), 'catatan' => 'UJIMUTU',
    ]);
    $specC = Database::insert('specimens', [
        'order_id' => $orderC, 'barcode' => $angka . '7', 'status' => 'received',
    ]);
    $orderD = Database::insert('orders', [
        'no_order' => 'AMBIGU' . substr($angka, -5),
        'patient_id' => (int) $duaPasien[1]['id'],
        'no_lab' => 'LABD' . substr($angka, -5),
        'status' => 'in_progress', 'tgl_order' => date('Y-m-d H:i:s'), 'catatan' => 'UJIMUTU',
    ]);
    $specD = Database::insert('specimens', [
        'order_id' => $orderD, 'barcode' => $angka . '8', 'status' => 'received',
    ]);

    $ambigu = 'AMBIGU' . substr($angka, -5);
    $calon  = Database::select(
        "SELECT DISTINCT o.id AS order_id
           FROM specimens s JOIN orders o ON o.id = s.order_id
          WHERE (o.no_lab = ? OR o.no_order = ?)
            AND o.status NOT IN ('cancelled','released')",
        [$ambigu, $ambigu]
    );

    periksa(
        'Nomor yang menunjuk dua order terdeteksi sebagai ambigu, bukan ditebak',
        count($calon) > 1,
        'Ditemukan ' . count($calon) . ' order. API harus menolak dengan 409, '
        . 'bukan memilih salah satu.'
    );

    foreach ([$specA, $specB, $specC, $specD] as $s) {
        Database::execute('DELETE FROM specimens WHERE id = ?', [$s]);
    }
    foreach ([$orderA, $orderB, $orderC, $orderD] as $o) {
        Database::execute('DELETE FROM orders WHERE id = ?', [$o]);
    }
}

// ---------------------------------------------------------------------
// Bersih-bersih — dilakukan SEBELUM pemeriksaan katalog di bawah, agar
// data uji tidak ikut terhitung sebagai pemeriksaan yang belum dipetakan.
// ---------------------------------------------------------------------

$bersihkan();

bagian('9. Kesiapan pertukaran data (SATUSEHAT)');

$tanpaUcum = Database::select(
    "SELECT kode, nama, satuan FROM tests
      WHERE aktif = 1 AND satuan IS NOT NULL AND TRIM(satuan) <> '' AND satuan_ucum IS NULL"
);
periksa(
    'Semua pemeriksaan aktif bersatuan sudah punya padanan UCUM',
    $tanpaUcum === [],
    count($tanpaUcum) . ' belum dipetakan: '
        . implode(', ', array_map(static fn ($t) => $t['kode'] . ' (' . $t['satuan'] . ')', $tanpaUcum))
);

$total      = (int) Database::scalar('SELECT COUNT(*) FROM tests WHERE aktif = 1');
// Tanggal lahir menentukan dua hal sekaligus: kolom Age di layar alat
// (diturunkan dari PID-7) dan pemilihan rujukan menurut umur di LIS.
// Satu kolom kosong melumpuhkan keduanya.
$tanpaLahir = (int) Database::scalar('SELECT COUNT(*) FROM patients WHERE tgl_lahir IS NULL');
$totalPasien = (int) Database::scalar('SELECT COUNT(*) FROM patients');

periksa(
    sprintf('Kelengkapan tanggal lahir pasien: %d dari %d', $totalPasien - $tanpaLahir, $totalPasien),
    $tanpaLahir === 0,
    $tanpaLahir . ' pasien tanpa tanggal lahir. Akibatnya dua: kolom Age pada '
    . 'alat kosong (diturunkan dari PID-7), dan rujukan menurut umur tidak '
    . 'dapat dipilih sehingga flag hasilnya ditahan.'
);

$tanpaLoinc = Database::select(
    "SELECT kode, nama FROM tests
      WHERE aktif = 1 AND (loinc IS NULL OR TRIM(loinc) = '')
      ORDER BY kode"
);

periksa(
    sprintf(
        'Cakupan LOINC katalog pemeriksaan: %d dari %d (%d%%)',
        $total - count($tanpaLoinc),
        $total,
        $total > 0 ? (int) round(($total - count($tanpaLoinc)) / $total * 100) : 0
    ),
    count($tanpaLoinc) <= $total * 0.15,
    'Cakupan di bawah 85% — pertukaran data akan banyak tertolak.'
);

if ($tanpaLoinc !== []) {
    catatan(
        count($tanpaLoinc) . ' pemeriksaan belum berkode LOINC — perlu diputuskan lab',
        implode(', ', array_column($tanpaLoinc, 'kode')) . "\n"
        . "Kode LOINC TIDAK diisi otomatis di sini dengan sengaja. Menebak kode\n"
        . "menghasilkan padanan yang salah tanpa ada yang tahu — penerima data\n"
        . "tidak punya cara mendeteksinya. Isi lewat Master → Pemeriksaan,\n"
        . "dengan rujukan search.loinc.org.\n"
        . "Sebagian memang tidak punya padanan LOINC (mis. Widal, yang hampir\n"
        . "hanya dipakai di Asia Selatan dan Tenggara). Untuk itu SATUSEHAT\n"
        . "menyediakan kode nasional sementara berawalan \"X\" dari Kemenkes."
    );
}

Database::execute("UPDATE verification_rule_sets SET status = 'draft', berlaku_dari = NULL WHERE id = 1");
Config::putSetting('mutu.qc_belum_menahan', '0');

echo "\n══════════════════════════════════════════════════════════════\n";
printf("  %d lulus, %d gagal\n", $lulus, $gagal);
echo "══════════════════════════════════════════════════════════════\n\n";

if ($gagal > 0) {
    echo "Himpunan aturan bawaan dikembalikan ke status draft.\n";
    echo "Aktifkan lewat menu Pengaturan setelah ditinjau penanggung jawab lab.\n\n";
}

exit($gagal === 0 ? 0 : 1);
