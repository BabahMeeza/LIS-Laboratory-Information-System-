# Panduan Pemasangan LIS

Panduan ini mengasumsikan XAMPP. Untuk stack lain (LAMP, Laragon, Docker),
prinsipnya sama: arahkan document root ke `public/`.

---

## 1. Prasyarat

| Kebutuhan | Versi | Catatan |
|---|---|---|
| PHP | 8.0+ | Ekstensi `pdo_mysql`, `mbstring`, `json`, `openssl` wajib; `curl` dianjurkan |
| MySQL / MariaDB | 5.7+ / 10.4+ | Bawaan XAMPP sudah memenuhi |
| Apache | 2.4 | `mod_rewrite` **wajib aktif** |
| Node.js | 18+ | Hanya untuk middleware alat |

Periksa versi PHP:

```bash
# Windows
C:\xampp\php\php.exe -v
# macOS
/Applications/XAMPP/xamppfiles/bin/php -v
# Linux
php -v
```

---

## 2. Menempatkan berkas

Letakkan folder `LIS` di dalam `htdocs`:

```
Windows :  C:\xampp\htdocs\LIS
macOS   :  /Applications/XAMPP/xamppfiles/htdocs/LIS
Linux   :  /opt/lampp/htdocs/LIS
```

---

## 3. Mengaktifkan mod_rewrite

Buka `httpd.conf` (XAMPP → Apache → Config → httpd.conf) lalu pastikan
baris berikut **tidak** diawali tanda pagar:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Pada blok `<Directory>` yang memuat `htdocs`, pastikan:

```apache
AllowOverride All
```

Tanpa dua hal ini, hanya halaman depan yang terbuka; menu lain akan
menghasilkan 404.

---

## 4. Mengarahkan document root ke `public/`

**Ini langkah keamanan, bukan sekadar kerapian.** Bila seluruh folder
proyek terekspos, berkas `config/config.php` yang memuat kata sandi
database dapat dibaca lewat peramban.

Tersedia dua cara.

### Cara A — Virtual host (dianjurkan)

Tambahkan pada `httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName lis.local
    DocumentRoot "C:/xampp/htdocs/LIS/public"

    <Directory "C:/xampp/htdocs/LIS/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Lalu tambahkan pada berkas `hosts` sistem:

```
127.0.0.1   lis.local
```

Akses lewat `http://lis.local`.

### Cara B — Sub-folder

Biarkan di `htdocs/LIS` dan akses lewat `http://localhost/LIS`.
Berkas `.htaccess` di akar proyek sudah menolak akses ke `config/`,
`app/`, `storage/`, dan folder sensitif lainnya, lalu meneruskan
permintaan ke `public/`.

Cara ini berfungsi, tetapi Cara A tetap lebih aman karena berkas
sensitif tidak pernah berada di bawah document root sama sekali.

---

## 5. Menjalankan pemasang

```bash
cd C:\xampp\htdocs\LIS
C:\xampp\php\php.exe bin\install.php
```

Pemasang akan:

1. Memeriksa versi PHP, ekstensi, dan izin tulis folder `storage/`
2. Menanyakan kredensial database, lalu membuat `config/config.php`
   lengkap dengan `app_key` acak
3. Membuat skema database dan mengisi ±90 pemeriksaan beserta nilai
   rujukannya
4. Membuat akun administrator
5. Menerbitkan kredensial API untuk middleware alat

Catat baris berikut yang muncul di akhir — **secret hanya ditampilkan sekali**:

```
LIS_API_KEY=lis_xxxxxxxxxxxx
LIS_API_SECRET=xxxxxxxxxxxxxxxx
```

Verifikasi hasilnya:

```bash
php bin/selftest.php
```

Semua baris harus bertanda ✓. Bila ada ✗, pesannya menyebutkan
langkah perbaikan.

### Bila ingin memasang manual

```bash
cp config/config.example.php config/config.php
# sunting kredensial database dan isi security.app_key dengan:
php -r "echo bin2hex(random_bytes(32));"

mysql -u root -p < database/01_schema.sql
mysql -u root -p < database/02_seed_master.sql
```

