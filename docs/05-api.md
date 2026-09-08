# Rujukan API

Seluruh endpoint berada di bawah `/api/v1`.

## Autentikasi

Setiap permintaan (kecuali `/ping`) memerlukan header:

```
X-API-Key: lis_xxxxxxxxxxxxxxxx
```

Kredensial dibuat lewat **Pengaturan → Kredensial API**, dengan cakupan:

| Cakupan | Untuk |
|---|---|
| `instrument` | Middleware alat |
| `khanza` | Konektor SIMRS |
| `admin` | Semua cakupan |

### Tanda tangan HMAC (dianjurkan)

Bila permintaan membawa body, sertakan:

```
X-Signature: <hex HMAC-SHA256 atas raw body memakai secret klien>
```

Contoh Node.js:

```js
const sig = crypto.createHmac('sha256', SECRET).update(body).digest('hex');
```

Verifikasi memakai secret **milik klien tersebut**, bukan secret bersama.
Kredensial bawaan hasil `database/02_seed_master.sql` tidak menyimpan
secret yang dapat dipakai HMAC — terbitkan kredensial baru lewat
Pengaturan atau `bin/install.php`.

### Pembatasan IP

Setiap kredensial dapat dibatasi ke daftar IP atau CIDR lewat kolom
**Batas IP**. Sangat dianjurkan untuk pemasangan lintas server.

---

## Bentuk jawaban

Seragam untuk seluruh endpoint:

```json
{
  "sukses": true,
  "pesan": "15 dari 16 hasil tersimpan",
  "data": { },
  "waktu": "2026-09-02T13:50:12+07:00"
}
```

| Status | Arti |
|---|---|
| 200 | Berhasil |
| 207 | Sebagian berhasil — jangan diulang |
| 401 | API key atau tanda tangan tidak sah |
| 403 | Cakupan atau IP tidak diizinkan |
| 404 | Data tidak ditemukan |
| 422 | Payload tidak lolos validasi |
| 500 | Kesalahan server |

Middleware memperlakukan 4xx selain 429 sebagai **permanen** dan tidak
mengulanginya; sisanya masuk antrian kirim ulang.

---

## Sistem

### `GET /api/v1/ping`

Tanpa autentikasi. Dipakai middleware untuk memastikan LIS hidup.

```json
{"sukses":true,"pesan":"LIS siap",
 "data":{"aplikasi":"LIS","versi":"1.0.0","database":"terhubung","zona":"Asia/Pontianak"}}
```

Mengembalikan 503 bila database tidak terjangkau.

---

## Alat laboratorium — cakupan `instrument`

### `GET /api/v1/instruments`

Konfigurasi seluruh alat aktif. Diambil middleware saat start dan setiap
kali muat ulang berkala.

```json
{"data":{"instruments":[{
  "id":1,"code":"HEMA-01","name":"Hematology Analyzer 1",
  "protocol":"astm","transport":"tcp_server","mode":"bidirectional",
  "encoding":"latin1",
  "tcp":{"host":"0.0.0.0","port":5100},
  "serial":{"path":null,"baudRate":9600,"dataBits":8,"stopBits":1,
            "parity":"none","flowControl":"none"}
}],"heartbeat_seconds":30}}
```

### `POST /api/v1/instruments/heartbeat`

```json
{"instruments":[{"code":"HEMA-01","status":"online","error":null}]}
```

`status`: `online`, `offline`, atau `error`. Memperbarui indikator pada
halaman Alat Laboratorium.

### `POST /api/v1/instruments/results`

Endpoint utama. Menerima satu payload atau kumpulan lewat `messages`.

```json
{
  "instrument_code": "HEMA-01",
  "protocol": "astm",
  "raw": "(sesi mentah untuk penelusuran)",
  "samples": [{
    "sample_id": "2609020001",
    "patient": { "id": "000123", "name": "BUDI SANTOSO", "sex": "M" },
    "collected_at": "2026-09-02 13:40:00",
    "results": [
      { "code": "HGB", "value": "6.4", "unit": "g/dL",
        "flags": "L", "status": "F", "completed_at": "2026-09-02 13:45:00" }
    ]
  }]
}
```

Jawaban:

```json
{"sukses":true,"pesan":"15 dari 16 hasil tersimpan","data":{
  "pesan_diproses":1,"total_hasil":16,"tersimpan":15,
  "rincian":[{"message_id":42,"status":"sebagian","total":16,"tersimpan":15,
    "detail":[{"sample_id":"2609020001","total":16,"tersimpan":15,
               "pesan":["Parameter belum dipetakan: KODE-BARU"]}]}]}}
```

Nilai `status` pada rincian:

| Nilai | Arti |
|---|---|
| `diproses` | Seluruh hasil tersimpan |
| `sebagian` | Sebagian tersimpan — sisanya masuk karantina |
| `tidak_cocok` | Sample ID tidak ditemukan; seluruh hasil dikarantina |
| `error` | Alat belum terdaftar atau payload tidak dikenali |

**Hasil tidak pernah dibuang diam-diam.** Yang tidak dapat diproses masuk
ke tabel `orphan_results` dan muncul di menu Hasil Belum Terpetakan.

