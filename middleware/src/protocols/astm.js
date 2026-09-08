'use strict';

/**
 * ASTM E1381 (lapisan tautan) + ASTM E1394 (lapisan pesan).
 *
 * E1381 — kerangka pengiriman:
 *   Pengirim  : <ENQ>
 *   Penerima  : <ACK>
 *   Pengirim  : <STX> FN teks <ETB|ETX> C1 C2 <CR><LF>
 *   Penerima  : <ACK> bila checksum benar, <NAK> bila salah
 *   Pengirim  : <EOT>
 *
 *   FN adalah nomor bingkai '0'–'7' yang berputar.
 *   Checksum = jumlah seluruh byte SETELAH <STX> sampai dan termasuk
 *   <ETB>/<ETX>, modulo 256, ditulis dua digit heksadesimal huruf besar.
 *   <ETB> menandai bingkai lanjutan, <ETX> menandai akhir sebuah rekaman.
 *
 * E1394 — rekaman: H (header), P (pasien), O (order), R (hasil),
 *   C (komentar), Q (permintaan/host query), L (penutup).
 *   Pembatas baku: field '|', repeat '\', component '^', escape '&'.
 */

const { EventEmitter } = require('events');

const ENQ = 0x05;
const ACK = 0x06;
const NAK = 0x15;
const STX = 0x02;
const ETX = 0x03;
const EOT = 0x04;
const ETB = 0x17;
const CR = 0x0d;
const LF = 0x0a;

const MAKS_TEKS_BINGKAI = 240; // batas aman menurut E1381 (240 karakter data)

/**
 * Hitung checksum ASTM atas potongan teks bingkai.
 * Masukan adalah string berisi FN + data + ETB/ETX.
 */
function checksum(teks) {
  let jumlah = 0;
  for (let i = 0; i < teks.length; i++) {
    jumlah = (jumlah + teks.charCodeAt(i)) & 0xff;
  }

  return jumlah.toString(16).toUpperCase().padStart(2, '0');
}

/**
 * Pecah rekaman menjadi bingkai-bingkai siap kirim.
 *
 * @param {string[]} records Rekaman E1394 tanpa CR di akhir
 * @param {number}   mulaiFn Nomor bingkai awal (0–7)
 * @returns {Buffer[]}
 */
function bangunBingkai(records, mulaiFn = 1) {
  const bingkai = [];
  let fn = mulaiFn % 8;

  for (const record of records) {
    // Setiap rekaman diakhiri CR sebelum ETB/ETX.
    const muatan = record + '\r';
    const potongan = [];

    for (let i = 0; i < muatan.length; i += MAKS_TEKS_BINGKAI) {
      potongan.push(muatan.slice(i, i + MAKS_TEKS_BINGKAI));
    }

    potongan.forEach((bagian, idx) => {
      const terakhir = idx === potongan.length - 1;
      const penutup = terakhir ? String.fromCharCode(ETX) : String.fromCharCode(ETB);
      const inti = String(fn) + bagian + penutup;

      bingkai.push(Buffer.from(
        String.fromCharCode(STX) + inti + checksum(inti) + '\r\n',
        'latin1'
      ));

      fn = (fn + 1) % 8;
    });
  }

  return bingkai;
}

/**
 * Sesi ASTM dua arah untuk satu koneksi alat.
 *
 * Peristiwa yang dipancarkan:
 *   'records' (string[])           — kumpulan rekaman lengkap setelah EOT
 *   'query'   ({ sampleId, raw })  — alat meminta worklist (rekaman Q)
 *   'raw'     (string)             — seluruh teks mentah sesi
 *   'error'   (Error)
 */
class AstmSession extends EventEmitter {
  /**
   * @param {object}   opsi
   * @param {Function} opsi.write    fungsi pengirim byte ke alat
   * @param {object}   opsi.logger
   * @param {string}   opsi.nama     nama alat untuk keperluan log
   * @param {number}   opsi.timeoutMs
   */
  constructor({ write, logger, nama = 'alat', timeoutMs = 45000 }) {
    super();
    this.write = write;
    this.logger = logger;
    this.nama = nama;
    this.timeoutMs = timeoutMs;

    this.buffer = Buffer.alloc(0);
    this.records = [];
    this.mentah = '';
    this.potonganBingkai = '';   // teks lintas-bingkai untuk satu rekaman
    this.sedangSesi = false;
    this.timer = null;

    // Keadaan saat middleware menjadi PENGIRIM (menjawab host query).
    this.kirim = null;

    // ASTM bersifat setengah dupleks: hanya satu pihak boleh menguasai
    // saluran pada satu waktu. Rekaman yang hendak dikirim saat alat masih
    // mengirim ditahan di sini, lalu dilepas setelah alat mengirim EOT.
    this.tertunda = [];
  }

