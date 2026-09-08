<?php
declare(strict_types=1);

/**
 * IDENTITAS APLIKASI — BERTANDA TANGAN.
 *
 * Jangan menyunting berkas ini dengan tangan. Nilai tanda_tangan di
 * bawah dihitung dari seluruh isi data dan berkas; satu huruf yang
 * berubah membuatnya tidak cocok, aplikasi terkunci, dan seluruh
 * kredensial API berhenti melayani.
 *
 * Tanda tangan RSA-SHA256. Hanya pemegang kunci privat yang dapat
 * membuatnya; kunci itu tidak tersimpan di server ini.
 *
 * Diterbitkan: 2026-09-08 11:30:36 WIB
 */

return [
    'data' => [
        'aplikasi'      => 'LIS Khanza',
        'versi'         => '1.0.0',
        'instansi'      => 'Khanza',
        'unit'          => 'Instalasi Laboratorium',
        'pengembang'    => 'Rendra Yusrimaelani',
        'kontak'        => '',
        'lisensi'       => 'Lisensi tunggal untuk instansi tersebut di atas',
        'no_lisensi'    => 'LIS-HANAU-001',
        'diterbitkan'   => '2026-09-07',
        'berlaku'       => 'Tanpa batas waktu',
        'donasi_bank'   => 'Bank Mandiri',
        'donasi_rekening' => '1590014271265',
        'donasi_atas_nama' => 'Rendra Yusrimaelani',
        'donasi_qris'   => '',
        'donasi_catatan' => 'Terima kasih atas dukungannya.',
    ],
    'berkas' => [
        'app/Views/about.php'            => '9ad0517965289e292f36249b11668ac52489167e7e0d2189a48f60e227177d56',
        'app/Core/Lisensi.php'           => 'db4ccba4f3d1bcd0e23bad4dd66946a3a8c61bdd4660096eeb83c75a5abe9c2d',
    ],
    'tanda_tangan' => 'Dg0gjUSWM8IYsv7ByBZQNyF9QVTA20DZIktAAELKn9PmtyVOinWR5UsIWEIzJ2ZJ6QejHZHU7HoRod/u9F1gEtuJ28hogSnEJH+KYPf42CTt/SyZ6J/WlVSM8bp2gZ6/SL+KkGtd1sVMSwYUqXln9UiSNzbgs0J99V5HFfc/Y/KmLLUcH/UQveAH5SyY0cFEpbd0pdLRym5H5JbPDs0qNdL5RIsrVIWHVC9TDCUQEA4YyPteZq+nvOBjKXhM40BzaM22Xx4EYL5/znhqHdQQuMGZgjwuGwY0Bz/2h1UqhwQVjjo4xm4CeLpMBqO2PTHCr646xGy+csj1FlwuLbW7iyGdkTdjCdPRgA8hOFp/HrRUJIHvmjCJG6p5Z2P2RIy61Z6VzrrTzl9KC28c0s54pIGRH1GWj/vf0UjLEGsAVi4oULphOwIzZSE9wyRxrLEo3WCbfjIDTD3WOO9kH/ziBS3TOAr0Xs4aZ4ClBIcnv7bWhiZ7tsNgPuY+DK6BcN7e',
];
