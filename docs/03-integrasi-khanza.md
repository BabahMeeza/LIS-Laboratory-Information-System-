# Integrasi SIMRS Khanza

## Cara kerja

LIS **tidak pernah** membuka koneksi database ke SIMRS Khanza. Seluruh
pertukaran berjalan lewat HTTP dengan API key, melalui modul konektor yang
dipasang di server Khanza.

```
┌───────────────────────┐         REST + API key        ┌─────────────┐
│  SIMRS Khanza         │                               │     LIS     │
│                       │                               │             │
│  ┌─────────────────┐  │  ①  permintaan_lab ──────────►│  order      │
│  │ khanza-connector│  │                               │             │
│  └────────┬────────┘  │  ②  detail_hasil_lab ◄────────│  hasil      │
│           │ SQL       │                               │  terverif.  │
│  ┌────────▼────────┐  │  ③  template_laboratorium ───►│  pemetaan   │
│  │sik_bridging_lab │  │                               │             │
│  └─────────────────┘  │                               └─────────────┘
│  ┌─────────────────┐  │
│  │ sik (hanya baca)│  │
│  └─────────────────┘  │
└───────────────────────┘
```

Konektor hanya menulis ke database bridging resmi Khanza
(`sik_bridging_lab`). Database utama `sik` hanya **dibaca**, itu pun
opsional dan sebatas master pemeriksaan.

### Tabel yang dipakai

| Tabel | Arah | Isi |
|---|---|---|
| `permintaan_lab` | Khanza → LIS | Header permintaan: pasien, ruang, dokter, diagnosa |
| `detail_permintaan_lab` | Khanza → LIS | Pemeriksaan yang diminta (`kd_jenis_prw` + `id_template`) |
| `detail_hasil_lab` | LIS → Khanza | Hasil terverifikasi |
| `template_laboratorium` | dibaca | Master parameter, untuk pemetaan |
| `jns_perawatan_lab` | dibaca | Nama jenis pemeriksaan |

Aplikasi desktop Khanza kemudian menarik isi `detail_hasil_lab` ke
`periksa_lab` / `detail_periksa_lab` lewat menu bridging laboratorium.

---

## Pemasangan konektor

### 1. Salin folder

Salin `khanza-connector/` ke `htdocs` **server SIMRS Khanza**:

```
C:\xampp\htdocs\khanza-connector\
```

### 2. Buat tabel bridging

```bash
mysql -u root -p < khanza-connector/install.sql
```

Skrip memakai `CREATE TABLE IF NOT EXISTS`, jadi aman dijalankan bila
database `sik_bridging_lab` sudah ada — data yang ada tidak tersentuh.

### 3. Buat pengguna database terbatas

Jangan memakai `root`. Buka komentar bagian akhir `install.sql`:

```sql
CREATE USER 'lis_konektor'@'localhost' IDENTIFIED BY 'KATA_SANDI_KUAT';
GRANT SELECT, INSERT, UPDATE, DELETE ON `sik_bridging_lab`.* TO 'lis_konektor'@'localhost';
GRANT SELECT ON `sik`.`template_laboratorium` TO 'lis_konektor'@'localhost';
GRANT SELECT ON `sik`.`jns_perawatan_lab`     TO 'lis_konektor'@'localhost';
FLUSH PRIVILEGES;
```

Dengan hak seperti ini, konektor **tidak mungkin** mengubah data medis di
database utama Khanza sekalipun ada kekeliruan atau penyalahgunaan.

### 4. Konfigurasi

```bash
cp config.example.php config.php
```

Hasilkan kunci acak:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

Isi `config.php`:

```php
'db_bridging' => [
    'name' => 'sik_bridging_lab',
    'user' => 'lis_konektor',
    'pass' => 'KATA_SANDI_KUAT',
],
'api_key'    => 'hasil_acak_pertama',
'api_secret' => 'hasil_acak_kedua',
'wajib_signature' => true,
'ip_whitelist'    => ['192.168.1.20'],   // alamat server LIS
'lis' => [
    'base_url'   => 'http://192.168.1.20/LIS/public',
    'api_key'    => 'API key dari LIS',
    'api_secret' => 'secret dari LIS',
],
```

`ip_whitelist` sangat dianjurkan: konektor dapat menulis data medis, jadi
membatasinya ke satu alamat server LIS menutup sebagian besar risiko.

