'use strict';

/**
 * Simulator analyzer ASTM — untuk menguji middleware tanpa alat sungguhan.
 *
 * Pemakaian:
 *   node simulator/astmAnalyzer.js [--host 127.0.0.1] [--port 5100]
 *                                  [--sample 2608310001] [--query]
 *
 *   --query  kirim host query (rekaman Q) lebih dulu dan tampilkan
 *            worklist yang dijawab LIS, baru kemudian kirim hasil.
 *
 * Simulator ini berperan sebagai analyzer hematologi: ia menyambung ke
 * port TCP yang dibuka middleware, lalu melakukan sesi ASTM lengkap
 * (ENQ → bingkai → EOT) dengan checksum yang benar.
 */

const net = require('net');

const ENQ = 0x05;
const ACK = 0x06;
const NAK = 0x15;
const STX = 0x02;
const ETX = 0x03;
const EOT = 0x04;

function arg(nama, bawaan) {
  const i = process.argv.indexOf(`--${nama}`);

  return i !== -1 && process.argv[i + 1] !== undefined ? process.argv[i + 1] : bawaan;
}

const host = arg('host', '127.0.0.1');
const port = parseInt(arg('port', '5100'), 10);
const sample = arg('sample', '2608310001');
const modeQuery = process.argv.includes('--query');

function checksum(teks) {
  let jumlah = 0;
  for (let i = 0; i < teks.length; i++) {
    jumlah = (jumlah + teks.charCodeAt(i)) & 0xff;
  }

  return jumlah.toString(16).toUpperCase().padStart(2, '0');
}

function bingkai(record, fn) {
  const inti = String(fn % 8) + record + '\r' + String.fromCharCode(ETX);

  return Buffer.from(
    String.fromCharCode(STX) + inti + checksum(inti) + '\r\n',
    'latin1'
  );
}

function capWaktu(d = new Date()) {
  return [
    d.getFullYear(),
    String(d.getMonth() + 1).padStart(2, '0'),
    String(d.getDate()).padStart(2, '0'),
    String(d.getHours()).padStart(2, '0'),
    String(d.getMinutes()).padStart(2, '0'),
    String(d.getSeconds()).padStart(2, '0'),
  ].join('');
}

const cap = capWaktu();

// Hasil hematologi lengkap, sebagian sengaja dibuat abnormal dan kritis
// agar penandaan flag dan alur nilai kritis di LIS ikut teruji.
const HASIL = [
  ['WBC', '15.80', '10*3/uL', 'H'],
  ['RBC', '3.92', '10*6/uL', 'L'],
  ['HGB', '6.4', 'g/dL', 'L'],      // di bawah ambang kritis 7.0
  ['HCT', '21.5', '%', 'L'],
  ['MCV', '84.2', 'fL', 'N'],
  ['MCH', '27.1', 'pg', 'N'],
  ['MCHC', '32.4', 'g/dL', 'N'],
  ['RDW-CV', '15.8', '%', 'H'],
  ['PLT', '96', '10*3/uL', 'L'],
  ['MPV', '9.4', 'fL', 'N'],
  ['NEU%', '78.4', '%', 'H'],
  ['LYM%', '14.2', '%', 'L'],
  ['MON%', '5.6', '%', 'N'],
  ['EOS%', '1.4', '%', 'N'],
  ['BAS%', '0.4', '%', 'N'],
];

const recordsQuery = [
  `H|\\^&|||SimAnalyzer^1.0|||||||P|1|${cap}`,
  `Q|1|^${sample}||ALL||||||||O`,
  'L|1|N',
];

const recordsHasil = [
  `H|\\^&|||SimAnalyzer^1.0|||||LIS||P|1|${cap}`,
  `P|1||${sample}||SIMULASI^PASIEN||19900115|M|||||||||||||||||`,
  `O|1|${sample}|${sample}|^^^ALL|R|${cap}|${cap}||||A||||1||||||||||F`,
  ...HASIL.map(([kode, nilai, satuan, flag], i) =>
    `R|${i + 1}|^^^${kode}^1|${nilai}|${satuan}||${flag}||F||sim||${cap}|SimAnalyzer`
  ),
  'C|1|I|Dihasilkan oleh simulator analyzer ASTM|G',
  'L|1|N',
];