  /** Masukkan byte yang diterima dari alat. */
  feed(data) {
    this.buffer = Buffer.concat([this.buffer, data]);
    this._proses();
  }

  _resetTimeout() {
    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => {
      if (this.sedangSesi) {
        this.logger.warn(`[${this.nama}] Sesi ASTM diam terlalu lama, dipulihkan paksa`);
        this._selesaikanSesi();
      }
    }, this.timeoutMs);
  }

  _proses() {
    while (this.buffer.length > 0) {
      const byte = this.buffer[0];

      // --- Mode pengirim: menunggu ACK/NAK atas bingkai yang kami kirim ---
      if (this.kirim !== null) {
        if (byte === ACK) {
          this.buffer = this.buffer.subarray(1);
          this._lanjutkanPengiriman(true);
          continue;
        }
        if (byte === NAK) {
          this.buffer = this.buffer.subarray(1);
          this._lanjutkanPengiriman(false);
          continue;
        }

        // Tabrakan: alat juga ingin mengirim. Menurut E1381 saluran
        // diserahkan kepada alat; permintaan kami ditunda dan dikirim
        // ulang setelah sesi alat selesai.
        if (byte === ENQ && this.kirim.indeks === -1) {
          this.logger.debug(`[${this.nama}] Tabrakan ENQ, saluran diserahkan kepada alat`);
          this.tertunda.unshift(this.kirim.records);
          this.kirim = null;
          continue; // biarkan ENQ diproses oleh mode penerima di bawah
        }

        // Byte lain saat mengirim diabaikan (derau saluran).
        this.buffer = this.buffer.subarray(1);
        continue;
      }

      // --- Mode penerima ---
      if (byte === ENQ) {
        this.buffer = this.buffer.subarray(1);
        this.sedangSesi = true;
        this.records = [];
        this.mentah = '';
        this.potonganBingkai = '';
        this._resetTimeout();
        this._tulis(Buffer.from([ACK]));
        this.logger.debug(`[${this.nama}] Sesi ASTM dibuka (ENQ diterima)`);
        continue;
      }

      if (byte === EOT) {
        this.buffer = this.buffer.subarray(1);
        this.logger.debug(`[${this.nama}] Sesi ASTM ditutup (EOT diterima)`);
        this._selesaikanSesi();
        continue;
      }

      if (byte === STX) {
        // Bingkai lengkap diakhiri CR LF; tunggu sampai tersedia.
        const akhir = this._cariAkhirBingkai();
        if (akhir === -1) {
          return; // menunggu byte berikutnya
        }

        const bingkai = this.buffer.subarray(0, akhir + 1);
        this.buffer = this.buffer.subarray(akhir + 1);
        this._tanganiBingkai(bingkai);
        continue;
      }

      // Byte di luar kerangka (ACK/NAK nyasar, derau saluran) dibuang.
      this.buffer = this.buffer.subarray(1);
    }
  }

  /**
   * Cari indeks byte terakhir sebuah bingkai; -1 bila belum lengkap.
   *
   * Bingkai baku berakhir dengan <CR><LF> setelah dua digit checksum,
   * tetapi sebagian analyzer hanya mengirim <CR>. Kedua bentuk didukung
   * dengan cara mencari penutup ETX/ETB lebih dulu, lalu menghitung
   * dua karakter checksum sesudahnya.
   */
  _cariAkhirBingkai() {
    for (let i = 1; i < this.buffer.length; i++) {
      const b = this.buffer[i];
      if (b !== ETX && b !== ETB) {
        continue;
      }

      // Perlu dua digit checksum setelah penutup.
      const akhirChecksum = i + 2;
      if (akhirChecksum >= this.buffer.length) {
        return -1; // menunggu byte checksum
      }

      const setelah = this.buffer[akhirChecksum + 1];
      if (setelah === undefined) {
        return -1; // menunggu CR
      }
      if (setelah !== CR) {
        // Bukan pola yang dikenali; anggap bingkai berakhir di checksum.
        return akhirChecksum;
      }

      // Ikutkan LF bila menyusul.
      if (this.buffer[akhirChecksum + 2] === LF) {
        return akhirChecksum + 2;
      }

      // Bila LF mungkin belum tiba, tunggu sebentar hanya jika buffer habis.
      if (akhirChecksum + 2 >= this.buffer.length) {
        return -1;
      }

      return akhirChecksum + 1;
    }

    return -1;
  }

  _tanganiBingkai(bingkai) {
    this._resetTimeout();

    const teks = bingkai.toString('latin1');
    if (this.logger.aktifTrace()) {
      this.logger.trace(`[${this.nama}] << ${this.logger.bacaBiner(bingkai)}`);
    }

    // Bentuk: STX FN data (ETB|ETX) C1 C2 CR LF
    const posPenutup = Math.max(teks.lastIndexOf('\x03'), teks.lastIndexOf('\x17'));
    if (posPenutup === -1) {
      this.logger.warn(`[${this.nama}] Bingkai tanpa ETX/ETB, dijawab NAK`);
      this._tulis(Buffer.from([NAK]));
      return;
    }

    const inti = teks.slice(1, posPenutup + 1);          // FN + data + penutup
    const checksumDiterima = teks.slice(posPenutup + 1).replace(/[\r\n]/g, '');
    const checksumDihitung = checksum(inti);

    if (checksumDiterima.length >= 2 &&
        checksumDiterima.toUpperCase() !== checksumDihitung) {
      this.logger.warn(
        `[${this.nama}] Checksum bingkai tidak cocok ` +
        `(diterima ${checksumDiterima}, dihitung ${checksumDihitung}), dijawab NAK`
      );
      this._tulis(Buffer.from([NAK]));
      return;
    }

    const data = inti.slice(1, -1);   // buang FN dan penutup
    const lanjutan = teks[posPenutup] === '\x17'; // ETB = masih bersambung

    this.potonganBingkai += data;
    this.mentah += data;

    if (!lanjutan) {
      const record = this.potonganBingkai.replace(/\r$/, '');
      this.potonganBingkai = '';
      if (record !== '') {
        this.records.push(record);
        this._periksaQuery(record);
      }
    }

    this._tulis(Buffer.from([ACK]));
  }

  /** Rekaman Q berarti alat meminta daftar pemeriksaan untuk sebuah sampel. */
  _periksaQuery(record) {
    if (!record.startsWith('Q|')) {
      return;
    }

    const field = record.split('|');
    // Q|1|^sampleid^...||ALL||||||||O
    const komponen = (field[2] || '').split('^').filter((x) => x !== '');
    const sampleId = komponen.length > 0 ? komponen[komponen.length - 1].trim() : '';

    this.logger.info(`[${this.nama}] Host query untuk sample "${sampleId}"`);
    this.emit('query', { sampleId, raw: record });
  }

  _selesaikanSesi() {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }

    const records = this.records;
    const mentah = this.mentah;

    this.sedangSesi = false;
    this.records = [];
    this.mentah = '';
    this.potonganBingkai = '';

    if (records.length > 0) {
      this.emit('records', records, mentah);
    }

    // Saluran bebas — lepaskan pengiriman yang ditahan.
    this._lepaskanTertunda();
  }

  /** Mulai pengiriman berikutnya yang menunggu, bila saluran menganggur. */
  _lepaskanTertunda() {
    if (this.kirim !== null || this.sedangSesi || this.tertunda.length === 0) {
      return;
    }

    const records = this.tertunda.shift();
    this._mulaiPengiriman(records);
  }

  // -------------------------------------------------------------------
  // Mode pengirim — dipakai untuk menjawab host query
  // -------------------------------------------------------------------

  /**
   * Kirim sekumpulan rekaman ke alat memakai kerangka E1381.
   * @param {string[]} records
   */
  sendRecords(records) {
    if (!Array.isArray(records) || records.length === 0) {
      return;
    }

    // Alat masih menguasai saluran, atau pengiriman lain sedang berjalan:
    // antrikan dan kirim setelah saluran bebas. Memaksa mengirim di
    // tengah sesi alat membuat kedua pihak saling menunggu.
    if (this.sedangSesi || this.kirim !== null) {
      this.tertunda.push(records);
      this.logger.debug(
        `[${this.nama}] Saluran sedang dipakai, ${records.length} rekaman ditahan di antrian`
      );
      return;
    }

    this._mulaiPengiriman(records);
  }

  _mulaiPengiriman(records) {
    this.kirim = {
      records,
      bingkai: bangunBingkai(records, 1),
      indeks: -1,     // -1 = belum mengirim ENQ
      ulang: 0,
    };

    this.logger.debug(`[${this.nama}] Mengirim ${records.length} rekaman ke alat`);
    this._tulis(Buffer.from([ENQ]));
  }

  _lanjutkanPengiriman(diterima) {
    const s = this.kirim;
    if (s === null) {
      return;
    }

    if (!diterima) {
      s.ulang += 1;
      if (s.ulang > 6) {
        this.logger.error(`[${this.nama}] Pengiriman gagal setelah 6 percobaan, dibatalkan`);
        this._tulis(Buffer.from([EOT]));
        this.kirim = null;
        this._lepaskanTertunda();
        return;
      }
      // Kirim ulang bingkai yang sama.
      if (s.indeks >= 0 && s.indeks < s.bingkai.length) {
        this._tulis(s.bingkai[s.indeks]);
      } else {
        this._tulis(Buffer.from([ENQ]));
      }
      return;
    }

    s.ulang = 0;
    s.indeks += 1;

    if (s.indeks >= s.bingkai.length) {
      this._tulis(Buffer.from([EOT]));
      this.logger.debug(`[${this.nama}] Pengiriman selesai (EOT)`);
      this.kirim = null;
      this._lepaskanTertunda();
      return;
    }

    this._tulis(s.bingkai[s.indeks]);
  }

  _tulis(buffer) {
    if (this.logger.aktifTrace()) {
      this.logger.trace(`[${this.nama}] >> ${this.logger.bacaBiner(buffer)}`);
    }
    try {
      this.write(buffer);
    } catch (e) {
      this.emit('error', e);
    }
  }

  hancurkan() {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    this.kirim = null;
    this.buffer = Buffer.alloc(0);
  }
}

