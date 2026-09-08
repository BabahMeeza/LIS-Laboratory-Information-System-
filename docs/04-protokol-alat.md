# Menghubungkan Alat Laboratorium

## Menentukan cara sambung

Sebelum apa pun, cari tahu tiga hal dari manual analyzer atau teknisi
pemasangnya:

1. **Protokol** — ASTM, HL7, atau keluaran teks polos
2. **Transport** — Ethernet (TCP) atau serial (RS232)
3. **Peran** — alat menyambung ke LIS, atau LIS yang menyambung ke alat

Pemetaan ke pengaturan LIS:

| Kondisi alat | Transport di LIS |
|---|---|
| Alat punya kolom "Host IP" dan "Host Port" | `tcp_server` — LIS menunggu, alat menyambung |
| Alat berperan sebagai server, punya IP sendiri | `tcp_client` — LIS yang menyambung |
| Alat hanya punya kabel serial DB9 | `serial` |
| Alat memakai konverter Serial-to-Ethernet | `tcp_client` atau `tcp_server`, sesuai mode konverter |

`tcp_server` adalah kasus paling umum untuk analyzer klinik modern.

---

## Panduan cepat per merek

Nilai di bawah adalah titik awal yang lazim, bukan pengganti manual alat.

| Merek / model | Protokol | Transport | Catatan |
|---|---|---|---|
| Mindray BC-5150, BC-20s, BC-30s | ASTM | tcp_server | Menu Setup → Communication → LIS |
| Mindray BS-240, BS-430 | HL7 atau ASTM | tcp_server | Sebagian firmware memakai HL7 |
| Sysmex XN, XP-300, KX-21N | ASTM | tcp_server / serial | KX-21N umumnya serial 9600 8N1 |
| Roche Cobas c111, c311 | HL7 (LIS2-A2) | tcp_server | Balasan ACK wajib, sudah ditangani |
| Abbott Architect / Alinity | HL7 | tcp_server | |
| Erba XL series | ASTM | tcp_server | |
| Getein 1100 / 1160 | ASTM sederhana | serial / tcp | Sering tanpa rekaman P |
| Dirui H-500, FUS series | ASTM | serial | 9600 8N1 lazim |
| Biosystems A15 / A25 | ASTM | serial | |
| Alat lama tanpa protokol | raw | serial | Keluaran printer, lihat bagian Raw |

Bila merek Anda tidak ada di daftar: mulai dengan ASTM + tcp_server,
jalankan middleware dengan `LOG_LEVEL=trace`, lalu amati byte yang datang.
Bentuk pesannya akan langsung terbaca.

---

## ASTM E1381 / E1394

Standar yang paling luas dipakai analyzer klinik.

### Lapisan tautan (E1381)

```
Alat  → <ENQ>                                    minta saluran
LIS   → <ACK>                                    saluran diberikan
Alat  → <STX> FN teks <ETX> C1 C2 <CR><LF>       satu bingkai
LIS   → <ACK>                                    bingkai diterima
   ⋮
Alat  → <EOT>                                    sesi selesai
```

- `FN` nomor bingkai `0`–`7`, berputar
- Checksum: jumlah seluruh byte **setelah** `<STX>` sampai dan termasuk
  `<ETX>`/`<ETB>`, modulo 256, dua digit heksadesimal huruf besar
- `<ETB>` menandai bingkai lanjutan untuk rekaman yang lebih panjang dari
  240 karakter; `<ETX>` menandai akhir rekaman
- Bingkai dengan checksum salah dijawab `<NAK>`, dan alat mengirim ulang

Implementasinya di `middleware/src/protocols/astm.js`, dengan 18 uji
otomatis yang mencakup checksum, penomoran bingkai, perakitan lintas
`<ETB>`, kedatangan byte demi byte, dan tabrakan `<ENQ>`.

### Lapisan pesan (E1394)

```
H|\^&|||SimAnalyzer^1.0|||||||P|1|20260902134000
P|1||000123||BUDI^SANTOSO||19900115|M
O|1|2609020001|2609020001|^^^ALL|R|20260902134000
R|1|^^^HGB^1|6.4|g/dL||L||F||op||20260902134500|Analyzer
L|1|N
```