let records = modeQuery ? recordsQuery : recordsHasil;
let indeks = -1;
let fn = 1;
let fase = modeQuery ? 'query' : 'hasil';
let bufferMasuk = Buffer.alloc(0);

const socket = net.createConnection({ host, port }, () => {
  console.log(`✓ Tersambung ke middleware ${host}:${port}`);
  console.log(`  Sample ID : ${sample}`);
  console.log(`  Mode      : ${modeQuery ? 'host query lalu kirim hasil' : 'kirim hasil'}\n`);
  kirim(Buffer.from([ENQ]));
});

function kirim(buffer) {
  socket.write(buffer);
}

function lanjut() {
  indeks += 1;

  if (indeks >= records.length) {
    kirim(Buffer.from([EOT]));
    console.log(`→ EOT (sesi ${fase} selesai)\n`);

    if (fase === 'query') {
      // Setelah query, tunggu jawaban LIS lalu kirim hasil.
      setTimeout(() => {
        fase = 'hasil';
        records = recordsHasil;
        indeks = -1;
        fn = 1;
        console.log('→ Mengirim hasil pemeriksaan…\n');
        kirim(Buffer.from([ENQ]));
      }, 1500);
      return;
    }

    setTimeout(() => {
      console.log('Selesai. Periksa LIS: menu Alat Laboratorium → Log Komunikasi,');
      console.log('lalu Worklist / Verifikasi untuk melihat hasilnya.');
      socket.end();
    }, 800);
    return;
  }

  const record = records[indeks];
  console.log(`→ ${record}`);
  kirim(bingkai(record, fn));
  fn += 1;
}

socket.on('data', (data) => {
  bufferMasuk = Buffer.concat([bufferMasuk, data]);

  while (bufferMasuk.length > 0) {
    const byte = bufferMasuk[0];

    if (byte === ACK) {
      bufferMasuk = bufferMasuk.subarray(1);
      lanjut();
      continue;
    }

    if (byte === NAK) {
      bufferMasuk = bufferMasuk.subarray(1);
      console.log('← NAK — middleware menolak bingkai (checksum salah?)');
      continue;
    }

    if (byte === ENQ) {
      // LIS ingin mengirim (jawaban worklist).
      bufferMasuk = bufferMasuk.subarray(1);
      console.log('← ENQ (LIS akan mengirim worklist)');
      kirim(Buffer.from([ACK]));
      continue;
    }

    if (byte === EOT) {
      bufferMasuk = bufferMasuk.subarray(1);
      console.log('← EOT (pengiriman LIS selesai)\n');
      continue;
    }

    if (byte === STX) {
      // Bingkai dari LIS — tampilkan isinya.
      const akhir = bufferMasuk.indexOf(0x0a);
      if (akhir === -1) {
        return;
      }

      const teks = bufferMasuk.subarray(0, akhir + 1).toString('latin1');
      bufferMasuk = bufferMasuk.subarray(akhir + 1);

      const isi = teks.slice(2).replace(/[\x03\x17][0-9A-F]{2}\r?\n?$/, '').replace(/\r$/, '');
      console.log(`← ${isi}`);
      kirim(Buffer.from([ACK]));
      continue;
    }

    bufferMasuk = bufferMasuk.subarray(1);
  }
});

socket.on('error', (e) => {
  console.error(`\n✗ Tidak dapat tersambung ke ${host}:${port} — ${e.message}`);
  console.error('  Pastikan middleware berjalan (npm start) dan alat dengan');
  console.error(`  transport tcp_server pada port ${port} sudah aktif di LIS.`);
  process.exit(1);
});

socket.on('close', () => {
  process.exit(0);
});