// ---------------------------------------------------------------------
// Lapisan pesan E1394
// ---------------------------------------------------------------------

/** Ambil pembatas dari rekaman H; kembalikan default bila tidak jelas. */
function bacaPembatas(records) {
  const h = records.find((r) => r.startsWith('H'));
  const bawaan = { field: '|', repeat: '\\', component: '^', escape: '&' };

  if (h === undefined || h.length < 5) {
    return bawaan;
  }

  // H|\^&  → h[1] pembatas field, h[2..4] repeat/component/escape
  return {
    field: h[1] || bawaan.field,
    repeat: h[2] || bawaan.repeat,
    component: h[3] || bawaan.component,
    escape: h[4] || bawaan.escape,
  };
}

/** Ubah timestamp ASTM (YYYYMMDDHHMMSS) menjadi 'YYYY-MM-DD HH:MM:SS'. */
function bacaWaktu(nilai) {
  if (typeof nilai !== 'string') {
    return null;
  }
  const t = nilai.replace(/\D/g, '');
  if (t.length < 8) {
    return null;
  }

  const tahun = t.slice(0, 4);
  const bulan = t.slice(4, 6);
  const hari = t.slice(6, 8);
  const jam = t.slice(8, 10) || '00';
  const menit = t.slice(10, 12) || '00';
  const detik = t.slice(12, 14) || '00';

  return `${tahun}-${bulan}-${hari} ${jam}:${menit}:${detik}`;
}