| Rekaman | Isi | Field penting |
|---|---|---|
| `H` | Header | Pembatas pada `H|\^&` |
| `P` | Pasien | 5 nama, 7 tanggal lahir, 8 jenis kelamin |
| `O` | Order | **3 sample ID**, 4 kode pemeriksaan |
| `R` | Hasil | 2 kode, 3 nilai, 4 satuan, 6 flag, 12 waktu selesai |
| `C` | Komentar | Menempel pada hasil sebelumnya |
| `Q` | Host query | 2 sample ID yang ditanyakan |
| `L` | Penutup | |

Kode pemeriksaan pada `R|2` berbentuk `^^^HGB^1`; komponen keempat adalah
kodenya. Parser menerima juga bentuk `^^^HGB` dan `HGB` polos.

### Host query dua arah

Bila alat memindai barcode tabung lalu bertanya ke LIS:

```
Alat  → Q|1|^2609020001||ALL||||||||O
Alat  → <EOT>
LIS   → H|\^&|||LIS^1.0|||||||P|1|20260902134000
LIS   → P|1||000123||BUDI SANTOSO||19900115|M
LIS   → O|1|2609020001||^^^WBC\^^^HGB\^^^PLT|S|20260902134000|||||A||||1
LIS   → L|1|F
```

Bila sampel tidak dikenal, LIS menjawab `L|1|I` ("no information"), dan
alat akan menjalankan profil bawaannya.

**Catatan penting soal setengah dupleks.** ASTM hanya mengizinkan satu
pihak menguasai saluran pada satu waktu. LIS menahan jawabannya sampai
alat mengirim `<EOT>`. Mengabaikan aturan ini membuat kedua pihak saling
menunggu — kebuntuan yang justru ditemukan saat pengujian integrasi
sistem ini, dan sekarang dicegah beserta uji regresinya.

---

## HL7 v2.x di atas MLLP

Dipakai analyzer Roche, Abbott, Beckman, dan sebagian Mindray.

### Kerangka MLLP

```
<VT> pesan HL7 <FS><CR>
VT = 0x0B, FS = 0x1C, CR = 0x0D
```

### Pesan hasil (ORU^R01)

```
MSH|^~\&|SimChem|LAB|LIS|LAB|20260902134000||ORU^R01|MSG0001|P|2.4
PID|1||000123||BUDI^SANTOSO||19900115|M
OBR|1|PLC001|2609020001|PANEL^Kimia Klinik|R|20260902134000
OBX|1|NM|K^Kalium||6.9|mmol/L|3.5-5.1|HH|||F|||20260902134500
```

| Segmen | Field | Isi |
|---|---|---|
| `MSH` | 9 | Jenis pesan |
| `PID` | 3 nomor pasien, 5 nama, 7 lahir, 8 jenis kelamin |
| `OBR` | **3 sample ID** (filler), 2 cadangan (placer), 7 waktu ambil |
| `OBX` | 3 kode, 5 nilai, 6 satuan, 7 rujukan, 8 flag, 11 status |
| `SPM` | 2 | Sample ID pada varian OUL^R22 |
| `NTE` | 3 | Komentar |

LIS membalas setiap pesan dengan `ACK` berisi `MSA|AA|<control id>`.
Banyak analyzer menahan antrian kirimnya bila ACK tidak datang.

Pembatas dibaca dari `MSH-1` dan `MSH-2`, bukan diasumsikan — varian yang
memakai pembatas tidak baku tetap terbaca.

### Host query HL7

Pesan `QRY^Q02` (QRD-8) atau `QBP^Q11` (QPD-3) dijawab dengan `ORM^O01`
berisi satu pasangan `ORC`/`OBR` per pemeriksaan.

Format pesan unduh order berbeda antar pabrikan. Bila analyzer Anda
mengharapkan bentuk lain (`DSR^Q03`, `RSP^K11`), sesuaikan
`bangunOrmWorklist()` di `middleware/src/protocols/hl7.js` — seluruh data
yang diperlukan sudah tersedia pada objek worklist.

---

## Serial RS232

### Persiapan

```bash
cd middleware
npm install serialport
```

Menemukan nama port:

| Sistem | Cara |
|---|---|
| Windows | Device Manager → Ports (COM & LPT) → mis. `COM3` |
| macOS | `ls /dev/tty.*` → mis. `/dev/tty.usbserial-1410` |
| Linux | `ls /dev/ttyUSB* /dev/ttyS*` |

Pada Linux, pengguna yang menjalankan middleware harus masuk grup `dialout`:

```bash
sudo usermod -a -G dialout $USER    # perlu logout-login
```

### Parameter

