'use strict';

/**
 * Protokol BioSystems A15 — pertukaran lewat BERKAS TEKS, bukan soket.
 *
 * Sumber: "A15 — 3.4 Additional technical information, 3.4.1 LIMS
 * Communications" (manual pabrikan, halaman 1–5).
 *
 * A15 tidak berbicara ASTM maupun HL7. Ia menyalin berkas teks datar ke
 * folder pada PC-nya sendiri:
 *
 *   Import\import.txt              worklist masuk ke alat
 *   Export\online(...)_n.txt       hasil keluar, bertambah tiap hasil baru
 *   Import\Errors.txt              kegagalan impor
 *
 * BENTUK BARIS EKSPOR — dipisah TAB (ASCII 09), satu baris per pemeriksaan:
 *
 *   PAC1234 <TAB> ALT <TAB> SER <TAB> 121,4717 <TAB> U/L <TAB> 19/09/2003 12:19:46
 *   └ID pasien  └teknik  └jenis   └hasil      └satuan └waktu hasil
 *
 * DUA JEBAKAN YANG HARUS DITANGANI, DAN KEDUANYA DIAM-DIAM MERUSAK DATA
 *
 * 1. PEMISAH DESIMAL KOMA. Alat ini keluaran Spanyol dan menulis
 *    "121,4717" untuk 121.4717. Dibaca apa adanya oleh parseFloat,
 *    "121,4717" menjadi 121 — bukan galat, bukan NaN, melainkan angka
 *    yang salah dan tampak wajar. Kalium 5,2 mmol/L akan tersimpan
 *    sebagai 5, dan tidak ada yang menyadarinya.
 *
 * 2. TANGGAL dd/mm/yyyy. "01/09/2026" berarti 1 September, bukan
 *    9 Januari. Salah urai menggeser waktu hasil berbulan-bulan dan
 *    merusak perhitungan TAT serta pemeriksaan delta.
 *
 * Nilai teks (mis. hasil kualitatif) dibiarkan apa adanya — hanya yang
 * berbentuk angka yang dinormalkan.
 */

/** Pisahkan baris, buang baris kosong. */
function baris(teks) {
  return String(teks).split(/\r\n|\r|\n/).filter((b) => b.trim() !== '');
}

/**
 * Ubah angka bergaya Spanyol menjadi bentuk yang dipahami JavaScript.
 *
 * Menangani "121,4717" dan juga "1.234,56" (titik sebagai pemisah ribuan).
 * Bila bentuknya bukan angka sama sekali, teks aslinya dikembalikan utuh.
 */
function normalkanAngka(teks) {
  const t = String(teks).trim();
  if (t === '') return '';

  // Hanya perlakukan sebagai angka bila seluruhnya angka, titik, koma,
  // dan tanda minus. Selain itu biarkan — bisa jadi hasil kualitatif.
  if (!/^-?[\d.,]+$/.test(t)) return t;

  const adaKoma  = t.includes(',');
  const adaTitik = t.includes('.');

  if (adaKoma && adaTitik) {
    // "1.234,56" → titik ribuan, koma desimal.
    return t.replace(/\./g, '').replace(',', '.');
  }
  if (adaKoma) {
    return t.replace(',', '.');
  }

  return t;
}

/**
 * Urai "dd/mm/yyyy hh:mm:ss" menjadi "YYYY-MM-DD HH:MM:SS".
 * Mengembalikan null bila bentuknya tidak dikenali — lebih baik kosong
 * daripada tanggal yang salah.
 */
function waktu(teks) {
  const m = String(teks).trim().match(
    /^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/
  );
  if (m === null) return null;

  const [, dd, mm, yyyy, hh = '00', mi = '00', ss = '00'] = m;

  return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
}

/**
 * Ubah isi satu berkas ekspor menjadi payload ternormalkan, sama bentuknya
 * dengan keluaran astm.keNormal() dan hl7.keNormal() sehingga sisa
 * middleware tidak perlu tahu asalnya dari berkas.
 *
 * Satu berkas dapat memuat beberapa pasien; baris dikelompokkan menurut
 * ID pasien, dan tiap kelompok menjadi satu sampel.
 *
 * @param {string} teks     isi berkas
 * @param {string} kodeAlat
 * @returns {{instrument:string, samples:Array}}
 */