Akun bawaan dari data master: `admin`, `dokter`, `analis`, `sampling`,
semuanya dengan kata sandi `Lis#2026`. **Ganti atau nonaktifkan sebelum
dipakai melayani pasien** — `bin/install.php` menawarkan ini secara otomatis.

---

## 5b. Menjalankan LIS secara lokal

Untuk pengembangan dan uji coba di komputer sendiri, ada dua cara. Cara
pertama jauh lebih cepat karena tidak menyentuh konfigurasi Apache sama
sekali.

### Cara cepat — server bawaan PHP (untuk lokal saja)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/LIS
/Applications/XAMPP/xamppfiles/bin/php -S localhost:8080 -t public
```

Buka `http://localhost:8080`. Hentikan dengan `Ctrl+C`.

Server bawaan PHP sudah meneruskan seluruh alamat ke `public/index.php`,
sehingga routing bekerja penuh tanpa `mod_rewrite` dan tanpa virtual host.
MySQL tetap harus dinyalakan dari XAMPP Manager.

**`app.base_url` tidak perlu diubah.** Dengan `-t public`, folder `public/`
menjadi akar situs, sehingga awalan seperti `/LIS/public` justru menunjuk ke
lokasi yang tidak ada. LIS mengabaikan `app.base_url` saat berjalan di server
bawaan PHP dan menurunkan awalannya sendiri, jadi satu `config/config.php`
yang sama dapat dipakai untuk Apache maupun `php -S` tanpa disunting
bolak-balik.

Cara ini **hanya untuk lokal**. Server bawaan PHP melayani satu permintaan
pada satu waktu dan tidak dirancang untuk produksi — untuk melayani
pengguna sungguhan, pakai Apache seperti pada bagian 4.

### Cara Apache — seperti kondisi produksi

Ikuti bagian 3 dan 4 di atas (aktifkan `mod_rewrite`, arahkan document
root ke `public/`), lalu akses `http://localhost/LIS` atau
`http://lis.local` sesuai cara yang dipilih.

Pakai cara ini bila Anda ingin menguji perilaku yang bergantung pada
Apache: aturan `.htaccess`, header keamanan, atau akses dari komputer lain
di jaringan.

### Urutan menjalankan seluruh sistem

Tiga proses, dijalankan di tiga jendela Terminal:

```bash
# Jendela 1 — MySQL
# Nyalakan lewat XAMPP Manager, atau:
sudo /Applications/XAMPP/xamppfiles/xampp startmysql

# Jendela 2 — LIS
cd /Applications/XAMPP/xamppfiles/htdocs/LIS
/Applications/XAMPP/xamppfiles/bin/php -S localhost:8080 -t public

# Jendela 3 — middleware alat
cd /Applications/XAMPP/xamppfiles/htdocs/LIS/middleware
npm start
```

