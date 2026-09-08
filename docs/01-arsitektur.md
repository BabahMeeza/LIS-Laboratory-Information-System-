# Arsitektur

## Gambaran

```
┌──────────────┐        ASTM / HL7 / serial        ┌────────────────────┐
│   Analyzer   │◄─────────────────────────────────►│    Middleware      │
│ (hematologi, │   TCP 5100/5200 atau RS232        │    Node.js         │
│  kimia, dll) │                                   │  (gateway alat)    │
└──────────────┘                                   └─────────┬──────────┘
                                                             │ REST + API key
                                                             │ (+ HMAC opsional)
                                                             ▼
┌──────────────┐      REST + API key       ┌──────────────────────────────┐
│   Konektor   │◄─────────────────────────►│            LIS               │
│    Khanza    │                           │      PHP + MySQL             │
│  (di server  │                           │  ┌────────────────────────┐  │
│    SIMRS)    │                           │  │ Web UI  │  API v1      │  │
└──────┬───────┘                           │  ├────────────────────────┤  │
       │ SQL                               │  │ Services (logika lab)  │  │
       ▼                                   │  ├────────────────────────┤  │
┌──────────────────┐                       │  │ db_lis (MySQL)         │  │
│ sik_bridging_lab │                       │  └────────────────────────┘  │
│  (Khanza)        │                       └──────────────────────────────┘
└──────────────────┘
```

Tiga komponen, tiga proses terpisah. Masing-masing dapat dimatikan tanpa
merusak yang lain — sifat ini disengaja dan diuji.

---

## Mengapa dipisah seperti ini

### Middleware terpisah dari LIS

Koneksi analyzer bersifat **stateful dan berumur panjang**: soket TCP atau
port serial harus tetap terbuka berjam-jam, dan sesi ASTM berlangsung
lintas puluhan paket. PHP di bawah Apache tidak cocok untuk itu — setiap
permintaan HTTP adalah proses baru yang mati begitu selesai.

Node.js menangani hal ini secara alami. Pemisahan ini juga berarti:

- Middleware dapat dijalankan di komputer lain yang dekat dengan alat,
  misalnya PC di ruang analyzer, sementara LIS berada di server
- Me-restart Apache tidak memutus koneksi alat
- Menambah alat tidak memerlukan perubahan pada LIS

### Konektor terpisah dari LIS

LIS **tidak pernah** membuka koneksi database ke SIMRS Khanza. Seluruh
pertukaran data lewat HTTP + API key. Konsekuensinya:

- LIS boleh berada di server berbeda dari SIMRS
- Perubahan skema Khanza cukup ditangani di satu berkas konektor
- Kredensial database Khanza tidak pernah tersimpan di LIS
- Bila SIMRS mati, LIS tetap melayani; hasil masuk antrian kirim ulang

---

## Lapisan di dalam LIS

```
public/index.php          Front controller — satu-satunya berkas yang terekspos
   │
   ├── App\Core\App       Kernel: routing, middleware, penanganan galat
   │
   ├── App\Controllers    Modul web (order, spesimen, hasil, verifikasi, …)
   ├── App\Api\V1         Endpoint REST untuk middleware dan konektor
   │
   ├── App\Services       Logika laboratorium — bagian yang paling penting:
   │     ├── OrderService          penomoran, pembuatan order & spesimen
   │     ├── ResultProcessor       pemrosesan hasil dari alat
   │     ├── ReferenceRangeService pemilihan rujukan, flag, delta check
   │     ├── KhanzaService         integrasi dua arah dengan SIMRS
   │     └── PatientService        pencocokan dan pembuatan pasien
   │
   └── App\Core           Infrastruktur: Database, Auth, Router, Barcode, …
```

Aturan yang dipegang: **logika klinis tidak boleh berada di controller.**
Controller hanya menerjemahkan HTTP menjadi panggilan service dan
sebaliknya. Ini yang membuat pemrosesan hasil dari alat (lewat API) dan
entri manual (lewat web) memakai aturan flag, rujukan, dan delta check
yang sama persis — bukan dua implementasi yang bisa menyimpang.

---

## Perjalanan sebuah hasil

Contoh nyata: analyzer hematologi mengirim Hb 6,4 g/dL.

