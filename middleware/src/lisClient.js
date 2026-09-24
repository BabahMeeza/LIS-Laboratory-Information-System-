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

      // Petunjuk jenis server, dipakai periksaServerLis() sebagai
      // pelengkap pengukuran. Server bawaan PHP tidak selalu mengirim
      // header "Server" — versi 8.4 tidak — sehingga header saja tidak
      // cukup untuk mengenalinya.
      const server = respons.headers.get('server') || '';
      const powered = respons.headers.get('x-powered-by') || '';
      if (/PHP\/[\d.]+ Development Server/i.test(server)) {
        this.serverPengembangan = server;
      } else if (server === '' && /^PHP\//i.test(powered)) {
        this.serverPengembangan = powered + ' (kemungkinan server bawaan PHP)';
      }

      const teks = await respons.text();
      let data = null;
      try {
        data = JSON.parse(teks);
      } catch {
        data = null;
      }

      if (!respons.ok) {
        let pesan = (data && data.pesan) ? data.pesan : `HTTP ${respons.status}`;

        // 4xx selain 429 adalah kesalahan permanen: mengulang tidak menolong.
        const permanen = respons.status >= 400 && respons.status < 500 && respons.status !== 429;

        // "HTTP 404" tanpa keterangan lain tidak menunjuk ke mana pun, dan
        // 404 di sini punya dua asal yang menuntut perbaikan berbeda:
        //
        //   - Server web yang tidak menemukan berkasnya. Balasannya HTML.
        //     Berarti jalur pada LIS_BASE_URL salah, atau aturan rewrite
        //     public/.htaccess tidak berjalan di server itu.
        //
        //   - LIS sendiri yang tidak mengenali rutenya. Balasannya JSON.
        //     Alamatnya sampai ke aplikasi, hanya jalurnya yang keliru.
        //
        // Keduanya dibedakan dari bentuk balasannya, dan alamat lengkap
        // yang dicoba ikut disebut supaya tidak perlu ditebak.
        if (respons.status === 404) {
          const html = /^\s*<(!doctype|html)/i.test(teks);
          pesan = `HTTP 404 pada ${this.base}${jalur} — ` + (html
            ? 'dijawab server web, bukan LIS. Jalur pada LIS_BASE_URL salah, '
              + 'atau rewrite public/.htaccess tidak aktif di server itu.'
            : 'dijawab LIS, tetapi rutenya tidak dikenali. Periksa jalur pada LIS_BASE_URL.');
        }

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

  /**
   * Periksa apakah LIS dilayani server pengembangan bawaan PHP, dan ukur
   * apakah ia sanggup melayani permintaan bersamaan.
   *
   * MENGAPA INI PENTING — dan mengapa gejalanya menyesatkan
   *
   * `php -S` melayani SATU permintaan pada satu waktu. Permintaan kedua
   * menunggu sampai yang pertama selesai, tanpa galat apa pun; ia hanya
   * lambat. Untuk halaman web itu tidak terasa.
   *
   * Untuk worklist dua arah, itu mematikan. Alat memberi LIS hanya DUA
   * DETIK untuk menjawab permintaan worklist. Bila pada saat itu ada
   * permintaan lain yang sedang dilayani — seseorang membuka halaman di
   * peramban, satu berkas CSS, denyut middleware sendiri — panggilan
   * worklist mengantre di belakangnya. Tenggat terlampaui, alat
   * menampilkan kegagalan, dan tidak ada satu pun baris log di sisi LIS
   * yang menunjukkan ada yang salah: permintaannya memang berhasil,
   * hanya terlambat.
   *
   * Gejalanya jadi berpindah-pindah dan sulit dipercaya: berhasil saat
   * diuji sendirian, gagal saat ada orang lain memakai LIS.
   *
   * Penawarnya satu baris — PHP_CLI_SERVER_WORKERS — atau pindah ke
   * Apache/nginx untuk pemakaian sungguhan.
   *
   * @returns {Promise<{dev:boolean, server:string, serial:boolean, msParalel:number|null}>}
   */
  async periksaServerLis() {
    this.serverPengembangan = undefined;

    try {
      await this.ping();
    } catch {
      return { dev: false, server: '', serial: false, msParalel: null };
    }

    const server = this.serverPengembangan || '';

    // UKUR perilakunya, jangan hanya membaca nama server.
    //
    // Header tidak dapat diandalkan: server bawaan PHP 8.4 tidak mengirim
    // "Server" sama sekali, dan PHP_CLI_SERVER_WORKERS dapat sudah
    // dipasang sehingga namanya sama tetapi perilakunya berbeda. Yang
    // menentukan adalah apakah permintaan bersamaan benar-benar dilayani
    // bersamaan — dan itu dapat diukur langsung.
    let msParalel = null;
    let msSatuan = null;
    let serial = false;

    try {
      // Ukur satu permintaan lebih dulu sebagai patokan.
      const m1 = Date.now();
      await this.ping();
      msSatuan = Date.now() - m1;

      const m2 = Date.now();
      await Promise.all([this.ping(), this.ping(), this.ping(), this.ping()]);
      msParalel = Date.now() - m2;

      // Server yang melayani bersamaan menyelesaikan empat permintaan
      // dalam waktu mendekati satu. Yang melayani berurutan memerlukan
      // sekitar empat kali lipat. Ambang 3× memberi ruang bagi derau.
      serial = msSatuan > 0 && msParalel >= msSatuan * 3;
    } catch {
      /* pengukuran gagal — jangan menyimpulkan apa pun */
    }

    return { dev: server !== '' || serial, server, serial, msParalel, msSatuan };
  }
}

module.exports = new LisClient();
