<?php
/**
 * Konektor SIMRS Khanza ↔ LIS — konfigurasi.
 *
 * Salin menjadi config.php lalu sesuaikan.
 * Berkas ini dipasang di web server SIMRS Khanza, BUKAN di server LIS.
 */

return [

    // -----------------------------------------------------------------
    // Database bridging Khanza
    //
    // Khanza menyediakan database bridging resmi bernama sik_bridging_lab
    // yang berisi tabel permintaan_lab, detail_permintaan_lab, dan
    // detail_hasil_lab. Konektor hanya menyentuh database ini — database
    // utama `sik` tidak diubah sama sekali.
    // -----------------------------------------------------------------
    'db_bridging' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'sik_bridging_lab',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8',   // instalasi Khanza umumnya latin1/utf8, bukan utf8mb4
    ],

    // -----------------------------------------------------------------
    // Database utama Khanza (opsional, hanya dibaca)
    //
    // Dipakai untuk mengekspor master pemeriksaan (template_laboratorium
    // dan jns_perawatan_lab) agar dapat dipetakan di LIS. Bila dibiarkan
    // kosong, endpoint templates.php akan menonaktifkan diri.
    // -----------------------------------------------------------------
    'db_sik' => [
        'aktif'   => true,
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'sikori',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8',
    ],

    // -----------------------------------------------------------------
    // Keamanan
    //
    // api_key dan api_secret di bawah adalah kredensial yang harus
    // DIKIRIMKAN OLEH LIS saat memanggil konektor ini. Isi nilai yang
    // sama pada Pengaturan > Integrasi di LIS.
    //
    // Hasilkan nilai acak dengan:
    //   php -r "echo bin2hex(random_bytes(24));"
    // -----------------------------------------------------------------
    'api_key'    => 'ubah_dengan_kunci_acak',
    'api_secret' => 'ubah_dengan_secret_acak',

    // Wajibkan tanda tangan HMAC pada setiap permintaan yang membawa body.
    // Sangat dianjurkan bila LIS berada di server berbeda.
    'wajib_signature' => false,

    // Batasi pemanggil ke alamat IP server LIS. Kosongkan untuk semua.
    'ip_whitelist' => [
        // '192.168.1.20',
        // '192.168.1.0/24',
    ],

    // -----------------------------------------------------------------
    // Alamat LIS — dipakai oleh push_orders.php (mode push)
    // -----------------------------------------------------------------
    'lis' => [
        'base_url'   => 'http://192.168.1.20/LIS/public',
        'api_key'    => 'ubah_dengan_api_key_lis',
        'api_secret' => 'ubah_dengan_secret_lis',
        'timeout'    => 20,
        'verify_ssl' => true,
    ],

    // -----------------------------------------------------------------
    // Perilaku
    // -----------------------------------------------------------------

    // Setelah order diambil LIS, tandai status_ambil = '1' agar tidak
    // terkirim dua kali. Matikan hanya saat pengujian.
    'tandai_terambil' => true,

    // Jumlah order maksimum per sekali ambil/kirim.
    'batas_order' => 50,

    // Tulis log ke berkas (kosongkan untuk mematikan).
    'log_file' => __DIR__ . '/connector.log',
];