```
1. Analyzer                 R|3|^^^HGB^1|6.4|g/dL||L||F
                                    │
2. Middleware               parser ASTM → payload ternormalisasi
   (protocols/astm.js)      { code:"HGB", value:"6.4", flags:"L" }
                                    │
3. Middleware               spool.simpan()  ← ditulis ke disk LEBIH DULU
   (spool.js)                                 agar tidak hilang bila LIS mati
                                    │
4. Middleware               POST /api/v1/instruments/results
                                    │
5. LIS ResultProcessor      cari sample 2609020001 → order #1
                            petakan HGB → pemeriksaan HB
                                    │
6. LIS ReferenceRange       pasien laki-laki, 36 tahun
                            → rujukan 13,2–17,3 ; kritis ≤ 7,0
                            → 6,4 berada di bawah ambang kritis
                                    │
7. LIS                      flag = LL, is_kritis = 1
                            (flag dari alat "L" disimpan terpisah
                             sebagai flag_alat, tidak dipakai menilai)
                                    │
8. LIS                      hasil tersimpan, status = final
                            verifikasi DITAHAN sampai pelaporan dicatat
                                    │
9. Verifikator              catat pelaporan ke DPJP → verifikasi → rilis
                                    │
10. KhanzaService           hasil dikirim ke detail_hasil_lab
```

Langkah 6 dan 7 adalah inti nilai sistem ini: **penilaian normal/abnormal
dilakukan LIS memakai rujukan laboratorium dan data pasien**, bukan
diambil begitu saja dari analyzer yang tidak tahu umur dan jenis kelamin
pasien.

---

## Ketahanan

| Yang mati | Yang terjadi |
|---|---|
| LIS / Apache / MySQL | Middleware tetap menerima hasil dan menyimpannya ke folder `spool/`. Begitu LIS hidup, antrian terkirim otomatis. Nol kehilangan data — diuji. |
| Middleware | Alat tidak dapat mengirim. Sebagian analyzer menyimpan hasil di memorinya dan mengirim ulang; sisanya perlu dikirim ulang manual dari alat. |
| SIMRS Khanza | Hasil masuk `khanza_sync_log` berstatus `antri` dengan backoff eksponensial (5, 10, 20 menit… maksimum 8 jam). Diproses ulang oleh `bin/sync_khanza.php`. |
| Jaringan ke alat | Transport `tcp_client` dan `serial` menyambung ulang otomatis dengan jeda meningkat. `tcp_server` cukup menunggu alat menyambung kembali. |

---

## Keputusan rancangan yang perlu diketahui

**Tanpa Composer, tanpa framework.** Sistem ini dipasang di rumah sakit
yang server-nya sering tidak punya akses internet dan dikelola staf IT
umum, bukan pengembang PHP. Satu folder yang disalin dan langsung jalan
lebih berharga daripada kerapian dependensi.

**Barcode dibuat sendiri sebagai SVG.** Code 128 diimplementasikan di
`app/Core/Barcode.php` tanpa ekstensi GD dan tanpa pustaka luar, sehingga
label dapat dicetak dari XAMPP apa adanya. Tabel polanya diverifikasi
otomatis oleh `bin/selftest.php`.

**Middleware tanpa dependensi npm.** `npm install` hanya diperlukan bila
memakai alat serial. Ini menyingkirkan seluruh kelas masalah pemasangan
di jaringan rumah sakit yang tertutup.

**Sample ID adalah barcode tabung, bukan nomor order.** Satu order dapat
memerlukan beberapa tabung (EDTA untuk hematologi, NaF untuk glukosa,
heparin untuk elektrolit). Setiap tabung punya barcode sendiri, sehingga
hasil selalu dapat ditautkan ke spesimen yang benar. Bila alat mengirim
nomor lab alih-alih barcode tabung, spesimen sengaja **tidak ditebak** —
penautan diambil dari item order, bukan dari salah satu tabung sembarang.

**Pemetaan parameter mengisi diri sendiri.** Saat alat mengirim kode yang
belum dikenal, kode itu langsung muncul di layar Pemetaan dengan status
kosong, dan hasilnya dikarantina di "Hasil Belum Terpetakan" — tidak
hilang. Setelah dipetakan, pesan dapat diproses ulang dari menu Log.
