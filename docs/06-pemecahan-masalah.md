# Pemecahan Masalah

## Langkah pertama, selalu

```bash
php bin/selftest.php
```

Memeriksa 30 hal: lingkungan, tabel database, keamanan, logika klinis, dan
kesiapan integrasi. Setiap kegagalan menyebutkan langkah perbaikannya.

Untuk masalah alat:

```bash
curl http://127.0.0.1:9701/health          # status middleware
tail -f middleware/logs/gateway-*.log      # log middleware
tail -f storage/logs/lis-*.log             # log LIS
```

---

## Aplikasi web

### Semua halaman 404 kecuali halaman depan

`mod_rewrite` belum aktif, atau `AllowOverride` bukan `All`.

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

```apache
<Directory "C:/xampp/htdocs">
    AllowOverride All
</Directory>
```

Restart Apache setelah mengubah `httpd.conf`.

### Setiap URL menampilkan dashboard XAMPP, bukan LIS

Ciri khasnya: status **200** (bukan 404), tetapi isinya halaman selamat
datang XAMPP — termasuk untuk `/LIS/public/api/v1/ping`, sehingga middleware
melaporkan `LIS v? — database ?`.

Penyebabnya baris `RewriteBase /` pada `public/.htaccess`. Baris itu membuat
setiap permintaan diarahkan ke `/index.php` di DocumentRoot, bukan ke
`index.php` milik LIS. **Hapus baris tersebut.** Tanpa `RewriteBase`,
mod_rewrite menyelesaikan substitusi terhadap folder `.htaccess` itu sendiri,
sehingga aturan yang sama bekerja baik di DocumentRoot maupun di sub-folder.

### Semua halaman 404 saat memakai `php -S`

Cirinya, log server bawaan PHP memperlihatkan awalan sub-folder yang ikut
terbawa:

```
[302]: GET /
[404]: GET /LIS/public/login
[404]: GET /LIS/public/assets/css/app.css - No such file or directory
```

Pengalihan pertama sudah menuju alamat yang salah. Sebabnya `app.base_url`
berisi `/LIS/public` — benar untuk Apache, tetapi pada `php -S ... -t public`
folder `public/` sudah menjadi akar situs, sehingga awalan itu menunjuk ke
lokasi yang tidak ada.

Sejak versi ini `Url::base()` mengabaikan `app.base_url` ketika berjalan pada
SAPI `cli-server` dan menurunkan awalannya dari `SCRIPT_NAME`. Konfigurasi
tidak perlu disunting; pastikan saja `app/Core/Url.php` sudah versi terbaru.

Jangan menyiasatinya dengan mengosongkan `app.base_url` — nilai itu tetap
dibutuhkan Apache, dan mengosongkannya akan merusak akses lewat
`http://localhost/LIS/public`.

### "Call to undefined function mb_strlen()" atau halaman 500 hanya di peramban

PHP CLI dan PHP milik Apache membaca **php.ini yang berbeda**. Ekstensi bisa
lulus pada `php bin/selftest.php` namun tetap hilang saat diakses lewat
peramban.

Sejak versi ini LIS memeriksa ekstensi wajib saat start dan menyebutkan
php.ini mana yang sedang dipakai. Bila muncul pesan tersebut, buka php.ini
Apache (panel XAMPP → **Config → PHP (php.ini)**), hapus titik-koma di depan
baris `extension=` yang disebut, lalu jalankan ulang Apache.

Ekstensi yang wajib: `pdo`, `pdo_mysql`, `mbstring`, `json`, `openssl`.

### Halaman kosong tanpa pesan

Aktifkan sementara di `config/config.php`:

```php
'app' => ['debug' => true, ...],
```

Lalu buka `storage/logs/lis-*.log`. **Kembalikan ke `false`** setelah selesai
— mode debug menampilkan jalur berkas dan pesan galat kepada pengguna.

### "Koneksi database gagal"

1. Pastikan MySQL berjalan di panel XAMPP
2. Cocokkan kredensial pada `config/config.php`
3. Uji langsung: `mysql -u root -p db_lis -e "SELECT 1"`

### "Token keamanan (CSRF) tidak sah"

Sesi kedaluwarsa. Muat ulang halaman lalu ulangi. Bila sering terjadi,
periksa apakah folder session PHP dapat ditulis dan `session.gc_maxlifetime`
tidak terlalu pendek.