/**
 * Ambil kode pemeriksaan dari universal test id.
 * Bentuk lazim: '^^^WBC' atau '^^^WBC^1' atau langsung 'WBC'.
 */
function bacaKodeTes(nilai, pemisahKomponen = '^') {
  if (typeof nilai !== 'string' || nilai === '') {
    return '';
  }

  const bagian = nilai.split(pemisahKomponen);
  // Kode berada pada komponen ke-4 bila tersedia, jika tidak ambil
  // komponen tidak kosong pertama.
  if (bagian.length >= 4 && bagian[3].trim() !== '') {
    return bagian[3].trim();
  }

  const pertama = bagian.find((b) => b.trim() !== '');

  return pertama === undefined ? '' : pertama.trim();
}

/**
 * Terjemahkan rekaman E1394 menjadi payload ternormalisasi untuk LIS.
 *
 * @param {string[]} records
 * @param {string}   kodeAlat
 * @param {string}   mentah
 * @returns {{instrument_code:string,protocol:string,raw:string,samples:Array}}
 */
function keNormal(records, kodeAlat, mentah = '') {
  const d = bacaPembatas(records);
  const samples = [];
  let sampelAktif = null;
  let pasienAktif = null;

  const dorongSampel = () => {
    if (sampelAktif !== null && sampelAktif.results.length > 0) {
      samples.push(sampelAktif);
    }
    sampelAktif = null;
  };

  for (const record of records) {
    const f = record.split(d.field);
    const tipe = (f[0] || '').charAt(0).toUpperCase();

    if (tipe === 'P') {
      // P|seq|practice_id|lab_id|id3|nama|...|tgl_lahir|jk
      const nama = (f[5] || '').split(d.component).filter((x) => x !== '').join(' ').trim();
      pasienAktif = {
        id: (f[2] || f[3] || '').trim(),
        name: nama,
        birthdate: bacaWaktu(f[7]),
        sex: (f[8] || '').trim().toUpperCase(),
      };
      continue;
    }

    if (tipe === 'O') {
      dorongSampel();

      // O|seq|specimen_id|instrument_specimen_id|universal_test_id|priority|...
      const specimenId = (f[2] || '').split(d.component)[0].trim();
      const instrumenId = (f[3] || '').split(d.component)[0].trim();

      sampelAktif = {
        sample_id: specimenId !== '' ? specimenId : instrumenId,
        instrument_sample_id: instrumenId,
        priority: (f[5] || '').trim(),
        requested_at: bacaWaktu(f[6]),
        collected_at: bacaWaktu(f[7]),
        specimen_descriptor: (f[15] || '').trim(),
        patient: pasienAktif,
        results: [],
      };
      continue;
    }

    if (tipe === 'R') {
      if (sampelAktif === null) {
        // Hasil tanpa rekaman O — buat wadah agar tidak hilang.
        sampelAktif = {
          sample_id: '',
          instrument_sample_id: '',
          patient: pasienAktif,
          results: [],
        };
      }

      // R|seq|universal_test_id|value|units|ref_range|abnormal_flags|
      //   nature|result_status|...|operator|started|completed|instrument
      sampelAktif.results.push({
        code: bacaKodeTes(f[2], d.component),
        raw_code: (f[2] || '').trim(),
        value: (f[3] || '').trim(),
        unit: (f[4] || '').trim(),
        ref_range: (f[5] || '').trim(),
        flags: (f[6] || '').trim(),
        status: (f[8] || '').trim(),
        completed_at: bacaWaktu(f[12]) || bacaWaktu(f[11]),
        operator: (f[10] || '').trim(),
      });
      continue;
    }

    if (tipe === 'C' && sampelAktif !== null && sampelAktif.results.length > 0) {
      // Komentar menempel pada hasil terakhir.
      const komentar = (f[3] || '').split(d.component).filter((x) => x !== '').join(' ').trim();
      if (komentar !== '') {
        const terakhir = sampelAktif.results[sampelAktif.results.length - 1];
        terakhir.comment = (terakhir.comment ? terakhir.comment + '; ' : '') + komentar;
      }
      continue;
    }

    if (tipe === 'L') {
      dorongSampel();
    }
  }

  dorongSampel();

  return {
    instrument_code: kodeAlat,
    protocol: 'astm',
    raw: mentah,
    samples,
  };
}

