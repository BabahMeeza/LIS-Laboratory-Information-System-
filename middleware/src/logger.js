'use strict';

/**
 * Log ke konsol dan berkas harian.
 *
 * Tingkat "trace" mencetak setiap byte yang dipertukarkan dengan alat
 * dalam bentuk yang dapat dibaca manusia — alat bantu utama saat
 * menelusuri masalah koneksi analyzer.
 */

const fs = require('fs');
const path = require('path');
const { config } = require('./config');

const TINGKAT = { error: 0, warn: 1, info: 2, debug: 3, trace: 4 };
const aktif = TINGKAT[config.logLevel] ?? TINGKAT.info;

const WARNA = {
  error: '\x1b[31m',
  warn: '\x1b[33m',
  info: '\x1b[36m',
  debug: '\x1b[90m',
  trace: '\x1b[90m',
};
const RESET = '\x1b[0m';

function pastikanFolder() {
  if (!fs.existsSync(config.logDir)) {
    fs.mkdirSync(config.logDir, { recursive: true });
  }
}

function tulis(tingkat, pesan, konteks) {
  if ((TINGKAT[tingkat] ?? 9) > aktif) {
    return;
  }

  const waktu = new Date().toISOString();
  const ekstra = konteks === undefined ? '' : ' ' + safeJson(konteks);
  const baris = `[${waktu}] ${tingkat.toUpperCase().padEnd(5)} ${pesan}${ekstra}`;

  const warna = WARNA[tingkat] ?? '';
  process.stdout.write(`${warna}${baris}${RESET}\n`);

  try {
    pastikanFolder();
    const berkas = path.join(config.logDir, `gateway-${waktu.slice(0, 10)}.log`);
    fs.appendFileSync(berkas, baris + '\n');
  } catch {
    // Kegagalan menulis log tidak boleh menghentikan middleware.
  }
}

function safeJson(nilai) {
  try {
    return JSON.stringify(nilai);
  } catch {
    return String(nilai);
  }
}

/**
 * Ubah data biner menjadi teks yang terbaca: karakter kendali protokol
 * ditampilkan sebagai <STX>, <CR>, dan seterusnya.
 */
function bacaBiner(buffer) {
  const peta = {
    0x02: '<STX>', 0x03: '<ETX>', 0x04: '<EOT>', 0x05: '<ENQ>',
    0x06: '<ACK>', 0x15: '<NAK>', 0x17: '<ETB>', 0x0b: '<VT>',
    0x1c: '<FS>', 0x0d: '<CR>', 0x0a: '<LF>', 0x1b: '<ESC>',
  };

  let keluar = '';
  for (const byte of buffer) {
    if (peta[byte] !== undefined) {
      keluar += peta[byte];
    } else if (byte < 0x20 || byte > 0x7e) {
      keluar += `<${byte.toString(16).padStart(2, '0').toUpperCase()}>`;
    } else {
      keluar += String.fromCharCode(byte);
    }
  }

  return keluar;
}

module.exports = {
  error: (p, k) => tulis('error', p, k),
  warn: (p, k) => tulis('warn', p, k),
  info: (p, k) => tulis('info', p, k),
  debug: (p, k) => tulis('debug', p, k),
  trace: (p, k) => tulis('trace', p, k),
  bacaBiner,
  aktifTrace: () => aktif >= TINGKAT.trace,
};
