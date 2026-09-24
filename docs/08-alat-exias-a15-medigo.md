# Panduan komunikasi — EXIAS e|1, BioSystems A15, MediGo URO

Catatan ini memisahkan dengan tegas **apa yang terverifikasi dari dokumen
pabrikan** dan **apa yang masih harus dibuktikan di lapangan**. Pemisahan itu
bukan formalitas: seluruh persoalan HEMA-03 selama dua hari berasal dari satu
hal yang diperlakukan sebagai fakta padahal tebakan — arah koneksi.

Aturan yang dipakai di sini: apa pun yang tidak ada sumbernya, tidak ditulis
sebagai kepastian.

---

## 1. EXIAS e|1 — elektrolit & pH darah

### Terverifikasi

| Hal | Nilai | Sumber |
|---|---|---|
| Protokol | **LIS2-A2 (ASTM)** | brosur & halaman produk EXIAS |
| Antarmuka | Ethernet RJ45; 2× USB 2.0 | brosur |
| Parameter terukur | Na⁺, K⁺, Cl⁻, Ca²⁺, pH, Hct | brosur |
| Parameter terhitung | nCa²⁺, tHb(c) | brosur |
| Lain-lain | 20 µL, 25 detik, 80 sampel/jam | brosur |

Kutipan: *"Data exchange with LIS according to LIS2-A2 protocol (ASTM)"*.

LIS2-A2 adalah nama CLSI untuk ASTM E1394/E1381. LIS Anda sudah memiliki
implementasinya di `middleware/src/protocols/astm.js`, jadi `protokol = astm`
pada tabel `instruments` sudah pasti benar.

### Belum terverifikasi

- **Arah koneksi.** Dokumen publik tidak menyebut apakah alat bertindak
  sebagai TCP client atau server.
- **Nomor port.**

Keduanya ada di menu LIS pada alat, dan dibuktikan dengan
`simulator/temukanAlat.js`.

---

## 2. BioSystems A15 — kimia klinik

### Terverifikasi

Service manual menyatakan A15 *"is controlled on-line in real time from an
external dedicated PC"*. Sambungannya:

| Hal | Nilai |
|---|---|
| Port utama | **COM1** — kabel **USB atau RS-232**, hanya salah satu |
| Port bantu | COM2 — 38400 baud, 8 data bit, 1 stop bit, tanpa paritas |
| Arsitektur | analyzer dikendalikan aplikasi A15 di PC khusus |

### Konsekuensinya, dan ini yang paling penting

**Sambungan USB/RS-232 itu adalah analyzer → PC, bukan sambungan ke LIS.**

A15 tidak akan pernah menyambung ke middleware secara langsung. Yang harus
dihubungkan ke LIS adalah **aplikasi A15 di PC itu**. Pada tangkapan layar
laboratorium, aplikasinya memiliki menu **Konfigurasi** — di situlah
pengaturan host/LIS berada, bila versi perangkat lunaknya menyediakannya.

Jangan menjadwalkan `temukanAlat.js` untuk alat ini sebelum menu Konfigurasi
diperiksa. Yang perlu dipastikan di sana:

1. Apakah ada pengaturan **Host / LIS / Comunicación** sama sekali?
2. Bila ada, apakah keluarannya lewat **serial**, **TCP**, atau **berkas**
   (sebagian perangkat lunak analyzer hanya menulis berkas ke sebuah folder)?
3. Bila serial: port COM berapa, dan parameternya.

Ketiga jawaban itu menentukan penanganannya, dan ketiganya berbeda:

- **serial** → LIS sudah mendukung, `transport = serial` (perlu
  `npm install serialport` di mesin middleware).
- **TCP** → seperti alat lain.
- **berkas** → LIS **belum** mendukung. Perlu transport baru yang mengawasi
  folder. Beri tahu bila ini yang ditemukan; penanganannya tidak sulit,
  tetapi harus dibuat lebih dulu.

Dokumen protokol LIS BioSystems tidak tersedia untuk umum. Untuk A15
khususnya, mintalah **"LIS interface specification"** ke BioSystems atau
distributornya. Untuk analyzer BioSystems yang lebih baru (BA400) dokumen
serupa beredar dengan judul *"BA400 LIS Protocol — ASTM+HL7"*, yang
menunjukkan pabrikan ini memang memakai ASTM/HL7 — tetapi **jangan**
menganggap A15 memakai yang sama tanpa dokumennya sendiri.