/**
 * Susun jawaban host query: daftar pemeriksaan untuk satu sampel.
 *
 * @param {object} worklist  { sample_id, patient, tests: string[] }
 * @param {string} namaHost
 * @returns {string[]} rekaman E1394 siap dikirim
 */
function bangunJawabanQuery(worklist, namaHost = 'LIS') {
  const waktu = new Date();
  const cap = [
    waktu.getFullYear(),
    String(waktu.getMonth() + 1).padStart(2, '0'),
    String(waktu.getDate()).padStart(2, '0'),
    String(waktu.getHours()).padStart(2, '0'),
    String(waktu.getMinutes()).padStart(2, '0'),
    String(waktu.getSeconds()).padStart(2, '0'),
  ].join('');

  const records = [`H|\\^&|||${namaHost}^1.0|||||||P|1|${cap}`];

  if (worklist === null || worklist === undefined ||
      !Array.isArray(worklist.tests) || worklist.tests.length === 0) {
    // Tidak ada order: jawab dengan terminator "no information".
    records.push('L|1|I');

    return records;
  }

  const p = worklist.patient || {};
  const jk = p.sex === 'L' ? 'M' : (p.sex === 'P' ? 'F' : 'U');
  const lahir = (p.birthdate || '').replace(/\D/g, '').slice(0, 8);

  records.push(
    `P|1||${p.id || ''}||${(p.name || '').replace(/[|^\\]/g, ' ')}||${lahir}|${jk}`
  );

  const kodeTes = worklist.tests.map((t) => `^^^${t}`).join('\\');
  const prioritas = worklist.priority === 'S' ? 'S' : 'R';

  records.push(
    `O|1|${worklist.sample_id}||${kodeTes}|${prioritas}|${cap}|||||A||||1`
  );

  records.push('L|1|F');

  return records;
}

module.exports = {
  AstmSession,
  checksum,
  bangunBingkai,
  keNormal,
  bacaPembatas,
  bacaWaktu,
  bacaKodeTes,
  bangunJawabanQuery,
  KODE: { ENQ, ACK, NAK, STX, ETX, EOT, ETB, CR, LF },
};
