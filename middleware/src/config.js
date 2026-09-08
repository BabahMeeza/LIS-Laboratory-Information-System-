'use strict';

/**
 * Pembacaan konfigurasi middleware dari berkas .env.
 *
 * Ditulis tanpa dependensi (tidak memakai dotenv) agar middleware dapat
 * dijalankan hanya dengan Node.js standar, tanpa `npm install`, kecuali
 * bila memakai transport serial.
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

/** Baca .env sederhana: KEY=value, baris kosong dan # diabaikan. */
function muatEnv() {
  const berkas = path.join(ROOT, '.env');
  if (!fs.existsSync(berkas)) {
    return;
  }

  const isi = fs.readFileSync(berkas, 'utf8');
  for (const barisMentah of isi.split(/\r?\n/)) {
    const baris = barisMentah.trim();
    if (baris === '' || baris.startsWith('#')) {
      continue;
    }

    const pisah = baris.indexOf('=');
    if (pisah === -1) {
      continue;
    }

    const kunci = baris.slice(0, pisah).trim();
    let nilai = baris.slice(pisah + 1).trim();

    // Buang tanda kutip pembungkus bila ada.
    if ((nilai.startsWith('"') && nilai.endsWith('"')) ||
        (nilai.startsWith("'") && nilai.endsWith("'"))) {
      nilai = nilai.slice(1, -1);
    }

    if (process.env[kunci] === undefined) {
      process.env[kunci] = nilai;
    }
  }
}

muatEnv();

function str(kunci, bawaan = '') {
  const v = process.env[kunci];
  return v === undefined || v === '' ? bawaan : v;
}

function int(kunci, bawaan) {
  const v = parseInt(process.env[kunci] ?? '', 10);
  return Number.isFinite(v) ? v : bawaan;
}

function bool(kunci, bawaan = false) {
  const v = (process.env[kunci] ?? '').toLowerCase();
  if (v === '') return bawaan;
  return ['1', 'true', 'ya', 'yes', 'on'].includes(v);
}

const config = {
  root: ROOT,

  lis: {
    baseUrl: str('LIS_BASE_URL', 'http://192.168.20.85/LIS/public').replace(/\/+$/, ''),
    apiKey: str('LIS_API_KEY'),
    apiSecret: str('LIS_API_SECRET'),
    signRequests: bool('LIS_SIGN_REQUESTS', false),
    timeoutMs: int('LIS_TIMEOUT_MS', 15000),
  },

  healthPort: int('HEALTH_PORT', 9701),
  heartbeatSeconds: int('HEARTBEAT_SECONDS', 30),
  configRefreshSeconds: int('CONFIG_REFRESH_SECONDS', 300),
  worklistPollSeconds: int('WORKLIST_POLL_SECONDS', 20),

  logLevel: str('LOG_LEVEL', 'info'),

  // Bentuk jawaban worklist HL7: off | auto | orm | rsp | mindray
  //
  // BAWAANNYA "off", dan itu disengaja.
  //
  // Pesan order yang tidak dikenali analyzer tidak selalu menghasilkan
  // pesan kesalahan yang sopan: sebagian firmware langsung fault dan
  // menuntut alat di-restart. Pada alat yang sedang melayani pasien, itu
  // bukan gangguan kecil. Karena itu pengiriman worklist HL7 harus
  // dinyalakan secara sadar setelah dialek alatnya dipastikan — tidak
  // pernah menyala sendiri karena nilai bawaan.
  //
  // Hasil dari alat tetap mengalir normal saat "off"; yang dimatikan
  // hanya arah LIS → alat. ASTM tidak terpengaruh pengaturan ini.
  hl7WorklistReply: str('HL7_WORKLIST_REPLY', 'off'),
  logDir: path.resolve(ROOT, str('LOG_DIR', 'logs')),
  spoolDir: path.resolve(ROOT, str('SPOOL_DIR', 'spool')),

  reconnectDelayMs: int('RECONNECT_DELAY_MS', 5000),
  astmSessionTimeoutMs: int('ASTM_SESSION_TIMEOUT_MS', 45000),
};

/** Validasi minimum agar kesalahan konfigurasi terdeteksi saat start. */
function periksa() {
  const masalah = [];

  if (!/^https?:\/\//i.test(config.lis.baseUrl)) {
    masalah.push('LIS_BASE_URL harus diawali http:// atau https://');
  }
  if (config.lis.apiKey === '') {
    masalah.push('LIS_API_KEY belum diisi');
  }
  if (config.lis.signRequests && config.lis.apiSecret === '') {
    masalah.push('LIS_SIGN_REQUESTS aktif tetapi LIS_API_SECRET kosong');
  }

  return masalah;
}

module.exports = { config, periksa };