### 5. Uji dari server Khanza

```bash
curl -H "X-API-Key: hasil_acak_pertama" \
     http://localhost/khanza-connector/api/ping.php
```

Jawaban yang diharapkan:

```json
{"sukses":true,"pesan":"Konektor siap.",
 "data":{"tabel":{"permintaan_lab":true,"detail_permintaan_lab":true,
                  "detail_hasil_lab":true},"siap":true,"order_menunggu":0}}
```

---

## Konfigurasi di sisi LIS

**Pengaturan Sistem**, grup `khanza`:

| Kunci | Isi |
|---|---|
| `khanza.aktif` | centang |
| `khanza.base_url` | `http://192.168.1.10/khanza-connector` |
| `khanza.api_key` | sama dengan `api_key` konektor |
| `khanza.api_secret` | sama dengan `api_secret` konektor |
| `khanza.mode_order` | `push` atau `pull` (lihat bawah) |
| `khanza.kirim_hasil_saat` | `verifikasi` atau `rilis` |
| `khanza.auto_buat_pasien` | centang |

Lalu **Integrasi → Uji Koneksi**. Jawabannya menyebutkan versi konektor
dan nama database bila berhasil.

---

## Memetakan pemeriksaan

Ini langkah yang paling menentukan berhasil-tidaknya integrasi.

Khanza mengenali pemeriksaan lewat pasangan `kd_jenis_prw` + `id_template`.
LIS memakai kode sendiri. Keduanya harus dipasangkan.

1. **Integrasi → Impor Master Pemeriksaan** — menarik seluruh
   `template_laboratorium` dari Khanza
2. Sistem mencoba memetakan otomatis berdasarkan kemiripan nama setelah
   normalisasi (huruf kecil, tanda baca dibuang). Pada uji, "Hemoglobin",
   "Leukosit", "Trombosit", dan "Glukosa Darah Sewaktu" terpetakan
   seluruhnya tanpa campur tangan.
3. **Integrasi → Pemetaan Pemeriksaan** — lengkapi sisanya. Baris yang
   belum terpetakan ditandai merah di atas.
4. Simpan. Pemetaan ditulis balik ke master pemeriksaan LIS sehingga
   dipakai untuk kedua arah.

Pemeriksaan Khanza yang tidak dipetakan akan **menolak order** yang
memuatnya, dengan pesan yang menyebutkan kode mana yang belum dikenal.
Order itu tidak ditandai terambil, sehingga dapat dikirim ulang setelah
pemetaan dilengkapi.

---

## Mode order: push atau pull

### Push — Khanza mengirim ke LIS (dianjurkan)

Jalankan `push_orders.php` berkala di server Khanza:

```
# Linux/macOS
* * * * * /usr/bin/php /var/www/khanza-connector/push_orders.php

# Windows Task Scheduler
C:\xampp\php\php.exe C:\xampp\htdocs\khanza-connector\push_orders.php
```

Keluarannya jelas per order:

```
== Pendorong Order Khanza → LIS ==
Ditemukan: 1 permintaan
  ✓ LB0002         → LIS-260902-0003 (No. Lab 2609020003)
Selesai: 1 terkirim, 0 ditolak, 12 ms
```

Order **hanya** ditandai terambil bila LIS benar-benar menerimanya.

### Pull — LIS menarik dari Khanza

Atur `khanza.mode_order` = `pull`, lalu jalankan di server LIS:

```
*/2 * * * * /usr/bin/php /path/ke/LIS/bin/sync_khanza.php
```

Dapat juga ditarik manual kapan saja lewat tombol **Tarik Order Baru**.

Pilih **push** bila server Khanza dapat menghubungi LIS; pilih **pull**
bila arah sebaliknya yang memungkinkan (misalnya LIS berada di jaringan
yang boleh keluar, tetapi tidak boleh menerima koneksi masuk).

---

## Jalur balik hasil

Hasil dikirim otomatis saat verifikasi atau rilis, sesuai pengaturan
`khanza.kirim_hasil_saat`.

Bila SIMRS sedang mati, pengiriman **tidak hilang**: entri masuk
`khanza_sync_log` berstatus `antri` dengan backoff eksponensial
(5, 10, 20, 40 menit… maksimum 8 jam, 10 percobaan). Antrian diproses oleh:

