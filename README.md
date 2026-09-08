# LIS — Laboratory Information System

Sistem informasi laboratorium klinik yang terhubung langsung ke alat
(analyzer) dan terintegrasi dua arah dengan **SIMRS Khanza**.

Dibangun untuk berjalan di atas XAMPP standar: PHP tanpa Composer,
MySQL/MariaDB, ditambah satu proses Node.js untuk menjaga koneksi alat.

---

## Yang dikerjakan sistem ini

**Alur kerja laboratorium**

- Pendaftaran order, otomatis membuat satu spesimen per jenis tabung
  beserta barcode Code 128 yang dapat langsung dipindai analyzer
- Penerimaan sampel berbasis pemindaian barcode, termasuk pencatatan
  penolakan spesimen sebagai indikator mutu pra-analitik
- Worklist per kategori/alat, entri hasil manual, dan verifikasi berjenjang
  oleh dokter penanggung jawab
- Lembar hasil siap cetak, register nilai kritis, analisis TAT, dan
  kontrol mutu internal dengan grafik Levey-Jennings serta aturan Westgard

**Koneksi alat**

- ASTM E1381/E1394 (TCP dan serial), HL7 v2.x di atas MLLP, dan protokol
  teks polos
- Dua arah: analyzer dapat menanyakan daftar pemeriksaan (host query) dan
  LIS menjawab dengan worklist untuk barcode tersebut
- Pemetaan kode parameter alat → pemeriksaan LIS yang terisi sendiri saat
  alat pertama kali mengirim kode baru
- Hasil ditulis ke disk sebelum dikirim, sehingga tetap selamat bila LIS
  atau jaringan sedang mati

**Integrasi SIMRS Khanza**

- Lewat konektor REST yang dipasang di server Khanza; LIS tidak pernah
  menyentuh database `sik` secara langsung
- Memakai database bridging resmi Khanza (`sik_bridging_lab`)
- Dua arah: permintaan lab masuk ke LIS, hasil terverifikasi kembali ke
  `detail_hasil_lab`, dengan antrian kirim ulang bila SIMRS sedang mati

---

## Keamanan klinis yang ditegakkan sistem

Beberapa perilaku sengaja dibuat kaku karena menyangkut keselamatan pasien:

| Perilaku | Alasan |
|---|---|
| Flag hasil **dihitung ulang oleh LIS**, bukan diambil dari alat | Analyzer memakai rentang bawaannya sendiri. Contoh nyata dari uji: alat menandai Hb 6,4 g/dL hanya `L`, LIS menandainya `LL` (kritis) sesuai ambang laboratorium. |
| Nilai rujukan dipilih menurut **jenis kelamin dan umur pasien** | Rujukan Hb laki-laki 13,2–17,3 berbeda dari perempuan 11,7–15,5 g/dL; bayi baru lahir berbeda lagi. |
| Verifikasi **ditahan** selama nilai kritis belum tercatat pelaporannya ke DPJP | Nilai kritis harus sampai ke klinisi, bukan sekadar tercetak. |
| Hasil yang sudah diverifikasi **tidak dapat ditimpa** alat maupun entri manual | Perubahan hanya lewat jalur koreksi yang meninggalkan jejak permanen. |
| Parameter alat yang belum dipetakan **dikarantina**, tidak dibuang | Hasil yang tidak dikenali tidak boleh hilang diam-diam. |
| Autovalidasi hanya berjalan bila ada **penanggung jawab yang ditunjuk** | Hasil tanpa nama penanggung jawab tidak layak diterbitkan. |
| Seluruh perubahan hasil tercatat pada jejak audit | Persyaratan telusur akreditasi laboratorium. |

---

## Susunan folder

```
LIS/
├── public/              Document root — hanya folder ini yang boleh terekspos
├── app/                 Inti aplikasi (Core, Controllers, Services, Api, Views)
├── config/              Konfigurasi (config.php tidak masuk version control)
├── database/            Skema dan data master
├── bin/                 Perkakas baris perintah (install, selftest, sync)
├── middleware/          Gateway Node.js untuk koneksi alat + simulator
├── khanza-connector/    Modul yang dipasang di server SIMRS Khanza
├── storage/             Log, berkas sementara, laporan
└── docs/                Dokumentasi
```

---

## Pemasangan singkat

```bash
# 1. LIS
php bin/install.php          # memeriksa lingkungan, membuat config, mengisi database
php bin/selftest.php         # memverifikasi hasil pemasangan

# 2. Middleware alat
cd middleware
cp .env.example .env         # isi LIS_BASE_URL dan LIS_API_KEY
npm start

# 3. Uji tanpa alat sungguhan (terminal lain)
npm run simulate:astm -- --query
npm run simulate:hl7
```

Panduan lengkap: [INSTALL.md](INSTALL.md)

---

## Dokumentasi

| Berkas | Isi |
|---|---|
| [INSTALL.md](INSTALL.md) | Pemasangan langkah demi langkah di XAMPP |
| [docs/01-arsitektur.md](docs/01-arsitektur.md) | Gambaran komponen dan alasan rancangannya |
| [docs/02-alur-kerja.md](docs/02-alur-kerja.md) | Alur order sampai lembar hasil, beserta status |
| [docs/03-integrasi-khanza.md](docs/03-integrasi-khanza.md) | Pemasangan konektor dan pemetaan pemeriksaan |
| [docs/04-protokol-alat.md](docs/04-protokol-alat.md) | ASTM, HL7, serial, dan cara menyambungkan analyzer |
| [docs/05-api.md](docs/05-api.md) | Rujukan endpoint REST |
| [docs/06-pemecahan-masalah.md](docs/06-pemecahan-masalah.md) | Gejala, sebab, dan penanganannya |

---

## Peran pengguna

| Peran | Kewenangan |
|---|---|
| `admin` | Seluruh modul, termasuk pengaturan, kredensial API, dan jejak audit |
| `manajer` | Membaca dashboard, order, hasil, dan laporan |
| `verifikator` | Memverifikasi, merilis, dan mengoreksi hasil; mencatat nilai kritis |
| `analis` | Entri hasil, worklist, penerimaan spesimen, konfigurasi dan pemetaan alat |
| `sampling` | Pendaftaran order, pengambilan dan penerimaan spesimen, cetak label |
| `viewer` | Hanya membaca |

---

## Kebutuhan sistem

- PHP 8.0+ dengan `pdo_mysql`, `mbstring`, `json`, `openssl` (`curl` dianjurkan)
- MySQL 5.7+ atau MariaDB 10.4+
- Node.js 18+ — hanya untuk middleware alat
- Paket `serialport` — hanya bila memakai alat RS232

---

## Uji otomatis

```bash
php bin/selftest.php            # 30 pemeriksaan pemasangan dan logika klinis
cd middleware && npm test       # 33 uji parser ASTM dan HL7
```

---

## Lisensi

MIT.
