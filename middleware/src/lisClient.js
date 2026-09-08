'use strict';

/**
 * Klien HTTP ke LIS.
 *
 * Memakai fetch bawaan Node 18+ sehingga tidak memerlukan dependensi.
 * Setiap permintaan membawa header X-API-Key, dan opsional X-Signature
 * berisi HMAC-SHA256 atas body.
 */

const crypto = require('crypto');
const { config } = require('./config');
const logger = require('./logger');

class LisClient {
  constructor() {
    this.base = config.lis.baseUrl;
  }

  _headers(body) {
    const headers = {
      'Accept': 'application/json',
      'X-API-Key': config.lis.apiKey,
      'User-Agent': 'LIS-Instrument-Gateway/1.0',
    };

    if (body !== undefined && body !== null) {
      headers['Content-Type'] = 'application/json; charset=utf-8';

      if (config.lis.signRequests && config.lis.apiSecret !== '') {
        headers['X-Signature'] = crypto
          .createHmac('sha256', config.lis.apiSecret)
          .update(body)
          .digest('hex');
      }
    }

    return headers;
  }

  async _minta(metode, jalur, payload) {
    const url = this.base + jalur;
    const body = payload === undefined ? undefined : JSON.stringify(payload);

    const kendali = new AbortController();
    const batas = setTimeout(() => kendali.abort(), config.lis.timeoutMs);

    try {
      const respons = await fetch(url, {
        method: metode,
        headers: this._headers(body),
        body,
        signal: kendali.signal,
      });

      const teks = await respons.text();
      let data = null;
      try {
        data = JSON.parse(teks);
      } catch {
        data = null;
      }

      if (!respons.ok) {
        const pesan = (data && data.pesan) ? data.pesan : `HTTP ${respons.status}`;

        // 4xx selain 429 adalah kesalahan permanen: mengulang tidak menolong.
        const permanen = respons.status >= 400 && respons.status < 500 && respons.status !== 429;

        const galat = new Error(`${metode} ${jalur} gagal: ${pesan}`);
        galat.status = respons.status;
        galat.permanen = permanen;
        galat.data = data;
        throw galat;
      }

      return data;
    } catch (e) {
      if (e.name === 'AbortError') {
        const galat = new Error(`${metode} ${jalur} melewati batas waktu ${config.lis.timeoutMs} ms`);
        galat.permanen = false;
        throw galat;
      }
      if (e.permanen === undefined) {
        e.permanen = false; // kegagalan jaringan → layak diulang
      }
      throw e;
    } finally {
      clearTimeout(batas);
    }
  }

  /** Konfigurasi seluruh alat aktif. */
  async ambilKonfigurasiAlat() {
    const hasil = await this._minta('GET', '/api/v1/instruments');

    return hasil?.data ?? { instruments: [] };
  }

  /** Laporkan status koneksi tiap alat. */
  async heartbeat(daftar) {
    return this._minta('POST', '/api/v1/instruments/heartbeat', { instruments: daftar });
  }

  /** Kirim payload hasil ternormalisasi. */
  async kirimHasil(payload) {
    return this._minta('POST', '/api/v1/instruments/results', payload);
  }

  /** Simpan pesan mentah tanpa memproses hasil (untuk penelusuran). */
  async kirimPesanMentah(kodeAlat, protokol, mentah, sampleId = null) {
    return this._minta('POST', '/api/v1/instruments/messages', {
      instrument_code: kodeAlat,
      protocol: protokol,
      raw: mentah,
      sample_id: sampleId,
      direction: 'in',
    });
  }

  /** Worklist untuk satu sample ID (jawaban host query). */
  async ambilWorklistSampel(sampleId, kodeAlat) {
    try {
      const hasil = await this._minta(
        'GET',
        `/api/v1/worklist/${encodeURIComponent(sampleId)}?instrument=${encodeURIComponent(kodeAlat)}`
      );

      return hasil?.data ?? null;
    } catch (e) {
      if (e.status === 404) {
        return null; // sampel memang tidak dikenal — bukan kegagalan sistem
      }
      throw e;
    }
  }

  /** Antrian worklist yang menunggu dikirim ke sebuah alat. */
  async ambilAntrianWorklist(kodeAlat, ack = true) {
    const hasil = await this._minta(
      'GET',
      `/api/v1/instruments/${encodeURIComponent(kodeAlat)}/worklist?ack=${ack ? 1 : 0}`
    );

    return hasil?.data ?? [];
  }

  async ping() {
    return this._minta('GET', '/api/v1/ping');
  }
}

module.exports = new LisClient();
