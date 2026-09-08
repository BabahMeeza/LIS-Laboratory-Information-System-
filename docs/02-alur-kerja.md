# Alur Kerja Laboratorium

## Ringkasan status

```
   ordered ──► collected ──► received ──► in_progress ──► resulted ──► verified ──► released
      │                          │                                        │
      └──► cancelled             └──► (spesimen rejected)                 └──► corrected
```

Status order dihitung ulang otomatis dari status item-itemnya setiap kali
ada perubahan — tidak pernah diatur manual. Aturannya di
`OrderService::segarkanStatus()`:

| Kondisi | Status order |
|---|---|
| Semua item terverifikasi | `verified` |
| Semua item punya hasil | `resulted` |
| Sebagian item punya hasil | `in_progress` |
| Ada spesimen diterima | `received` |
| Ada spesimen diambil | `collected` |
| Selain itu | `ordered` |

`released` dan `cancelled` bersifat final dan tidak dihitung ulang.

---

## 1. Pendaftaran order

**Menu: Order Pemeriksaan → Order Baru**
**Peran: sampling, analis, verifikator, admin**

Pilih pasien (atau daftarkan baru), isi asal pasien dan prioritas, lalu
centang pemeriksaan atau paket.

Saat disimpan, sistem membuat:

- Nomor order internal, mis. `LIS-260902-0001`
- Nomor laboratorium harian, mis. `2609020001`
- **Satu spesimen per jenis tabung**, masing-masing dengan barcode sendiri

Contoh nyata: order Darah Lengkap + GDS + Kalium + Natrium menghasilkan
tiga tabung:

| Barcode | Tabung | Untuk |
|---|---|---|
| `2609020001` | EDTA (ungu) | Darah lengkap |
| `2609020002` | NaF (abu-abu) | Glukosa |
| `2609020003` | Heparin (hijau) | Elektrolit |

Barcode inilah yang dibaca analyzer. Cetak labelnya dari tombol
**Cetak Label** pada halaman order.

### Order dari SIMRS Khanza

Order yang datang dari Khanza masuk lewat jalur yang sama, sehingga
memperoleh nomor lab dan barcode yang sama bentuknya. Pasien dibuat
otomatis bila belum ada, dicocokkan lewat `no_rkm_medis`. Prioritas CITO
dikenali dari kolom `informasi_tambahan`.

---

## 2. Pengambilan dan penerimaan spesimen

**Menu: Penerimaan Sampel**
**Peran: sampling, analis**

Layar ini dirancang untuk dipakai dengan **scanner barcode dan tanpa
menyentuh mouse**: kursor otomatis berada di kolom pemindaian, dan setiap
pemindaian menghasilkan bunyi konfirmasi berbeda untuk berhasil dan gagal.

Sistem menolak pemindaian ganda dan barcode tak dikenal dengan pesan yang
jelas, bukan diam-diam.

### Penolakan spesimen

Tombol **Tolak** pada baris spesimen. Wajib mengisi kondisi (lisis, beku,
volume kurang, ikterik, lipemik, identitas tidak sesuai) dan alasan.

Data ini bukan sekadar catatan: seluruh penolakan muncul pada
**Laporan → Rekap** sebagai indikator mutu pra-analitik. Bila satu ruangan
sering menyumbang sampel lisis, angkanya akan terlihat.

---

## 3. Pengerjaan

**Menu: Worklist**
**Peran: analis**

Menampilkan pemeriksaan yang spesimennya sudah diterima dan belum ada
hasilnya, dikelompokkan per order. Dapat disaring per kategori, per alat,
dan per prioritas. Order yang melewati target TAT ditandai merah.

**Untuk alat dua arah**, pilih beberapa order lalu tekan
**Kirim Worklist ke Alat** — daftar pemeriksaan masuk antrian dan didorong
middleware ke analyzer, sehingga operator tidak perlu mengetik ulang
permintaan di alat.

Alat yang mendukung host query bahkan tidak memerlukan langkah ini: alat
memindai barcode tabung, bertanya ke LIS, dan LIS menjawab dengan daftar
pemeriksaan untuk barcode tersebut.

---

## 4. Hasil masuk

### Dari alat (otomatis)

Middleware mengirim hasil ke LIS, yang kemudian:

1. Mencocokkan sample ID dengan barcode spesimen
2. Memetakan kode parameter alat ke pemeriksaan LIS
3. **Menghitung ulang flag** terhadap nilai rujukan pasien
4. Menjalankan delta check terhadap hasil sebelumnya
5. Menyimpan hasil dengan status `final`