---

## 3. MediGo URO — urinalisa (keluarga URIT US-500)

### Terverifikasi — dari manual pabrikan

Dokumennya ada: *"Appendix B Communication Protocol Description"*, untuk
**URIT Group Automatic Urine Sediment Analyzer**. Perhatikan judulnya: ini
manual **penganalisis urine**, bukan kimia klinik. Istilah *"dry chemistry"*
di dalamnya berarti kimia kering carik celup urine — bukan kimia klinik
serum. Memasangnya pada KIMIA-02 (BioSystems A15) akan salah alat.

| Hal | Nilai |
|---|---|
| Protokol | **HL7 v2.3.1** di atas **MLLP** |
| Kerangka | SB = `0x0B` (VT), EB = `0x1C` (FS), CR = `0x0D` |
| Jenis pesan | **ORU^R01**, *unsolicited* — alat aktif mengirim ke LIS |
| Encoding | **UNICODE** (MSH-18), bukan latin1 |
| ACK | satu byte `0x06`; *"compatible with non-return data"* |

Kerangka MLLP itu baku dan sudah ditangani `hl7.js` apa adanya.

**Arah koneksi.** *Unsolicited* berarti alat yang memulai pengiriman. Itu
petunjuk kuat — walau bukan bukti mutlak — bahwa alat berperan sebagai
penelepon, sehingga di sisi LIS transport-nya `tcp_server`. Nomor portnya
tidak disebut di lampiran ini.

### Bentuk pesannya

```
MSH|^~\&|[CompanyName]|[InstrName]|LIS|PC|[ResultTime]||ORU^R01|...|P|2.3.1||||||UNICODE
PID|[PatType]|[PatID]|[PatBarCode]|[PatBedCode]|[PatName]||[PatBirth]|[PatSex]
OBR|[SampleType]|[REQID]|[SampleID]|[CompanyName]^[InstrName]||[SampleTime]|[StartTime]|...
OBX|1|[ValueType]|[ItemID]|[ItemName]|[TestResult]|[Unit]|[ConsultValue]|[Flag]|||F|...
```

Yang menentukan pemetaan: **OBX-3 berbentuk `NNN^KODE`**, contoh `001^WBC`.
Yang menjadi identitas adalah **komponen pertama — nomornya**, bukan
singkatannya. `hl7.js` memang mengambil komponen pertama, jadi sudah cocok
tanpa perubahan.

OBX bertipe **ED** memuat gambar sedimen dalam base64. `hl7.js` sudah
melewatinya — kalau tidak, setiap sampel akan membanjiri database dengan
data gambar yang bukan hasil pemeriksaan.

### Diuji, bukan diandaikan

Pesan contoh dari lampiran B3.1 dijalankan apa adanya lewat
`hl7.keNormal()`. Hasilnya benar tanpa satu baris kode pun diubah:

```
sample_id : 000001            (OBR-3)
pasien    : Zhang San, M, 1981-10-11
results   : 001 / WBC / 0.4 / ul / 0.0-1.0 / N
            002 / RBC / 0.2 / ul / 0.0-1.0 / N
OBX tipe ED (gambar) dilewati
```

### Yang masih belum diketahui

**Nomor item selengkapnya.** Lampiran ini hanya memberi dua contoh,
`001 = WBC` dan `002 = RBC`. Daftar penuhnya tidak ada di sini. Karena itu
kode parameter tetap dipungut dari kiriman alat yang sebenarnya:

```
node simulator/temukanAlat.js --dengar --kode URIN-02
```

Keluarannya mencetak seluruh nomor item yang benar-benar dikirim, beserta
INSERT yang tinggal dilengkapi kode pemeriksaan LIS-nya.

### Dua hal yang perlu diputuskan sebelum mengaktifkan

**1. Alamat IP.** Layar alat menunjukkan `192.168.1.100` / gateway
`192.168.1.1` — alamat bawaan pabrik, terpisah sama sekali dari jaringan
Anda. DHCP-nya mati, jadi IP, Mask, dan Gateway diisi manual sesuai VLAN
laboratorium. Sebelum ini dibereskan, tidak ada percobaan yang bermakna.
Tab **Transmit data** adalah tempat host dan port LIS diisi.