### `GET /api/v1/worklist/{sampleId}?instrument=KODE`

Jawaban host query. Mengembalikan kode versi alat bila parameter
`instrument` disertakan, atau kode LIS bila tidak.

```json
{"sukses":true,"pesan":"15 pemeriksaan menunggu","data":{
  "sample_id":"2609020001","order_no":"LIS-260902-0001","lab_no":"2609020001",
  "priority":"S",
  "patient":{"id":"000123","name":"BUDI SANTOSO","sex":"L","birthdate":"1990-01-15"},
  "tests":["WBC","RBC","HGB","HCT","MCV","MCH","MCHC","RDW-CV","PLT","MPV",
           "NEU%","LYM%","MON%","EOS%","BAS%"]}}
```

`priority`: `S` untuk cito, `R` untuk rutin. Mengembalikan 404 dengan
`tests: []` bila sampel tidak dikenal.

### `GET /api/v1/instruments/{kode}/worklist?ack=1`

Antrian worklist yang menunggu didorong ke alat dua arah. Dengan `ack=1`,
entri langsung ditandai terkirim.

### `POST /api/v1/instruments/messages`

Menyimpan potongan komunikasi mentah tanpa memproses hasil — dipakai untuk
merekam sesi saat penelusuran masalah.

---

## SIMRS Khanza — cakupan `khanza`

### `POST /api/v1/khanza/orders`

Satu order, atau beberapa lewat `orders`.

```json
{"orders":[{
  "noorder":"LB0001","no_rawat":"2026/09/02/000045",
  "no_rkm_medis":"000456","nm_pasien":"SITI AMINAH",
  "jk":"P","tgl_lahir":"1985-07-20",
  "tgl_permintaan":"2026-09-02","jam_permintaan":"13:40:00",
  "dokter_perujuk":"dr. Rina Sp.PD","status":"ranap",
  "nama_ruang":"Melati","informasi_tambahan":"CITO",
  "diagnosa_klinis":"Demam tifoid?",
  "detail":[{"kd_jenis_prw":"LK001","id_template":101}]
}]}
```

Jawaban per order:

```json
{"data":[{"noorder":"LB0001","sukses":true,"pesan":"Order berhasil dibuat.",
  "lis_order_id":2,"lis_no_order":"LIS-260902-0002","no_lab":"2609020002",
  "barcode":["2609020002"],"tidak_dipetakan":[]}]}
```

Bersifat **idempoten**: `noorder` yang sama tidak membuat order ganda.

Bila `tidak_dipetakan` terisi, pemeriksaan tersebut dilewati. Bila tidak
ada satu pun yang terpetakan, order ditolak agar dapat dikirim ulang
setelah pemetaan dilengkapi.

### `POST /api/v1/khanza/orders/batal`

```json
{"noorder":"LB0001","alasan":"Dibatalkan dokter"}
```

Menolak dengan 409 bila order sudah dirilis.

### `GET /api/v1/khanza/hasil?limit=20`

Hasil terverifikasi yang belum pernah terkirim — untuk konektor yang
memakai pola tarik.

### `POST /api/v1/khanza/hasil/ack`

```json
{"lis_order_id":[2,3]}
```

atau

```json
{"noorder":["LB0001","LB0002"]}
```

Menandai hasil sudah tersimpan di Khanza.

### `GET /api/v1/khanza/status`

```json
{"data":{"order_khanza_hari_ini":12,"menunggu_kirim":2,
         "antrian":0,"template_belum_dipetakan":5}}
```

---

## API konektor Khanza

Dipasang di server SIMRS, bukan bagian dari LIS. Autentikasi memakai
`X-API-Key` yang dikonfigurasi pada `khanza-connector/config.php`.

| Endpoint | Metode | Isi |
|---|---|---|
| `/api/ping.php` | GET | Kesehatan konektor dan keberadaan tabel bridging |
| `/api/orders.php?limit=50` | GET | Permintaan yang belum diambil |
| `/api/orders.php?aksi=ack` | POST | Tandai order sudah diambil |
| `/api/results.php` | POST | Tulis hasil ke `detail_hasil_lab` |
| `/api/templates.php` | GET | Ekspor master pemeriksaan |

---

## Contoh lengkap

```bash
BASE=http://localhost/LIS/public
KEY=lis_xxxxxxxxxxxx
SECRET=xxxxxxxxxxxxxxxx

# Kesehatan
curl -s $BASE/api/v1/ping

# Konfigurasi alat
curl -s -H "X-API-Key: $KEY" $BASE/api/v1/instruments

# Worklist untuk sebuah barcode
curl -s -H "X-API-Key: $KEY" \
  "$BASE/api/v1/worklist/2609020001?instrument=HEMA-01"

# Kirim hasil dengan tanda tangan
BODY='{"instrument_code":"HEMA-01","protocol":"astm","samples":[
  {"sample_id":"2609020001","results":[
    {"code":"HGB","value":"6.4","unit":"g/dL","flags":"L","status":"F"}]}]}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)

curl -s -X POST $BASE/api/v1/instruments/results \
  -H "X-API-Key: $KEY" -H "X-Signature: $SIG" \
  -H "Content-Type: application/json" -d "$BODY"
```