Lalu di jendela keempat, kirim hasil dari analyzer tiruan:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/LIS/middleware
npm run simulate:astm -- --query
```

Bila memakai server bawaan PHP di port 8080, sesuaikan `middleware/.env`:

```
LIS_BASE_URL=http://localhost:8080
```

### Memeriksa versi PHP XAMPP

LIS memerlukan PHP 8.0 atau lebih baru:

```bash
/Applications/XAMPP/xamppfiles/bin/php -v
```

Bila XAMPP Anda masih PHP 7.x, ada dua pilihan:

1. Perbarui XAMPP ke versi dengan PHP 8 (paling sederhana)
2. Pakai PHP dari Homebrew hanya untuk menjalankan LIS, sementara MySQL
   tetap dari XAMPP:

   ```bash
   brew install php
   cd /Applications/XAMPP/xamppfiles/htdocs/LIS
   php bin/install.php
   php -S localhost:8080 -t public
   ```

   Pada `config/config.php`, pastikan `db.host` diisi `127.0.0.1`
   (bukan `localhost`), agar PHP menyambung lewat TCP dan bukan lewat
   socket XAMPP yang jalurnya berbeda.

### Masalah lokal yang paling sering muncul

| Gejala | Penanganan |
|---|---|
| `Address already in use` di port 8080 | Ganti portnya: `-S localhost:8081`. Cari pemakainya dengan `lsof -i :8080` |
| `Koneksi database gagal` padahal MySQL hidup | Ganti `db.host` menjadi `127.0.0.1`. `localhost` membuat PHP mencari socket Unix yang mungkin berbeda jalur |
| `No such file or directory` saat menyambung MySQL | Sama seperti di atas — masalah socket, pakai `127.0.0.1` |
| `Access denied for user 'root'` | XAMPP macOS bawaannya tanpa kata sandi root. Bila pernah diberi kata sandi, isikan pada `db.pass` |
| Perintah `php` tidak dikenali | Pakai jalur lengkap `/Applications/XAMPP/xamppfiles/bin/php`, atau tambahkan ke PATH |
| `npm: command not found` | Node.js belum terpasang. Unduh dari nodejs.org, atau `brew install node` |
| Halaman terbuka tetapi tanpa gaya (CSS) | Bila memakai Apache di sub-folder, isi `app.base_url` pada `config/config.php` dengan `/LIS/public` — sesuai alamat yang benar-benar diketik di peramban |
| Semua halaman **404** di `php -S`, dan log menunjukkan `GET /LIS/public/login` | Awalan sub-folder ikut terbawa ke server bawaan PHP. Sudah diperbaiki: LIS kini mengabaikan `app.base_url` pada `php -S`. Pastikan `app/Core/Url.php` sudah versi terbaru |
| Setiap alamat menampilkan dashboard XAMPP dengan status **200** | Ada baris `RewriteBase /` pada `public/.htaccess`. Hapus baris itu |

---

## 6. Masuk pertama kali

Buka LIS di peramban, masuk dengan akun administrator, lalu:

1. **Pengaturan Sistem** — isi nama fasilitas, nama laboratorium, alamat,
   dan telepon. Data ini tercetak pada kop lembar hasil.
2. **Pengguna** — buat akun untuk analis, verifikator, dan petugas sampling.
   Nonaktifkan akun contoh bila belum dilakukan pemasang.
3. **Master Pemeriksaan** — sesuaikan tarif dan target TAT. Periksa nilai
   rujukan terhadap insert reagen dan metode yang laboratorium Anda pakai;
   nilai bawaan adalah rujukan umum, bukan pengganti validasi laboratorium.

---

## 7. Menghubungkan alat laboratorium

### 7.1 Mendaftarkan alat di LIS

Menu **Alat Laboratorium → Tambah Alat**:

| Kolom | Keterangan |
|---|---|
| Kode alat | Identitas yang dipakai middleware, mis. `HEMA-01` |
| Protokol | `astm`, `hl7`, atau `raw` |
| Transport | `tcp_server` (alat menyambung ke LIS), `tcp_client` (LIS menyambung ke alat), atau `serial` |
| Mode | `bidirectional` bila alat mendukung host query |
| Port | Port TCP yang didengarkan, mis. 5100 |

Pada analyzer, isi "Host IP" dengan alamat komputer middleware dan
"Host Port" dengan port yang sama.

### 7.2 Menjalankan middleware

Bila Anda menjalankan `php bin/install.php`, berkas `middleware/.env` sudah
dibuat lengkap dengan kunci API — cukup periksa `LIS_BASE_URL` di dalamnya.

Bila membuatnya manual:

```bash
cd middleware
cp .env.example .env
```

Isi `.env`:

```
LIS_BASE_URL=http://localhost/LIS/public
LIS_API_KEY=lis_xxxxxxxxxxxx
LIS_API_SECRET=xxxxxxxxxxxxxxxx
LIS_SIGN_REQUESTS=true
```

> **`LIS_API_KEY` harus kunci terbitan, bukan kunci bawaan skema.**
> `bin/install.php` menonaktifkan `lis_mw_0000...` demi keamanan, sehingga
> kunci itu selalu ditolak dengan *"API key tidak dikenali atau sudah
> dinonaktifkan"*. Terbitkan lewat **Pengaturan → Kredensial API** dengan
> cakupan `instrument`; secret hanya ditampilkan satu kali.

Lalu:

```bash
npm start
```

Middleware tidak memerlukan `npm install` kecuali Anda memakai alat serial:

```bash
npm install serialport
```

### 7.3 Menguji tanpa alat sungguhan

**Buat dulu order yang akan menerima hasilnya.** Simulator memakai sample ID
bawaan `2608310001` yang tidak ada di instalasi baru; tanpa order yang cocok,
hasilnya tidak punya tempat menempel dan seluruhnya berakhir di Hasil Belum
Terpetakan.

Urutannya:

1. **Order Baru** — pilih pasien, centang paket **Darah Lengkap**, simpan
2. Catat **barcode** pada label spesimen yang tercetak, mis. `2609020007`
3. Jalankan simulator dengan barcode itu:

```bash
cd middleware
node simulator/astmAnalyzer.js --query --sample 2609020007
```

Untuk analyzer kimia klinik:

```bash
node simulator/hl7Analyzer.js --sample 2609020007
```

Periksa hasilnya di **Worklist** lalu **Verifikasi**.

Yang ditampilkan log middleware:

| Baris | Artinya |
|---|---|
| `INFO Hasil terkirim ke LIS: 15 dari 15 nilai` | Semua tersimpan |
| `WARN ... hanya menyimpan 1 dari 15 nilai` | Sebagian ditolak; sebabnya ikut dicetak |
| `WARN Sample "..." tidak ditemukan di LIS` | Barcode tidak cocok dengan order mana pun |

Nilai yang tidak tersimpan **tidak dibuang** — seluruhnya masuk ke
**Alat Laboratorium → Hasil Belum Terpetakan** beserta sebabnya, dan dapat
dipasangkan ke order yang benar dari layar itu.

Bila `npm run simulate:astm -- --query` dijalankan tanpa `--sample`, angka
`0 dari 15` adalah hasil yang benar, bukan kegagalan: sample ID bawaan memang
tidak ada di database Anda.

### 7.3b Memeriksa worklist sebelum menyambung alat sungguhan

Analyzer sungguhan menolak worklist cacat **tanpa memberi pesan yang
berguna** — sampel dijalankan dengan profil bawaannya, atau alat diam saja.
Kegagalan seperti itu sangat mahal ditelusuri di lapangan, jadi periksa
bentuk kawatnya lebih dulu:

```bash
cd middleware
npm run verify:worklist -- --sample 2609020008
```

Perekam ini berperan sebagai analyzer, mengirim host query, lalu memeriksa
setiap byte jawaban terhadap ASTM E1381 dan E1394:

```
← STX 3 [ETX] cs=28 ✓  O|1|2609020008||^^^HGB\^^^HCT\^^^RBC…|S|…|||||A||||1

  ✓ Checksum 4 bingkai (0 salah)
  ✓ Nomor bingkai berurutan dan berputar pada 0-7
  ✓ Universal test ID memuat 15 kode: BAS%, EOS%, HCT, HGB, …
  ✓ Prioritas "S" adalah nilai E1394 yang sah
  ✓ Jenis kelamin "M" dipetakan ke kode E1394 (M/F/U)