**2. Worklist dua arah belum didukung untuk alat ini.** Manual B4.2
memerikan dialek permintaan order yang berbeda dari mana pun:

```
minta   <SB>QRD|[barcode]|[ID]|[posisi]|[waktu]<EB><CR>
jawab   <SB>R-QRD|[hasil]|[barcode]|[ID]|[posisi]|[waktu]<EB><CR>
        hasil: 0 = tidak ada, 1 = kimia kering saja,
               2 = sedimen saja, 3 = keduanya
```

Itu bukan QBP/QRY HL7 baku, dan bukan salah satu dari empat dialek yang
didukung `HL7_WORKLIST_REPLY` (auto / orm / rsp / mindray). Jadi:

- biarkan alat ini `unidirectional` — hasil tetap mengalir normal;
- `HL7_WORKLIST_REPLY` **harus tetap `off`** sampai dialek ini dibuat.

Ada batasan rancangan yang perlu diketahui di sini: `HL7_WORKLIST_REPLY`
bersifat **global, satu nilai untuk semua alat HL7**. BC-5000 memerlukan
`mindray`, alat ini memerlukan dialek yang belum ada. Keduanya tidak dapat
menyala bersamaan sampai setelan itu dijadikan per alat.

### Catatan ACK

Alat mengharapkan satu byte `0x06`, sementara `hl7.js` membalas pesan ACK
HL7 lengkap (`MSH` + `MSA|AA`). Manual menyebut alat ini *"compatible with
non-return data"*, jadi ia tetap bekerja apa pun balasannya — risikonya
kecil. Bila ternyata alat tampak menunggu atau mengulang kiriman, itulah
tersangka pertamanya, dan penanganannya menambah mode ACK per alat.

## Urutan pengerjaan

Satu alat pada satu waktu. Kalau tiga-tiganya diaktifkan sekaligus lalu ada
yang diam, tidak akan jelas yang mana.

1. **Jalankan migrasi** `database/06_alat_exias_a15_medigo.sql`. Ketiga alat
   terdaftar NONAKTIF, lengkap dengan pemetaan parameternya.

2. **MediGo — perbaiki alamat IP lebih dulu.** Tanpa itu, tidak ada percobaan
   apa pun yang bermakna.

3. **A15 — periksa menu Konfigurasi.** Jawab tiga pertanyaan di atas sebelum
   menyentuh middleware.

4. **EXIAS dan MediGo — temukan arah koneksinya:**

   ```
   cd middleware
   node simulator/temukanAlat.js --dengar
   ```

   Isi Host IP pada alat dengan alamat mesin middleware, lalu tekan kirim.
   Bila ada yang masuk, keluarannya menyebutkan port dan protokolnya
   sekaligus. Bila sunyi:

   ```
   node simulator/temukanAlat.js --pindai <ip-alat>
   ```

   Jalankan dari mesin **di segmen jaringan yang sama dengan alat** — dari
   segmen lain, ACL membuat semua port tampak tertutup padahal tidak.

5. **Isi Transport / Host / Port** di menu Alat Laboratorium, baru centang
   aktif.

6. **Satu sampel uji**, lalu buka layar **Hasil Belum Terpetakan**. Kode
   parameter pada migrasi adalah singkatan yang lazim, bukan hasil membaca
   manual ketiga alat. Kode yang meleset muncul di layar itu lengkap dengan
   kode aslinya — perbaiki dari menu Pemetaan Kode. Tebakan pada pemetaan
   aman justru karena kegagalannya terlihat, bukan tersimpan diam-diam di
   bawah pemeriksaan yang salah.

---

## Sumber

- EXIAS e|1 — brosur produk:
  <https://www.afmssc.com/images/AFMS/Documents_pdfs/EXIAS-e1-Brochure.pdf>
- EXIAS Medical — halaman produk e|1:
  <https://www.exias-medical.com/en/products/exias-maintenance-free-electrolyte-analyzer/>
- BioSystems A-15 — service manual:
  <https://manualmachine.com/biosystems/a15/7454264-service-manual/>
- BioSystems — halaman produk A15:
  <https://biosystems.global/ph-en/service/clinical-analysis/biochemistry-systems/analysers/a15>
- URIT Group — *Appendix B Communication Protocol Description* (berkas
  `US-500 LIS config.pdf` yang Anda kirimkan)
