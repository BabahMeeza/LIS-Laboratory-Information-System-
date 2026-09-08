'use strict';

/**
 * Protokol "raw": alat mengirim hasil sebagai baris teks biasa, tanpa
 * kerangka ASTM maupun HL7. Bentuk ini dipakai sejumlah analyzer POCT
 * dan alat lama yang hanya mencetak ke printer serial.
 *
 * Format yang dikenali (satu baris per parameter):
 *
 *   SAMPLEID;KODE;NILAI;SATUAN;FLAG
 *   SAMPLEID,KODE,NILAI,SATUAN,FLAG
 *   SAMPLEID|KODE|NILAI|SATUAN|FLAG
 *
 * Baris dengan awalan '#' diabaikan sebagai komentar. Bila alat Anda
 * memakai tata letak berbeda, sesuaikan fungsi keNormal() di berkas ini —
 * hanya bagian ini yang perlu diubah.
 */

const { EventEmitter } = require('events');

/** Tebak pemisah kolom dari baris pertama yang berisi data. */
function tebakPemisah(baris) {
  for (const kandidat of [';', '|', '\t', ',']) {
    if (baris.includes(kandidat)) {
      return kandidat;
    }
  }

  return ';';
}

/**
 * @param {string} teks
 * @param {string} kodeAlat
 */
function keNormal(teks, kodeAlat) {
  const baris = teks
    .split(/[\r\n]+/)
    .map((b) => b.trim())
    .filter((b) => b !== '' && !b.startsWith('#'));

  const perSampel = new Map();

  for (const b of baris) {
    const pemisah = tebakPemisah(b);
    const kolom = b.split(pemisah).map((k) => k.trim());

    if (kolom.length < 3) {
      continue;
    }

    const [sampleId, kode, nilai, satuan = '', flag = ''] = kolom;
    if (sampleId === '' || kode === '') {
      continue;
    }

    if (!perSampel.has(sampleId)) {
      perSampel.set(sampleId, {
        sample_id: sampleId,
        instrument_sample_id: sampleId,
        patient: null,
        results: [],
      });
    }

    perSampel.get(sampleId).results.push({
      code: kode,
      raw_code: kode,
      value: nilai,
      unit: satuan,
      flags: flag,
      status: 'F',
      completed_at: null,
    });
  }

  return {
    instrument_code: kodeAlat,
    protocol: 'raw',
    raw: teks,
    samples: [...perSampel.values()],
  };
}

/**
 * Sesi raw: mengumpulkan baris dan memancarkan hasil setelah jeda diam,
 * karena tidak ada penanda akhir pesan pada protokol ini.
 */
class RawSession extends EventEmitter {
  constructor({ logger, nama = 'alat', jedaMs = 3000 }) {
    super();
    this.logger = logger;
    this.nama = nama;
    this.jedaMs = jedaMs;
    this.buffer = '';
    this.timer = null;
  }

  feed(data) {
    this.buffer += data.toString('latin1');

    if (this.logger.aktifTrace()) {
      this.logger.trace(`[${this.nama}] << ${this.logger.bacaBiner(data)}`);
    }

    if (this.timer) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => this._selesai(), this.jedaMs);
  }

  _selesai() {
    const teks = this.buffer.trim();
    this.buffer = '';
    this.timer = null;

    if (teks !== '') {
      this.emit('text', teks);
    }
  }

  hancurkan() {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    this.buffer = '';
  }
}

module.exports = { RawSession, keNormal };
