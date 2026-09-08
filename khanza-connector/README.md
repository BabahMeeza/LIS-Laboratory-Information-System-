# Konektor SIMRS Khanza ↔ LIS

Modul ini **dipasang di server SIMRS Khanza**, bukan di server LIS.

Fungsinya menjadi satu-satunya titik sentuh terhadap database Khanza,
sehingga LIS tidak perlu memegang kredensial database SIMRS sama sekali.

---

## Isi

```
khanza-connector/
├── api/
│   ├── ping.php          kesehatan konektor + diagnosa pemasangan
│   ├── orders.php        ambil permintaan lab, tandai sudah diambil
│   ├── results.php       tulis hasil ke detail_hasil_lab
│   └── templates.php     ekspor master pemeriksaan untuk pemetaan
├── lib/bootstrap.php     database, autentikasi, klien HTTP
├── push_orders.php       pendorong order ke LIS (untuk cron)
├── install.sql           tabel bridging
├── config.example.php    contoh konfigurasi
└── .htaccess             pengaman folder
```

Ditulis sebagai PHP polos tanpa framework dan tanpa Composer, agar dapat
dijatuhkan begitu saja ke `htdocs` pada server rumah sakit yang sering
memakai XAMPP lama dan tidak punya akses internet.

---

## Pemasangan

```bash
# 1. Salin folder ini ke htdocs server Khanza
#    C:\xampp\htdocs\khanza-connector\

# 2. Buat tabel bridging (aman bila sudah ada)
mysql -u root -p < install.sql

# 3. Konfigurasi
cp config.example.php config.php
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"   # untuk api_key
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"   # untuk api_secret

# 4. Uji
curl -H "X-API-Key: KUNCI_ANDA" http://localhost/khanza-connector/api/ping.php
```

Jawaban yang diharapkan:

```json
{"sukses":true,"pesan":"Konektor siap.",
 "data":{"siap":true,"order_menunggu":0,
         "tabel":{"permintaan_lab":true,"detail_permintaan_lab":true,
                  "detail_hasil_lab":true}}}
```

---

## Keamanan

Konektor dapat menulis data medis. Terapkan seluruhnya:

1. **Pengguna database terbatas.** Jangan `root`. Bagian akhir
   `install.sql` menyediakan perintahnya — konektor hanya perlu penuh pada
   `sik_bridging_lab`, dan **hanya baca** pada dua tabel master `sik`.

2. **Batasi IP.** Isi `ip_whitelist` dengan alamat server LIS:

   ```php
   'ip_whitelist' => ['192.168.1.20'],
   ```

3. **Wajibkan tanda tangan.**

   ```php
   'wajib_signature' => true,
   ```

4. **Pastikan `.htaccess` berlaku.** `config.php` memuat kata sandi
   database. Uji dari peramban:

   ```
   http://server-khanza/khanza-connector/config.php
   ```

   Harus menghasilkan **403**, bukan isi berkas. Bila isinya terbaca,
   `AllowOverride All` belum aktif — jangan lanjutkan sebelum ini beres.

---

## Mode push

Jalankan `push_orders.php` berkala agar order langsung mengalir ke LIS:

```
# Linux/macOS — setiap menit
* * * * * /usr/bin/php /var/www/khanza-connector/push_orders.php

# Windows Task Scheduler
Program : C:\xampp\php\php.exe
Argumen : C:\xampp\htdocs\khanza-connector\push_orders.php
```

Keluaran:

```
== Pendorong Order Khanza → LIS ==
Waktu   : 2026-09-02 13:53:28
LIS     : http://192.168.1.20/LIS/public
Ditemukan: 1 permintaan
  ✓ LB0002         → LIS-260902-0003 (No. Lab 2609020003)
Selesai: 1 terkirim, 0 ditolak, 12 ms
```

Order **hanya** ditandai terambil bila LIS benar-benar menerimanya, jadi
kegagalan jaringan tidak pernah menyebabkan order hilang.

Kode keluar: `0` semua terkirim, `1` gagal menghubungi LIS, `2` sebagian
ditolak (biasanya karena pemetaan pemeriksaan belum lengkap).

---

## Menyesuaikan untuk versi Khanza berbeda

Nama kolom berbeda antar versi Khanza. Konektor sudah defensif: kolom
dideteksi lewat `information_schema` sebelum dipakai, sehingga kolom yang
tidak ada dilewati alih-alih membuat kueri gagal.

Bila struktur instalasi Anda cukup berbeda, sunting:

| Berkas | Yang perlu disesuaikan |
|---|---|
| `api/orders.php` | Daftar kolom `permintaan_lab` yang dibaca |
| `api/results.php` | Kolom tujuan pada `detail_hasil_lab` |
| `api/templates.php` | Kolom `template_laboratorium` |

Sisi LIS tidak perlu diubah sama sekali.

---

## Log

Diatur lewat `log_file` pada `config.php`:

```php
'log_file' => __DIR__ . '/connector.log',
```

Berisi setiap permintaan yang ditolak, order yang dikirim, dan hasil yang
disimpan. Kosongkan nilainya untuk mematikan.

---

Dokumentasi lengkap: [../docs/03-integrasi-khanza.md](../docs/03-integrasi-khanza.md)