Nilai lazim: **9600 baud, 8 data bits, no parity, 1 stop bit** (9600 8N1),
tanpa flow control. Parameter ini **harus sama persis** dengan pengaturan
di alat. Salah satu saja berbeda menghasilkan karakter acak, bukan pesan
kosong — bila log `trace` menampilkan sampah, curigai baud rate lebih dulu.

### Kabel

Sebagian besar analyzer memerlukan **kabel null modem** (TX dan RX
bersilang), bukan kabel serial lurus. Bila alat tersambung tetapi tidak
ada satu byte pun masuk, ini penyebab tersering.

---

## Protokol raw

Untuk alat yang hanya mencetak baris teks:

```
SAMPLEID;KODE;NILAI;SATUAN;FLAG
2609020001;HGB;6.4;g/dL;L
```

Pemisah `;`, `,`, `|`, atau tab dikenali otomatis. Baris berawalan `#`
diabaikan. Karena tidak ada penanda akhir pesan, hasil dikirim setelah
saluran diam 3 detik.

Bila tata letak alat Anda berbeda, sesuaikan `keNormal()` di
`middleware/src/protocols/raw.js` — hanya fungsi itu yang perlu diubah.

---

## Pemetaan parameter

Kode yang dipakai analyzer jarang sama dengan kode LIS:

| Kode alat | Pemeriksaan LIS |
|---|---|
| `HGB` | `HB` — Hemoglobin |
| `WBC` | `WBC` — Leukosit |
| `NEU%` | `NEUT` — Neutrofil |
| `RDW-CV` | `RDW` — RDW-CV |

Buka **Alat Laboratorium → Pemetaan** untuk mengisinya. Kode yang belum
dikenal **muncul sendiri di layar ini** begitu alat pertama kali
mengirimkannya, sehingga tidak perlu menebak dari manual.

Kolom **Faktor** mengalikan nilai bila satuan alat berbeda dari satuan LIS
(mis. alat mengirim `g/L`, LIS memakai `g/dL` → faktor `0.1`).

Centang **Abaikan** untuk parameter yang memang tidak ingin disimpan
(mis. penanda internal alat), agar tidak terus muncul sebagai kode baru.

Setelah pemetaan dilengkapi, pesan yang tadinya gagal dapat diproses ulang
dari **Alat Laboratorium → Log Komunikasi → Detail → Proses Ulang**.
Hasil yang sudah diverifikasi tidak akan tertimpa.

---

## Menelusuri masalah koneksi

### Langkah pertama: hidupkan trace

Pada `middleware/.env`:

```
LOG_LEVEL=trace
```

Jalankan ulang middleware. Setiap byte akan tercetak dalam bentuk terbaca:

```
[HEMA-01] << <ENQ>
[HEMA-01] >> <ACK>
[HEMA-01] << <STX>1H|\^&|||Analyzer^1.0<CR><ETX>C7<CR><LF>
[HEMA-01] >> <ACK>
```

Ini biasanya langsung menjawab pertanyaan apakah masalahnya di kabel,
protokol, atau pemetaan.

### Gejala umum

| Gejala | Sebab tersering |
|---|---|
| Tidak ada byte sama sekali | Kabel salah (butuh null modem), port salah, atau alat belum dikonfigurasi mengirim ke LIS |
| Karakter acak | Baud rate atau parity tidak cocok |
| `<ENQ>` datang tetapi tidak ada bingkai | Alat menunggu `<ACK>` yang tidak sampai — periksa arah komunikasi |
| Bingkai dijawab `<NAK>` terus | Checksum tidak cocok; sebagian alat lama memakai varian tanpa checksum |
| Hasil masuk tetapi tidak tersimpan | Sample ID tidak cocok, atau parameter belum dipetakan — lihat "Hasil Belum Terpetakan" |
| `Alat dengan kode "X" belum terdaftar` | `kode` alat di LIS berbeda dengan yang dikirim middleware |

### Uji tanpa alat

```bash
cd middleware
npm run simulate:astm -- --query    # ASTM lengkap dengan host query
npm run simulate:hl7 -- --query     # HL7 dengan query worklist
npm run simulate:astm -- --port 5300 --sample 2609020005
```

Simulator melakukan sesi protokol yang sah sepenuhnya, termasuk checksum
yang benar, sehingga cocok untuk memastikan sisi LIS sudah siap sebelum
alat sungguhan disambungkan.

### Memeriksa status middleware

```bash
curl http://127.0.0.1:9701/health
```

Menampilkan status tiap alat, jumlah pesan diterima, waktu data terakhir,
dan jumlah berkas yang masih menunggu di spool.