### "Illegal mix of collations"

Collation koneksi berbeda dari collation tabel. Pastikan
`config/config.php` memuat:

```php
'db' => [
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
],
```

Nilai `collation` harus sama dengan yang dipakai tabel — bawaan skema kami
`utf8mb4_unicode_ci`.

### Akun terkunci

Setelah 5 kali gagal, akun dikunci 15 menit per kombinasi pengguna+IP.
Untuk membuka segera:

```sql
DELETE FROM login_attempts WHERE username = 'namapengguna';
```

Ambangnya diatur di `config/config.php` bagian `security`.

### Lupa kata sandi admin

```bash
php -r "echo password_hash('SandiBaru123', PASSWORD_BCRYPT), PHP_EOL;"
```

```sql
UPDATE users SET password_hash = '<hasil di atas>' WHERE username = 'admin';
```

---

## Alat laboratorium

### Alat tidak muncul di middleware

1. Pastikan alat **aktif** di menu Alat Laboratorium
2. Middleware memuat ulang konfigurasi tiap 5 menit; jalankan ulang untuk
   segera menerapkan
3. Periksa log saat start:

```
[HEMA-01] Hematology Analyzer 1 — ASTM / tcp_server (dua arah)
[HEMA-01] Menunggu koneksi analyzer di 0.0.0.0:5100
```

### Simulator: "ECONNREFUSED 127.0.0.1:5100"

Port 5100 tidak ada yang mendengarkan. Middleware hanya membuka port untuk
alat yang **aktif**, dan skema bawaan sengaja mengirim ketiga contoh alat
dalam keadaan tidak aktif agar tidak ada port yang terbuka tanpa
sepengetahuan Anda.

Urutan pemeriksaan:

1. **Aktifkan alatnya.** Menu **Alat Laboratorium** → `HEMA-01` → **Ubah** →
   centang **Alat aktif** → simpan.
2. **Jalankan ulang middleware** (`npm start`). Konfigurasi memang dimuat
   ulang otomatis tiap 5 menit, tetapi menjalankan ulang membuatnya langsung
   berlaku.
3. **Pastikan log start menyebut port itu:**

   ```
   [HEMA-01] Hematology Analyzer 1 — ASTM / tcp_server (dua arah)
   [HEMA-01] Menunggu koneksi analyzer di 0.0.0.0:5100
   ```

Bila yang muncul justru `Tidak ada alat aktif di LIS`, middleware berhasil
menghubungi LIS tetapi tidak menemukan alat aktif — kembali ke langkah 1.

Bila yang muncul `LIS merespons: LIS v? — database ?`, tanda tanya itu
berarti middleware menerima jawaban yang **bukan** JSON dari LIS — hampir
selalu masalah rewrite; lihat "Setiap URL menampilkan dashboard XAMPP" di
atas.

### "API key tidak dikenali atau sudah dinonaktifkan"

```
ERROR Tidak dapat mengambil konfigurasi alat dari LIS:
      GET /api/v1/instruments gagal: API key tidak dikenali atau sudah dinonaktifkan.
```

Middleware berhasil menghubungi LIS — jawaban JSON-nya sampai — tetapi
kredensialnya ditolak.

Sebab tersering: `middleware/.env` masih memuat kunci bawaan skema
`lis_mw_0000...`. `bin/install.php` **sengaja menonaktifkan** kunci itu
setelah menerbitkan kunci asli, sebagai langkah pengamanan.

Perbaikan:

1. **LIS → Pengaturan → Kredensial API → Terbitkan kredensial baru**,
   cakupan `instrument`
2. Salin `LIS_API_KEY` dan `LIS_API_SECRET` ke `middleware/.env`
   (secret hanya ditampilkan **satu kali**)
3. Jalankan ulang middleware

Kunci lama tidak dapat dibaca kembali dari database — secret disimpan
sebagai hash bcrypt dan salinan terenkripsi yang terikat `security.app_key`.
Bila hilang, terbitkan yang baru; tidak ada cara memulihkannya.

Periksa cakupannya juga: kredensial untuk middleware harus bercakupan
`instrument`, bukan `khanza`.

```sql
SELECT id, nama, LEFT(api_key,14) AS kunci, scopes, aktif FROM api_clients;
```