Flag dari alat tetap disimpan pada kolom terpisah (`flag_alat`) untuk
pembanding, tetapi **tidak dipakai menilai** — lihat
[docs/01-arsitektur.md](01-arsitektur.md#perjalanan-sebuah-hasil).

### Manual

**Menu: Worklist → Entri Hasil**, atau dari halaman order.

Layar entri menampilkan nilai rujukan yang sudah disesuaikan dengan jenis
kelamin dan umur pasien, ditambah dua hasil sebelumnya sebagai pembanding.
Nilai di luar rujukan berubah warna saat diketik; nilai kritis diberi latar
merah. Tekan **Enter** untuk berpindah ke kolom berikutnya.

Bila tanggal lahir atau jenis kelamin pasien belum terisi, layar
menampilkan peringatan — karena rujukan yang dipakai menjadi asumsi.

---

## 5. Verifikasi

**Menu: Verifikasi**
**Peran: verifikator (dokter penanggung jawab), admin**

Antrian diurutkan berdasarkan prioritas CITO, lalu jumlah nilai kritis,
lalu umur order. Setiap baris menampilkan berapa hasil abnormal, kritis,
dan yang ditandai delta check.

Layar detail menampilkan seluruh hasil beserta pembanding hasil sebelumnya.
Verifikator dapat mencentang sebagian atau memverifikasi semuanya.

### Gerbang nilai kritis

**Verifikasi ditahan selama masih ada nilai kritis yang belum tercatat
pelaporannya.** Pesan yang muncul menyebutkan pemeriksaan dan nilainya.

Catat pelaporan lewat kolom di Dashboard atau di baris hasil terkait,
dengan menuliskan kepada siapa hasil dilaporkan. Sistem mencatat waktunya,
dan selisih waktu antara hasil keluar dan pelaporan muncul pada
**Laporan → Register Nilai Kritis**.

### Delta check

Bila hasil berubah lebih dari ambang tertentu (bawaan 30%) dibandingkan
hasil terverifikasi terakhir pasien dalam 7 hari, hasil ditandai `Δ`.
Ini bukan penahan, melainkan penanda agar verifikator memeriksa
kemungkinan tertukar sampel atau kesalahan analitik.

Ambang dan rentang harinya diatur di **Pengaturan Sistem**.

---

## 6. Rilis dan pencetakan

Setelah seluruh pemeriksaan terverifikasi, tekan **Rilis Hasil**.

Rilis:

- Menandai order selesai dan menghentikan hitungan TAT
- Membuat lembar hasil dapat dicetak dalam bentuk final
- Memicu pengiriman ke SIMRS Khanza bila diatur "kirim saat rilis"

Bila sebagian pemeriksaan masih menunggu (misalnya kultur yang butuh tiga
hari), centang **rilis sebagian** untuk menerbitkan yang sudah ada.

Lembar hasil memuat kop fasilitas, identitas pasien, barcode, hasil
dikelompokkan per kategori dengan flag dan nilai rujukan, serta ruang tanda
tangan dokter penanggung jawab. Cetak dari peramban ke kertas A4.

Pratinjau sebelum verifikasi tersedia lewat `?draft=1` dan diberi tanda air
**DRAFT**.

---

## 7. Koreksi setelah rilis

Hasil yang sudah dirilis tidak dapat diubah lewat entri biasa. Gunakan
**Riwayat Hasil → Koreksi Hasil**, yang mewajibkan alasan.

Koreksi:

- Menandai hasil `corrected`
- Mencatat nilai lama, nilai baru, alasan, dan pelakunya secara permanen
- Memberi tanda pada lembar hasil bahwa nilai tersebut adalah koreksi
- Perlu dikirim ulang ke Khanza dari menu Integrasi → Log

---

## Kontrol mutu

**Menu: Kontrol Mutu**

Daftarkan bahan kontrol beserta lot, level, mean, dan SD. Setiap nilai QC
yang dimasukkan dievaluasi terhadap aturan Westgard:

| Aturan | Arti | Tindakan |
|---|---|---|
| `1-2s` | satu titik melewati 2 SD | peringatan, amati run berikutnya |
| `1-3s` | satu titik melewati 3 SD | **tolak** |
| `2-2s` | dua titik berurutan sesisi melewati 2 SD | **tolak** |
| `R-4s` | selisih dua titik berurutan melebihi 4 SD | **tolak** |
| `4-1s` | empat titik berurutan sesisi melewati 1 SD | **tolak** |
| `10x` | sepuluh titik berurutan pada sisi mean yang sama | **tolak** |

Pelanggaran aturan penolakan menampilkan peringatan tegas: hentikan
pemeriksaan pasien untuk parameter tersebut, lakukan tindakan perbaikan,
dan catat tindakannya. Grafik Levey-Jennings menampilkan 60 titik terakhir
dengan garis mean dan ±1/2/3 SD.

Mean dan SD sebaiknya diverifikasi sendiri dengan minimal 20 kali
pengukuran pada kondisi rutin, bukan langsung memakai angka insert
pabrikan.

---

## Laporan

| Laporan | Isi |
|---|---|
| **Rekap** | Volume order, sebaran asal pasien, pemeriksaan terbanyak, penolakan spesimen |
| **Analisis TAT** | Rata-rata TAT, per prioritas, dan kepatuhan target per pemeriksaan |
| **Register Nilai Kritis** | Seluruh nilai kritis beserta bukti pelaporan dan selang waktunya — dokumen akreditasi |
| **Produktivitas** | Aktivitas per petugas dan per alat, serta proporsi hasil otomatis vs manual |
| **Ekspor CSV** | Data mentah hasil untuk pengolahan lanjutan |