✓ Worklist sah menurut ASTM E1381/E1394 — 15 pemeriksaan terkirim.
```

Perhatikan kodenya: yang terkirim harus **kode alat** (`HGB`, `HCT`), bukan
kode LIS (`HB`, `HT`). Bila yang muncul kode LIS, pemetaan pada
**Alat → Pemetaan Kode** belum lengkap dan alat tidak akan mengenali
permintaannya.

Jalankan juga dengan barcode yang tidak ada:

```bash
npm run verify:worklist -- --sample 9999999999
```

Jawaban yang benar adalah `H` lalu `L|1|I` — kode terminasi ASTM untuk
"tidak ada informasi dari query". Analyzer memahami ini dan melanjutkan.
Bila yang terjadi justru tidak ada jawaban sama sekali, alat sungguhan akan
menggantung menunggu.

### 7.4 Menjalankan middleware sebagai layanan

Agar middleware hidup kembali setiap komputer dinyalakan:

**Windows** — dengan [NSSM](https://nssm.cc):

```
nssm install LIS-Gateway "C:\Program Files\nodejs\node.exe" "C:\xampp\htdocs\LIS\middleware\src\index.js"
nssm set LIS-Gateway AppDirectory "C:\xampp\htdocs\LIS\middleware"
nssm start LIS-Gateway
```

**Linux** — systemd, `/etc/systemd/system/lis-gateway.service`:

```ini
[Unit]
Description=LIS Instrument Gateway
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/lampp/htdocs/LIS/middleware
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=10
User=www-data