### Analyzer Mindray — ringkasan kesesuaian protokol

LIS mengikuti spesifikasi Mindray pada titik-titik berikut. Bila salah satu
menyimpang, alat dapat menolak data atau LIS salah menafsirkannya.

| Ketentuan | Nilai | Sumber |
|---|---|---|
| Kerangka pesan | MLLP `<VT>` … `<FS><CR>` | BC-5000 §1 |
| Encoding | UTF-8 (MSH-18 `UNICODE`) | BC-5000 §3.2.1 |
| Versi HL7 | 2.3.1, dicerminkan dari MSH-12 alat | BC-5000 §3.2.1 |
| Balasan hasil | `ACK^R01` + `MSA` | BC-5000 §4.2 |
| Permintaan worklist | alat kirim `ORM^O01`, `ORC\|RF\|\|<Sample>\|\|IP` | BC-5800 D.4.2 |
| Jawaban worklist | LIS kirim `ORR^O02`, `ORC\|AF\|<Sample>` | BC-5800 D.4.2 |
| Batas jawab worklist | 2 detik | BC-5800 D.2.2 |
| Denyut hidup | `0x02` tiap 3 detik, di luar blok MLLP | BC-5800 D.2.1 |
| Identitas parameter | OBX-3 komponen **ID**, bukan nama | BC-5000 §4.7 |
| Data kontrol mutu | MSH-11 `Q` (BC-5000), `D`/`T` (BC-5800) | keduanya |
| Escape | `\F\ \S\ \T\ \R\ \E\ \.br\` | BC-5000 §3 |

Empat hal yang paling mudah keliru:

1. **Data QC bukan hasil pasien.** QC dikirim sebagai `ORU^R01` juga —
   pembedanya hanya MSH-11. Pada pesan QC, PID-3 berisi **nomor lot
   kontrol**, bukan nomor rekam medis. LIS memisahkannya dan tidak pernah
   memproses QC sebagai hasil pasien.
2. **Denyut hidup `0x02`** mengalir di sela pesan pada port satu arah.
   Byte ini berada di luar blok MLLP dan dibuang, bukan ditimbun.
3. **OBX bertipe `ED`** memuat histogram dan scattergram dalam base64.
   Itu bukan hasil pemeriksaan dan dilewati.
4. **Kode parameter adalah ID pada OBX-3**, mis. `718-7`, bukan `HGB`.
   Lihat `database/03_pemetaan_mindray_bc5000.sql`.

### Analyzer menampilkan galat atau minta restart saat menerima order

**Hentikan pengiriman worklist lebih dulu.** Analyzer yang harus di-restart
di jam pelayanan adalah gangguan nyata bagi pasien, dan penelusuran tidak
boleh dilakukan sambil terus mengirim pesan yang sama.

Dua cara mematikannya, pakai yang paling cepat dijangkau:

```
LIS → Alat Laboratorium → <alat> → Ubah → Mode: Satu arah (hasil saja)
```

atau pada `middleware/.env`:

```
HL7_WORKLIST_REPLY=off
```

Keduanya menghentikan LIS mengirim order. Alat tetap dapat mengirim hasil,
jadi pelayanan bisa berjalan sementara masalahnya ditelusuri.

Sesudah aman, kumpulkan buktinya:

```
LOG_LEVEL=trace
```

Jalankan ulang middleware, minta worklist sekali dari alat, lalu buka
`middleware/logs/gateway-*.log`. Yang dicari adalah **pesan query dari
alat** — MSH-9 menyebut jenis pesan yang dipakainya (`QRY^Q02` atau
`QBP^Q11`), dan MSH-12 menyebut versi HL7-nya.

Sebab yang paling sering:

| Sebab | Tanda pada trace |
|---|---|
| Versi HL7 balasan berbeda dari versi alat | MSH-12 pada query berbeda dari MSH-12 pada balasan |
| Jenis pesan balasan tidak diharapkan alat | Alat mengirim `QBP^Q11` tetapi dijawab `ORM^O01` |
| Kode pemeriksaan tidak dikenal alat | Order diterima, lalu alat menolak per-parameter |

Sejak versi ini LIS mencerminkan versi HL7 alat dan memilih bentuk balasan
dari jenis query — `QBP^Q11` dijawab `RSP^K11` lengkap dengan `MSA` dan
`QAK`, `QRY^Q02` dijawab `ORM^O01`. Bila alat Anda menuntut bentuk tertentu,
paksakan lewat `HL7_WORKLIST_REPLY=orm` atau `=rsp`.

### Tidak tahu port atau arah koneksi alat

Menu komunikasi analyzer berbeda-beda antar merek dan versi firmware.
Sebagian tidak menampilkan nomor port sama sekali karena portnya tetap;
sebagian menyembunyikan pilihan protokol di mode servis. Jangan menebak —
biarkan alat itu sendiri yang memberi tahu:

```bash
cd middleware
# Hentikan middleware dulu agar port 5100/5200 bebas.

