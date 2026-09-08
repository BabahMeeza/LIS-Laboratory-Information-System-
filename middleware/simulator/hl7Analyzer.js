'use strict';

/**
 * Simulator analyzer HL7 v2 di atas MLLP — menguji middleware tanpa alat.
 *
 * Pemakaian:
 *   node simulator/hl7Analyzer.js [--host 127.0.0.1] [--port 5200]
 *                                 [--sample 2608310001] [--query]
 *
 *   --query  kirim QRY^Q02 untuk meminta worklist, tampilkan jawabannya,
 *            lalu kirim hasil.
 *
 * Berperan sebagai analyzer kimia klinik yang mengirim ORU^R01.
 */

const net = require('net');

const VT = 0x0b;
const FS = 0x1c;
const CR = 0x0d;

function arg(nama, bawaan) {
  const i = process.argv.indexOf(`--${nama}`);

  return i !== -1 && process.argv[i + 1] !== undefined ? process.argv[i + 1] : bawaan;
}

const host = arg('host', '127.0.0.1');
const port = parseInt(arg('port', '5200'), 10);
const sample = arg('sample', '2608310001');
const modeQuery = process.argv.includes('--query');

function cap(d = new Date()) {
  return [
    d.getFullYear(),
    String(d.getMonth() + 1).padStart(2, '0'),
    String(d.getDate()).padStart(2, '0'),
    String(d.getHours()).padStart(2, '0'),
    String(d.getMinutes()).padStart(2, '0'),
    String(d.getSeconds()).padStart(2, '0'),
  ].join('');
}

const waktu = cap();

// Hasil kimia klinik; kalium sengaja dibuat kritis (6.9 mmol/L).
const OBX = [
  ['GDS', 'Glukosa Darah Sewaktu', '243', 'mg/dL', '70-140', 'H'],
  ['UREUM', 'Ureum', '78.5', 'mg/dL', '10-50', 'H'],
  ['KREAT', 'Kreatinin', '2.84', 'mg/dL', '0.70-1.20', 'H'],
  ['SGOT', 'SGOT (AST)', '48', 'U/L', '<40', 'H'],
  ['SGPT', 'SGPT (ALT)', '52', 'U/L', '<41', 'H'],
  ['NA', 'Natrium', '131', 'mmol/L', '136-145', 'L'],
  ['K', 'Kalium', '6.9', 'mmol/L', '3.5-5.1', 'HH'],
  ['CL', 'Klorida', '99', 'mmol/L', '98-107', 'N'],
  ['ALB', 'Albumin', '3.10', 'g/dL', '3.5-5.2', 'L'],
];

function pesanHasil() {
  const baris = [
    `MSH|^~\\&|SimChem|LAB|LIS|LAB|${waktu}||ORU^R01|${waktu}|P|2.4`,
    `PID|1||${sample}||SIMULASI^PASIEN||19900115|M`,
    `OBR|1|${sample}|${sample}|PANEL^Kimia Klinik|R|${waktu}|${waktu}`,
    ...OBX.map(([kode, nama, nilai, satuan, rujukan, flag], i) =>
      `OBX|${i + 1}|NM|${kode}^${nama}||${nilai}|${satuan}|${rujukan}|${flag}|||F|||${waktu}`
    ),
    'NTE|1||Dihasilkan oleh simulator analyzer HL7',
  ];

  return baris.join('\r') + '\r';
}

function pesanQuery() {
  return [
    `MSH|^~\\&|SimChem|LAB|LIS|LAB|${waktu}||QRY^Q02|${waktu}|P|2.4`,
    `QRD|${waktu}|R|I|${waktu}|||1^RD|${sample}|OTH|||T`,
    'QRF|LAB||||',
  ].join('\r') + '\r';
}

function bungkus(pesan) {
  return Buffer.concat([
    Buffer.from([VT]),
    Buffer.from(pesan, 'latin1'),
    Buffer.from([FS, CR]),
  ]);
}

let buffer = Buffer.alloc(0);
let fase = modeQuery ? 'query' : 'hasil';

const socket = net.createConnection({ host, port }, () => {
  console.log(`✓ Tersambung ke middleware ${host}:${port}`);
  console.log(`  Sample ID : ${sample}`);
  console.log(`  Mode      : ${modeQuery ? 'query worklist lalu kirim hasil' : 'kirim hasil'}\n`);

  const pesan = modeQuery ? pesanQuery() : pesanHasil();
  console.log('→ Mengirim:\n' + pesan.split('\r').filter(Boolean).map((s) => '   ' + s).join('\n') + '\n');
  socket.write(bungkus(pesan));
});

socket.on('data', (data) => {
  buffer = Buffer.concat([buffer, data]);

  for (;;) {
    const mulai = buffer.indexOf(VT);
    if (mulai === -1) return;

    const akhir = buffer.indexOf(FS, mulai + 1);
    if (akhir === -1) return;

    const pesan = buffer.subarray(mulai + 1, akhir).toString('latin1');
    buffer = buffer.subarray(akhir + 2);

    console.log('← Jawaban middleware:');
    console.log(pesan.split('\r').filter(Boolean).map((s) => '   ' + s).join('\n') + '\n');

    if (fase === 'query') {
      fase = 'hasil';
      setTimeout(() => {
        const p = pesanHasil();
        console.log('→ Mengirim hasil:\n' + p.split('\r').filter(Boolean).map((s) => '   ' + s).join('\n') + '\n');
        socket.write(bungkus(p));
      }, 1200);
      continue;
    }

    setTimeout(() => {
      console.log('Selesai. Periksa LIS: menu Alat Laboratorium → Log Komunikasi,');
      console.log('lalu Verifikasi untuk melihat hasilnya.');
      socket.end();
    }, 600);
  }
});

socket.on('error', (e) => {
  console.error(`\n✗ Tidak dapat tersambung ke ${host}:${port} — ${e.message}`);
  console.error('  Pastikan middleware berjalan (npm start) dan alat HL7 dengan');
  console.error(`  transport tcp_server pada port ${port} sudah aktif di LIS.`);
  process.exit(1);
});

socket.on('close', () => process.exit(0));