[Install]
WantedBy=multi-user.target
```

**macOS** — `launchd`, atau jalankan dengan `pm2`:

```bash
npm install -g pm2
pm2 start src/index.js --name lis-gateway
pm2 startup && pm2 save
```

---

## 8. Integrasi SIMRS Khanza

Lihat [docs/03-integrasi-khanza.md](docs/03-integrasi-khanza.md) untuk
langkah lengkapnya. Ringkasnya:

1. Salin folder `khanza-connector/` ke `htdocs` **server Khanza**
2. Jalankan `khanza-connector/install.sql` untuk membuat tabel bridging
3. Salin `config.example.php` menjadi `config.php`, isi kredensial database
   dan API key acak
4. Di LIS, buka **Pengaturan Sistem → Integrasi**, aktifkan, isi URL
   konektor dan API key yang sama
5. **Integrasi → Uji Koneksi**, lalu **Impor Master Pemeriksaan**
6. Lengkapi **Integrasi → Pemetaan Pemeriksaan** (sebagian besar terpetakan
   otomatis berdasarkan kemiripan nama)

---

## 9. Tugas terjadwal

Pasang dua tugas berkala.

**Sinkronisasi Khanza — setiap 2 menit:**

```
Linux/macOS:  */2 * * * * /usr/bin/php /path/ke/LIS/bin/sync_khanza.php
Windows    :  Task Scheduler → C:\xampp\php\php.exe C:\xampp\htdocs\LIS\bin\sync_khanza.php
```

Tugas ini memproses antrian pengiriman hasil dan mengambil order baru
bila memakai mode pull.

**Pembersihan log — sekali sehari:**

```
0 2 * * * /usr/bin/php /path/ke/LIS/bin/sync_khanza.php --bersih
```

Menghapus pesan mentah alat yang melewati masa retensi. Jejak audit
sengaja tidak pernah dihapus.

---

## 10. Daftar periksa sebelum melayani pasien

- [ ] `php bin/selftest.php` bersih tanpa tanda ✗
- [ ] Document root menunjuk ke `public/`; membuka `.../config/config.php`
      di peramban menghasilkan 403, bukan isi berkas
- [ ] Akun contoh (`dokter`, `analis`, `sampling`) sudah dinonaktifkan
      atau kata sandinya diganti
- [ ] Kredensial API bawaan sudah dinonaktifkan
- [ ] Identitas fasilitas pada lembar hasil sudah benar
- [ ] Nilai rujukan sudah diverifikasi terhadap metode dan reagen laboratorium
- [ ] Ambang nilai kritis sudah disepakati dengan dokter penanggung jawab
- [ ] Pemetaan parameter setiap alat sudah lengkap dan diperiksa
- [ ] Middleware berjalan sebagai layanan, hidup kembali setelah komputer restart
- [ ] Tugas terjadwal sinkronisasi Khanza aktif
- [ ] Pencadangan database berjalan dan **sudah pernah diuji pemulihannya**

---

## 11. Pencadangan

```bash
# Harian
mysqldump -u root -p db_lis | gzip > /backup/db_lis-$(date +%F).sql.gz
```

Sertakan juga `config/config.php` — berkas ini memuat `app_key`, dan tanpa
kunci itu secret kredensial API tidak dapat didekripsi.

Uji pemulihan setidaknya sekali sebelum sistem dipakai melayani pasien.
Cadangan yang belum pernah diuji belum tentu cadangan.