# 1. Analyzer sebagai TCP client — analyzer yang menyambung ke LIS
npm run temukan:alat -- --dengar
#    lalu tekan "kirim ke LIS" atau jalankan satu sampel pada analyzer

# 2. Analyzer sebagai TCP server — LIS yang menyambung ke analyzer
npm run temukan:alat -- --pindai 192.168.0.15
```

Mode `--dengar` membuka 17 port lazim sekaligus dan menampilkan byte apa pun
yang masuk beserta tebakan protokolnya:

```
  ANALYZER MENYAMBUNG  →  port 5150
  hex   : 0b 4d 53 48 7c 5e 7e 5c 26 7c 42 43 2d 35 30 30 30 …
  teks  : <VT>MSH|^~\&|BC-5000|LAB|LIS|LAB|…<CR>
  tebakan protokol: HL7 v2 lewat MLLP (diawali VT 0x0B)

  KESIMPULAN
    Transport : TCP Server (analyzer menyambung ke LIS)
    Port      : 5150
```

Mulailah dari `--dengar`: mode itu tidak memerlukan tebakan apa pun.

### "ECONNREFUSED" padahal ping ke alat berhasil

Ping memakai ICMP, pengiriman data memakai TCP. Ping berhasil hanya
membuktikan alamatnya hidup dan terjangkau — bukan bahwa ada yang
mendengarkan di port itu.

`ECONNREFUSED` berarti alamatnya dijawab, tetapi **tidak ada yang
mendengarkan di port tersebut**. Sebabnya, berurutan dari yang paling sering:

1. **Arah koneksinya terbalik.** Analyzer justru menyambung ke LIS, bukan
   sebaliknya. Ubah Transport menjadi **TCP Server**, Host `0.0.0.0`, lalu
   isikan alamat komputer LIS pada menu komunikasi analyzer.
2. **Komunikasi LIS belum dinyalakan** pada menu analyzer. Banyak alat
   mengirim hanya setelah "auto transmit" atau "kirim ke LIS" diaktifkan.
3. **Portnya berbeda.** Pakai `npm run temukan:alat` di atas.
4. **Firewall** memblokir port itu meski ICMP lolos.

### "Port 5100 sudah dipakai proses lain"

Ada proses lain yang mendengarkan port itu — sering kali instance
middleware lama yang belum berhenti.

```bash
# Linux/macOS
lsof -i :5100
# Windows
netstat -ano | findstr :5100
```

### Alat tersambung tetapi tidak ada data

Hidupkan `LOG_LEVEL=trace` pada `middleware/.env`, jalankan ulang, lalu
amati. Bila benar-benar nol byte:

- Alat belum dikonfigurasi mengirim ke LIS (periksa menu komunikasi alat)
- Alamat IP host pada alat salah
- Firewall memblokir port
- Untuk serial: kabel bukan null modem, atau nama port salah

### Karakter acak pada log serial

Parameter serial tidak cocok. Periksa **baud rate** lebih dulu, lalu
parity dan data bits. Nilai lazim 9600 8N1.

### Hasil masuk tetapi tidak tersimpan

Buka **Alat Laboratorium → Log Komunikasi** dan lihat kolom status.

| Status | Sebab |
|---|---|
| `tidak_cocok` | Tidak satu pun nilai tersimpan |
| `sebagian` | Sebagian tersimpan, sebagian ditolak |
| `error` | Kode alat belum terdaftar di LIS |
| `diabaikan` | Pesan bukan pembawa hasil (mis. host query) |

Sebab persisnya ada pada kolom `alasan` di **Hasil Belum Terpetakan**:

| `alasan` | Artinya | Penanganan |
|---|---|---|
| `sample_tidak_ditemukan` | Sample ID tidak cocok dengan barcode, nomor lab, atau nomor order mana pun | Buat ordernya, lalu pasangkan dari layar itu |
| `parameter_belum_dipetakan` | Kode parameter alat belum dikenal LIS | Lengkapi **Alat → Pemetaan Kode** |
| `tidak_diminta_pada_order` | Parameternya dikenal, tetapi tidak diminta pada order tersebut | Wajar bila alat mengirim seluruh panelnya. Tambahkan pemeriksaan ke order bila memang diperlukan |
| `ditolak_hasil_sudah_diverifikasi` | Hasil lama sudah diverifikasi/dikoreksi sehingga tidak boleh ditimpa alat | Bila nilai baru yang benar, batalkan verifikasi atau pakai jalur koreksi |
| `sebagian_tidak_tersimpan` | Lebih dari satu sebab pada satu sampel | Buka detailnya |

Buka detail pesan untuk melihat pesan mentah dan hasil parsingnya.
Hasil yang tidak tersimpan **selalu** ada di **Hasil Belum Terpetakan** —
tidak pernah dibuang. Satu-satunya pengecualian yang disengaja adalah nilai
kosong dari alat dan parameter yang ditandai "abaikan" pada pemetaan; keduanya
bukan kehilangan data.

Setelah penyebabnya diperbaiki (pemetaan dilengkapi atau order dibuat),
proses ulang pesan dari **Log Komunikasi → Detail → Proses Ulang**.

### Sample ID tidak cocok

LIS mencocokkan sample ID dengan urutan: barcode spesimen → nomor lab →
nomor order → bentuk tanpa angka nol di depan.

Bila alat mengirim nomornya sendiri (bukan hasil pindai barcode), pastikan
operator memasukkan barcode LIS ke alat, atau pakai alat dalam mode dua
arah agar alat menanyakan worklist berdasarkan barcode yang dipindainya.

### Middleware berjalan tetapi LIS tidak menerima apa pun

Periksa folder spool:

```bash
ls middleware/spool/*.json | wc -l
```

Bila menumpuk, middleware tidak dapat menghubungi LIS. Cek `LIS_BASE_URL`
dan uji dari mesin middleware:

```bash
curl http://localhost/LIS/public/api/v1/ping
```

Berkas di `middleware/spool/gagal/` adalah payload yang ditolak permanen
oleh LIS — biasanya karena kode alat tidak terdaftar. Perbaiki sebabnya,
lalu pindahkan berkasnya kembali ke `middleware/spool/`.

**Hasil di spool tidak hilang.** Begitu LIS kembali, antrian terkirim
otomatis dalam 15 detik.

---

## Integrasi Khanza

### Uji koneksi gagal

Dari server LIS:

```bash
curl -H "X-API-Key: KUNCI" http://IP-KHANZA/khanza-connector/api/ping.php
```

| Hasil | Penanganan |
|---|---|
| Connection refused | Apache di server Khanza mati, atau firewall |
| 404 | Folder konektor salah tempat atau URL keliru |
| 401 | API key berbeda antara LIS dan `config.php` konektor |
| 403 | IP server LIS tidak ada di `ip_whitelist` |
| 500 dengan "config.php belum dibuat" | Salin `config.example.php` menjadi `config.php` |

### Order Khanza ditolak

Pesan pada Integrasi → Log menyebutkan kode yang belum dipetakan.

1. **Integrasi → Impor Master Pemeriksaan**
2. **Integrasi → Pemetaan Pemeriksaan**, lengkapi baris bertanda merah
3. Order yang ditolak tidak ditandai terambil, jadi akan terkirim ulang
   sendiri pada jalannya cron berikutnya

### Hasil tidak sampai ke Khanza

Periksa berurutan:

```sql
-- di LIS
SELECT status, percobaan, pesan FROM khanza_sync_log
WHERE jenis='hasil' ORDER BY id DESC LIMIT 5;

-- di server Khanza
SELECT * FROM sik_bridging_lab.detail_hasil_lab WHERE noorder='LB0001';
```

Bila `detail_hasil_lab` sudah terisi, LIS sudah menyelesaikan tugasnya —
yang tersisa adalah menarik data lewat menu bridging di aplikasi desktop
Khanza.

Bila statusnya `antri`, pastikan cron berjalan:

```bash
php bin/sync_khanza.php --antrian
```

### Hasil tergandakan di Khanza

Seharusnya tidak terjadi: pengiriman menghapus hasil lama untuk `noorder`
tersebut sebelum menulis ulang. Bila tetap terjadi, berarti ada lebih dari
satu order LIS memakai `noorder` Khanza yang sama:

```sql
SELECT khanza_noorder, COUNT(*) FROM orders
WHERE khanza_noorder IS NOT NULL GROUP BY khanza_noorder HAVING COUNT(*) > 1;
```

### Order terkirim berulang dari Khanza

Kolom `status_ambil` tidak terbarui. Pastikan `tandai_terambil` bernilai
`true` pada `config.php` konektor, dan pengguna database konektor punya
hak `UPDATE` pada `permintaan_lab`.

---

## Hasil dan nilai rujukan

### Flag tidak muncul

Pemeriksaan belum punya nilai rujukan. Buka **Master Pemeriksaan →
Rujukan**. Tanpa rujukan, hasil disimpan apa adanya tanpa penilaian.

### Rujukan yang dipakai terasa keliru

Sistem memilih baris paling spesifik: jenis kelamin eksplisit mengalahkan
"semua", lalu rentang umur tersempit.

Bila pasien belum punya tanggal lahir, sistem mengasumsikan dewasa
(30 tahun) dan menampilkan peringatan di layar entri. Lengkapi data pasien
agar rujukan yang dipakai benar — ini penting terutama untuk bayi dan anak.

### Nilai kritis tidak terdeteksi

Ambang kritis kosong pada nilai rujukan. `bin/selftest.php` memeriksa hal
ini dan menyebutkan pemeriksaan mana yang ditandai kritis tetapi belum
punya ambang.

### Tidak bisa memverifikasi

Pesan yang muncul menyebutkan sebabnya. Yang tersering: masih ada nilai
kritis yang belum tercatat pelaporannya ke DPJP. Catat pelaporannya lebih
dulu — ini disengaja.

### Hasil dari alat tidak menimpa hasil lama

Memang begitu rancangannya: hasil yang sudah **diverifikasi** tidak dapat
ditimpa otomatis. Batalkan verifikasi (sebelum rilis) atau gunakan jalur
koreksi (setelah rilis).

---

## Kinerja

### Halaman daftar lambat

Persempit rentang tanggal. Bawaan daftar order adalah hari ini.

Bila database sudah besar, pastikan pembersihan berkala berjalan:

```bash
php bin/sync_khanza.php --bersih
```

Menghapus pesan mentah alat yang melewati masa retensi (bawaan 90 hari).
Jejak audit tidak pernah dihapus.

### Database membesar cepat

Penyumbang terbesar biasanya `instrument_messages` karena menyimpan pesan
mentah. Kurangi retensi di **Pengaturan Sistem** (`middleware.simpan_raw_hari`),
atau matikan penyimpanan mentah per alat setelah alat itu terbukti stabil.

---

## Pemulihan darurat

### LIS mati saat jam pelayanan

Hasil tidak hilang: middleware terus menerima dan menyimpannya ke spool.
Untuk hasil mendesak, nilai dapat dibaca langsung dari layar analyzer dan
dilaporkan lisan sesuai prosedur nilai kritis. Setelah LIS hidup, seluruh
antrian masuk otomatis.

### Memulihkan dari cadangan

```bash
gunzip -c /backup/db_lis-2026-09-01.sql.gz | mysql -u root -p db_lis
```

Kembalikan juga `config/config.php` — berkas ini memuat `app_key`, dan
tanpa kunci itu secret kredensial API tidak dapat didekripsi. Bila
`app_key` hilang, terbitkan ulang seluruh kredensial API lewat
**Pengaturan → Kredensial API**.

### Memeriksa apa yang terjadi pada sebuah hasil

```sql
SELECT h.*, u.nama AS oleh
FROM result_history h
LEFT JOIN users u ON u.id = h.user_id
WHERE h.result_id = 123 ORDER BY h.id;
```

Seluruh perubahan nilai, status, alasan, dan pelakunya tercatat permanen.
Untuk tindakan pada tingkat sistem, lihat **Pengaturan → Jejak Audit**.