```
*/2 * * * * /usr/bin/php /path/ke/LIS/bin/sync_khanza.php
```

Skrip yang sama juga menyapu hasil terverifikasi yang belum pernah masuk
antrian — misalnya bila LIS mati tepat setelah verifikasi.

Pengiriman bersifat **idempoten**: mengirim ulang menghapus hasil lama
untuk `noorder` tersebut lalu menulis ulang, bukan menggandakan. Ini yang
membuat koreksi hasil ikut terbawa ke Khanza dengan benar.

Pantau semuanya di **Integrasi → Log**, lengkap dengan tombol kirim ulang
per entri.

---

## Bentuk data

### Order masuk

```json
{
  "noorder": "LB0001",
  "no_rawat": "2026/09/02/000045",
  "no_rkm_medis": "000456",
  "nm_pasien": "SITI AMINAH",
  "jk": "P",
  "tgl_lahir": "1985-07-20",
  "tgl_permintaan": "2026-09-02",
  "jam_permintaan": "13:40:00",
  "dokter_perujuk": "dr. Rina Sp.PD",
  "status": "ranap",
  "nama_ruang": "Melati",
  "nama_carabayar": "BPJS",
  "informasi_tambahan": "CITO",
  "diagnosa_klinis": "Demam tifoid?",
  "detail": [
    { "kd_jenis_prw": "LK001", "id_template": 101 },
    { "kd_jenis_prw": "LK002", "id_template": 201 }
  ]
}
```

`informasi_tambahan` yang memuat kata "CITO" atau "urgent" otomatis
menjadikan order berprioritas cito.

### Hasil keluar

```json
{
  "noorder": "LB0001",
  "no_lab": "2609020002",
  "tgl_hasil": "2026-09-02",
  "jam_hasil": "13:52:00",
  "petugas": "dr. Andi Sp.PK",
  "detail": [
    { "kd_jenis_prw": "LK001", "id_template": 101,
      "nilai": "10.2", "nilai_rujukan": "11.7 - 15.5", "keterangan": "L" }
  ]
}
```

`nilai_rujukan` adalah rujukan yang **berlaku untuk pasien tersebut** —
pada contoh di atas rujukan perempuan dewasa, bukan rujukan umum.

---

## Menyesuaikan untuk versi Khanza yang berbeda

Nama kolom Khanza berbeda antar versi. Konektor sudah menanganinya secara
defensif: kolom dideteksi lewat `information_schema` sebelum dipakai,
sehingga kolom yang tidak ada dilewati, bukan membuat kueri gagal.

Bila instalasi Anda memakai struktur yang cukup berbeda, cukup sunting:

- `khanza-connector/api/orders.php` — daftar kolom yang dibaca
- `khanza-connector/api/results.php` — kolom tujuan penulisan hasil
- `khanza-connector/api/templates.php` — kolom master pemeriksaan

Ketiganya berkas PHP polos tanpa dependensi. Sisi LIS tidak perlu diubah.

---

## Pemecahan masalah

| Gejala | Sebab dan penanganan |
|---|---|
| Uji koneksi gagal | Periksa URL konektor dari server LIS dengan `curl`. Cek firewall dan `ip_whitelist`. |
| `API key tidak dikenali` | `khanza.api_key` di LIS harus sama persis dengan `api_key` di `config.php` konektor. |
| `Tanda tangan tidak sah` | `api_secret` kedua sisi berbeda, atau `wajib_signature` aktif sementara secret LIS kosong. |
| Order ditolak "tidak ada pemeriksaan yang cocok" | Pemetaan belum lengkap. Buka Integrasi → Pemetaan Pemeriksaan; pesan menyebutkan kode yang belum dikenal. |
| Hasil tidak muncul di Khanza | Cek `detail_hasil_lab` — bila terisi, berarti LIS sudah mengirim dan yang tersisa adalah menarik data di aplikasi desktop Khanza. |
| Order terkirim berulang | `tandai_terambil` bernilai `false`, atau kolom `status_ambil` tidak ada pada tabel. |
| `Illegal mix of collations` | Pastikan `db.collation` pada `config/config.php` LIS sama dengan collation tabel (bawaan `utf8mb4_unicode_ci`). |
