<?php
declare(strict_types=1);

use App\Core\Router;

/**
 * Tabel rute aplikasi.
 *
 * Middleware yang tersedia:
 *   'auth'            — wajib sudah login
 *   'tamu'            — hanya untuk yang belum login
 *   'izin:modul.aksi' — cek RBAC
 *   'api:scope'       — autentikasi API key dengan cakupan tertentu
 */
return static function (Router $r): void {

    // =================================================================
    // Autentikasi
    // =================================================================
    $r->get('/login', 'App\Controllers\AuthController@form', ['tamu']);
    $r->post('/login', 'App\Controllers\AuthController@masuk', ['tamu']);
    $r->post('/logout', 'App\Controllers\AuthController@keluar', ['auth']);
    $r->get('/profil', 'App\Controllers\AuthController@profil', ['auth']);
    $r->post('/profil/password', 'App\Controllers\AuthController@ubahPassword', ['auth']);

    // =================================================================
    // Dashboard
    // =================================================================
    $r->get('/', 'App\Controllers\DashboardController@index', ['auth', 'izin:dashboard.lihat']);
    $r->get('/dashboard/statistik', 'App\Controllers\DashboardController@statistik', ['auth', 'izin:dashboard.lihat']);

    // =================================================================
    // Pasien
    // =================================================================
    $r->group('/pasien', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\PatientController@index', ['izin:pasien.lihat']);
        $r->get('/baru', 'App\Controllers\PatientController@form', ['izin:pasien.kelola']);
        $r->post('', 'App\Controllers\PatientController@simpan', ['izin:pasien.kelola']);
        $r->get('/cari', 'App\Controllers\PatientController@cari', ['izin:pasien.lihat']);
        $r->get('/{id}', 'App\Controllers\PatientController@detail', ['izin:pasien.lihat']);
        $r->get('/{id}/edit', 'App\Controllers\PatientController@form', ['izin:pasien.kelola']);
        $r->post('/{id}', 'App\Controllers\PatientController@simpan', ['izin:pasien.kelola']);
        $r->get('/{id}/riwayat', 'App\Controllers\PatientController@riwayat', ['izin:hasil.lihat']);
    });

    // =================================================================
    // Order pemeriksaan
    // =================================================================
    $r->group('/order', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\OrderController@index', ['izin:order.lihat']);
        $r->get('/baru', 'App\Controllers\OrderController@form', ['izin:order.buat']);
        $r->post('', 'App\Controllers\OrderController@simpan', ['izin:order.buat']);
        $r->get('/{id}', 'App\Controllers\OrderController@detail', ['izin:order.lihat']);
        $r->post('/{id}/batal', 'App\Controllers\OrderController@batal', ['izin:order.buat']);
        $r->post('/{id}/tambah-item', 'App\Controllers\OrderController@tambahItem', ['izin:order.buat']);
        $r->post('/{id}/hapus-item/{itemId}', 'App\Controllers\OrderController@hapusItem', ['izin:order.buat']);
    });

    // =================================================================
    // Spesimen — pengambilan, penerimaan, label barcode
    // =================================================================
    $r->group('/spesimen', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\SpecimenController@index', ['izin:spesimen.lihat']);
        $r->get('/penerimaan', 'App\Controllers\SpecimenController@penerimaan', ['izin:spesimen.terima']);
        $r->post('/{id}/ambil', 'App\Controllers\SpecimenController@ambil', ['izin:spesimen.ambil']);
        $r->post('/{id}/terima', 'App\Controllers\SpecimenController@terima', ['izin:spesimen.terima']);
        $r->post('/{id}/tolak', 'App\Controllers\SpecimenController@tolak', ['izin:spesimen.tolak']);
        $r->post('/terima-barcode', 'App\Controllers\SpecimenController@terimaBarcode', ['izin:spesimen.terima']);
        $r->get('/{id}/label', 'App\Controllers\SpecimenController@label', ['izin:spesimen.lihat']);
        $r->get('/label-batch', 'App\Controllers\SpecimenController@labelBatch', ['izin:spesimen.lihat']);
    });

    // =================================================================
    // Worklist & entri hasil
    // =================================================================
    $r->get('/worklist', 'App\Controllers\WorklistController@index', ['auth', 'izin:worklist.lihat']);
    $r->post('/worklist/kirim-alat', 'App\Controllers\WorklistController@kirimKeAlat', ['auth', 'izin:alat.kelola']);

    $r->group('/hasil', ['auth'], static function (Router $r): void {
        $r->get('/{orderId}/entri', 'App\Controllers\ResultController@form', ['izin:hasil.entri']);
        $r->post('/{orderId}/simpan', 'App\Controllers\ResultController@simpan', ['izin:hasil.entri']);
        $r->post('/{id}/koreksi', 'App\Controllers\ResultController@koreksi', ['izin:hasil.koreksi']);
        $r->post('/{id}/lapor-kritis', 'App\Controllers\ResultController@laporKritis', ['izin:kritis.lapor']);
        $r->get('/{id}/riwayat', 'App\Controllers\ResultController@riwayat', ['izin:hasil.lihat']);
    });

    // =================================================================
    // Verifikasi & rilis
    // =================================================================
    $r->group('/verifikasi', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\VerificationController@index', ['izin:hasil.lihat']);
        $r->get('/{orderId}', 'App\Controllers\VerificationController@detail', ['izin:hasil.verifikasi']);
        $r->post('/{orderId}', 'App\Controllers\VerificationController@verifikasi', ['izin:hasil.verifikasi']);
        $r->post('/{orderId}/rilis', 'App\Controllers\VerificationController@rilis', ['izin:hasil.rilis']);
        $r->post('/{orderId}/batal', 'App\Controllers\VerificationController@batalVerifikasi', ['izin:hasil.batal_verifikasi']);
    });

    // =================================================================
    // Alat laboratorium
    // =================================================================
    $r->group('/alat', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\InstrumentController@index', ['izin:alat.lihat']);
        $r->get('/baru', 'App\Controllers\InstrumentController@form', ['izin:alat.kelola']);
        $r->post('', 'App\Controllers\InstrumentController@simpan', ['izin:alat.kelola']);
        $r->get('/menggantung', 'App\Controllers\InstrumentController@menggantung', ['izin:alat.lihat']);
        $r->post('/menggantung/{id}/pasang', 'App\Controllers\InstrumentController@pasangMenggantung', ['izin:hasil.entri']);
        $r->post('/menggantung/{id}/buang', 'App\Controllers\InstrumentController@buangMenggantung', ['izin:alat.kelola']);
        $r->get('/log', 'App\Controllers\InstrumentController@log', ['izin:alat.lihat']);
        $r->get('/log/{id}', 'App\Controllers\InstrumentController@logDetail', ['izin:alat.lihat']);
        $r->post('/log/{id}/proses-ulang', 'App\Controllers\InstrumentController@prosesUlang', ['izin:alat.kelola']);
        $r->get('/{id}/edit', 'App\Controllers\InstrumentController@form', ['izin:alat.kelola']);
        $r->post('/{id}', 'App\Controllers\InstrumentController@simpan', ['izin:alat.kelola']);
        $r->get('/{id}/pemetaan', 'App\Controllers\InstrumentController@pemetaan', ['izin:alat.pemetaan']);
        $r->post('/{id}/pemetaan', 'App\Controllers\InstrumentController@simpanPemetaan', ['izin:alat.pemetaan']);
    });

    // =================================================================
    // Master data
    // =================================================================
    $r->group('/master', ['auth'], static function (Router $r): void {
        $r->get('/pemeriksaan', 'App\Controllers\MasterController@tests', ['izin:master.lihat']);
        $r->get('/pemeriksaan/baru', 'App\Controllers\MasterController@formTest', ['izin:master.kelola']);
        $r->post('/pemeriksaan', 'App\Controllers\MasterController@simpanTest', ['izin:master.kelola']);
        $r->get('/pemeriksaan/{id}/edit', 'App\Controllers\MasterController@formTest', ['izin:master.kelola']);
        $r->post('/pemeriksaan/{id}', 'App\Controllers\MasterController@simpanTest', ['izin:master.kelola']);
        $r->get('/pemeriksaan/{id}/rujukan', 'App\Controllers\MasterController@rujukan', ['izin:master.lihat']);
        $r->post('/pemeriksaan/{id}/rujukan', 'App\Controllers\MasterController@simpanRujukan', ['izin:master.kelola']);
        $r->post('/rujukan/{id}/hapus', 'App\Controllers\MasterController@hapusRujukan', ['izin:master.kelola']);
        $r->get('/paket', 'App\Controllers\MasterController@panels', ['izin:master.lihat']);
        $r->get('/paket/baru', 'App\Controllers\MasterController@formPanel', ['izin:master.kelola']);
        $r->post('/paket', 'App\Controllers\MasterController@simpanPanel', ['izin:master.kelola']);
        $r->get('/paket/{id}/edit', 'App\Controllers\MasterController@formPanel', ['izin:master.kelola']);
        $r->post('/paket/{id}', 'App\Controllers\MasterController@simpanPanel', ['izin:master.kelola']);
    });

    // =================================================================
    // Integrasi SIMRS Khanza
    // =================================================================
    $r->group('/integrasi', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\IntegrationController@index', ['izin:integrasi.lihat']);
        $r->post('/uji-koneksi', 'App\Controllers\IntegrationController@ujiKoneksi', ['izin:integrasi.lihat']);
        $r->post('/tarik-order', 'App\Controllers\IntegrationController@tarikOrder', ['izin:integrasi.kelola']);
        $r->post('/sinkron-bridging', 'App\Controllers\IntegrationController@sinkronBridging', ['izin:integrasi.kelola']);
        $r->post('/impor-template', 'App\Controllers\IntegrationController@imporTemplate', ['izin:integrasi.kelola']);
        $r->get('/pemetaan', 'App\Controllers\IntegrationController@pemetaan', ['izin:integrasi.lihat']);
        $r->post('/pemetaan', 'App\Controllers\IntegrationController@simpanPemetaan', ['izin:integrasi.kelola']);
        $r->get('/log', 'App\Controllers\IntegrationController@log', ['izin:integrasi.lihat']);
        $r->post('/log/{id}/kirim-ulang', 'App\Controllers\IntegrationController@kirimUlang', ['izin:integrasi.kelola']);
        $r->post('/proses-antrian', 'App\Controllers\IntegrationController@prosesAntrian', ['izin:integrasi.kelola']);
    });

    // =================================================================
    // Laporan
    // =================================================================
    $r->group('/laporan', ['auth'], static function (Router $r): void {
        $r->get('', 'App\Controllers\ReportController@index', ['izin:laporan.lihat']);
        $r->get('/hasil/{orderId}', 'App\Controllers\ReportController@lembarHasil', ['izin:laporan.cetak']);
        $r->get('/tat', 'App\Controllers\ReportController@tat', ['izin:laporan.lihat']);
        $r->get('/produktivitas', 'App\Controllers\ReportController@produktivitas', ['izin:laporan.lihat']);
        $r->get('/nilai-kritis', 'App\Controllers\ReportController@nilaiKritis', ['izin:laporan.lihat']);
        $r->get('/ekspor', 'App\Controllers\ReportController@ekspor', ['izin:laporan.ekspor']);
    });

    // =================================================================
    // Kontrol mutu
    // =================================================================
    $r->get('/qc', 'App\Controllers\QcController@index', ['auth', 'izin:qc.lihat']);
    $r->get('/qc/lot/{id}', 'App\Controllers\QcController@lot', ['auth', 'izin:qc.lihat']);
    $r->post('/qc/lot/{id}/entri', 'App\Controllers\QcController@entri', ['auth', 'izin:qc.entri']);
    $r->get('/qc/lot-baru', 'App\Controllers\QcController@formLot', ['auth', 'izin:qc.kelola']);
    $r->post('/qc/lot', 'App\Controllers\QcController@simpanLot', ['auth', 'izin:qc.kelola']);

    // =================================================================
    // Nilai kritis — antrean pelaporan berjangka waktu (CLSI GP47)
    //
    // Sengaja diberi alamat sendiri, bukan diselipkan sebagai tab pada
    // halaman hasil: nilai kritis harus dapat dibuka langsung dan terlihat
    // tanpa petugas perlu tahu lebih dulu order mana yang memuatnya.
    // =================================================================
    $r->get('/nilai-kritis', 'App\Controllers\CriticalController@index', ['auth', 'izin:hasil.lihat']);
    $r->post('/nilai-kritis/{id}/lapor', 'App\Controllers\CriticalController@lapor', ['auth', 'izin:kritis.lapor']);

    // =================================================================
    // Pengguna & pengaturan (admin)
    // =================================================================
    $r->group('/pengguna', ['auth', 'izin:pengguna.kelola'], static function (Router $r): void {
        $r->get('', 'App\Controllers\UserController@index');
        $r->get('/baru', 'App\Controllers\UserController@form');
        $r->post('', 'App\Controllers\UserController@simpan');
        $r->get('/{id}/edit', 'App\Controllers\UserController@form');
        $r->post('/{id}', 'App\Controllers\UserController@simpan');
        $r->post('/{id}/reset-password', 'App\Controllers\UserController@resetPassword');
    });

    $r->group('/pengaturan', ['auth', 'izin:pengaturan.kelola'], static function (Router $r): void {
        $r->get('', 'App\Controllers\SettingController@index');
        $r->post('', 'App\Controllers\SettingController@simpan');
        $r->get('/api', 'App\Controllers\SettingController@apiClients');
        $r->post('/api', 'App\Controllers\SettingController@simpanApiClient');
        $r->post('/api/{id}/batas-ip', 'App\Controllers\SettingController@simpanBatasIp');
        $r->post('/api/{id}/hapus', 'App\Controllers\SettingController@hapusApiClient');
        $r->get('/audit', 'App\Controllers\SettingController@audit');
    });

    // Tentang Aplikasi — cukup login, tidak perlu izin pengaturan.
    $r->get('/tentang', 'App\Controllers\AboutController@index', ['auth']);

    // =================================================================
    // API v1 — dipakai middleware alat & konektor Khanza
    // =================================================================
    $r->get('/api/v1/ping', 'App\Api\V1\SystemApi@ping');

    // --- Middleware alat (scope: instrument) ---
    $r->get('/api/v1/instruments', 'App\Api\V1\InstrumentApi@daftar', ['api:instrument']);
    $r->post('/api/v1/instruments/heartbeat', 'App\Api\V1\InstrumentApi@heartbeat', ['api:instrument']);
    $r->post('/api/v1/instruments/results', 'App\Api\V1\InstrumentApi@hasil', ['api:instrument']);
    $r->post('/api/v1/instruments/messages', 'App\Api\V1\InstrumentApi@pesanMentah', ['api:instrument']);
    $r->get('/api/v1/worklist/{sampleId}', 'App\Api\V1\InstrumentApi@worklistSampel', ['api:instrument']);
    $r->get('/api/v1/instruments/{kode}/worklist', 'App\Api\V1\InstrumentApi@worklistAlat', ['api:instrument']);

    // --- Konektor Khanza (scope: khanza) ---
    $r->post('/api/v1/khanza/orders', 'App\Api\V1\KhanzaApi@terimaOrder', ['api:khanza']);
    $r->post('/api/v1/khanza/orders/batal', 'App\Api\V1\KhanzaApi@batalkanOrder', ['api:khanza']);
    $r->get('/api/v1/khanza/hasil', 'App\Api\V1\KhanzaApi@hasilSiapKirim', ['api:khanza']);
    $r->post('/api/v1/khanza/hasil/ack', 'App\Api\V1\KhanzaApi@ackHasil', ['api:khanza']);
    $r->get('/api/v1/khanza/status', 'App\Api\V1\KhanzaApi@status', ['api:khanza']);
};