function keNormal(teks, kodeAlat) {
  const perSampel = new Map();
  const dilewati  = [];

  for (const b of baris(teks)) {
    // TAB adalah pemisah resmi. Sebagian penyalinan berkas mengubah TAB
    // menjadi rangkaian spasi, jadi keduanya diterima — tetapi TAB
    // didahulukan agar satuan seperti "10^3/uL" tidak ikut terpecah.
    const f = (b.includes('\t') ? b.split('\t') : b.split(/\s{2,}/)).map((x) => x.trim());

    // ID pasien, teknik, jenis, hasil, satuan, waktu.
    if (f.length < 4) {
      dilewati.push(b);
      continue;
    }

    const [idPasien, teknik, jenis, hasil, satuan = '', tgl = ''] = f;

    if (idPasien === '' || teknik === '') {
      dilewati.push(b);
      continue;
    }

    if (!perSampel.has(idPasien)) {
      perSampel.set(idPasien, {
        sample_id: idPasien,
        instrument_sample_id: idPasien,
        patient: null,
        specimen_type: jenis || null,
        results: [],
      });
    }

    perSampel.get(idPasien).results.push({
      code: teknik,
      raw_code: teknik,
      name: '',
      value: normalkanAngka(hasil),
      unit: satuan,
      ref_range: '',
      flags: '',
      status: 'F',
      completed_at: waktu(tgl),
    });
  }

  return {
    instrument: kodeAlat,
    samples: [...perSampel.values()],
    dilewati,
  };
}

/**
 * Susun isi import.txt dari daftar worklist.
 *
 * Batas yang ditegakkan manual, dan konsekuensinya bila dilanggar:
 *
 *   - ID pasien dan ID teknik maksimal 16 karakter. Lebih dari itu, A15
 *     menolak barisnya dan menulis alasannya ke Errors.txt.
 *   - Panjang seluruh baris maksimal 41 karakter.
 *   - Kelas tepat 1 karakter, jenis dan tabung tepat 3.
 *
 * Baris yang melanggar TIDAK ikut ditulis, dan dikembalikan pada
 * `ditolak` agar terlihat di log — bukan dibuang diam-diam, karena
 * pemeriksaan yang hilang dari worklist berarti sampel dikerjakan tanpa
 * permintaan.
 *
 * @param {Array<{sample_id:string, tests:Array<string>, urgent?:boolean,
 *                specimen?:string, tube?:string}>} daftar
 */
function keImport(daftar) {
  const JENIS  = ['SER', 'URI', 'SEM', 'WBL', 'LIQ'];
  const TABUNG = ['PED', 'T13', 'T15'];

  const baris   = [];
  const ditolak = [];

  for (const w of daftar) {
    const id      = String(w.sample_id ?? '').trim();
    const kelas   = w.urgent === true ? 'U' : 'N';
    const jenis   = JENIS.includes(w.specimen) ? w.specimen : 'SER';
    const tabung  = TABUNG.includes(w.tube) ? w.tube : 'T13';

    if (id === '' || id.includes('#')) {
      ditolak.push({ sample_id: id, sebab: 'ID pasien kosong atau memuat "#"' });
      continue;
    }
    if (id.length > 16) {
      ditolak.push({ sample_id: id, sebab: `ID pasien ${id.length} karakter, maksimal 16` });
      continue;
    }

    for (const t of w.tests ?? []) {
      const teknik = String(t).trim();

      if (teknik === '') continue;

      if (teknik.length > 16) {
        ditolak.push({ sample_id: id, test: teknik, sebab: `ID teknik ${teknik.length} karakter, maksimal 16` });
        continue;
      }

      const garis = [kelas, jenis, id, teknik, tabung].join('\t');

      if (garis.length > 41) {
        ditolak.push({ sample_id: id, test: teknik, sebab: `baris ${garis.length} karakter, maksimal 41` });
        continue;
      }

      baris.push(garis);
    }
  }

  return { isi: baris.join('\r\n') + (baris.length > 0 ? '\r\n' : ''), ditolak };
}

/**
 * Baca Errors.txt yang ditulis A15 setelah impor.
 *
 * Isinya bebas bentuk, jadi tidak diurai — dikembalikan sebagai baris
 * agar dapat dicatat apa adanya. Yang penting berkas ini dibaca sama
 * sekali: tanpa itu, worklist yang ditolak alat tidak meninggalkan jejak
 * di sisi LIS.
 */
function bacaErrors(teks) {
  return baris(teks).map((b) => b.trim());
}

module.exports = { keNormal, keImport, bacaErrors, normalkanAngka, waktu };
