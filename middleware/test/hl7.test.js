'use strict';

const test = require('node:test');
const assert = require('node:assert');

const hl7 = require('../src/protocols/hl7');

const VT = 0x0b;
const FS = 0x1c;
const CR = 0x0d;

const loggerSenyap = {
  error() {}, warn() {}, info() {}, debug() {}, trace() {},
  bacaBiner: () => '',
  aktifTrace: () => false,
};

const PESAN_ORU = [
  'MSH|^~\\&|SimChem|LAB|LIS|LAB|20260831120000||ORU^R01|MSG0001|P|2.4',
  'PID|1||000123||BUDI^SANTOSO||19900115|M',
  'OBR|1|PLC001|2608310001|PANEL^Kimia Klinik|R|20260831120000|20260831115000',
  'OBX|1|NM|GDS^Glukosa Darah Sewaktu||243|mg/dL|70-140|H|||F|||20260831121500',
  'OBX|2|NM|K^Kalium||6.9|mmol/L|3.5-5.1|HH|||F|||20260831121500',
  'NTE|1||Sampel hemolisis ringan',
].join('\r') + '\r';

function bungkus(pesan) {
  return Buffer.concat([
    Buffer.from([VT]),
    Buffer.from(pesan, 'latin1'),
    Buffer.from([FS, CR]),
  ]);
}

// ---------------------------------------------------------------------

test('pembatas dibaca dari MSH, bukan diasumsikan', () => {
  const { pembatas } = hl7.urai(PESAN_ORU);

  assert.strictEqual(pembatas.field, '|');
  assert.strictEqual(pembatas.component, '^');
  assert.strictEqual(pembatas.repeat, '~');
  assert.strictEqual(pembatas.escape, '\\');
  assert.strictEqual(pembatas.subcomponent, '&');
});

test('penomoran field MSH mengikuti aturan HL7 (MSH-9, MSH-10)', () => {
  const { segmen, pembatas } = hl7.urai(PESAN_ORU);
  const msh = segmen[0];

  assert.strictEqual(hl7.field(msh, 3, pembatas), 'SimChem');
  assert.strictEqual(hl7.field(msh, 9, pembatas), 'ORU^R01');
  assert.strictEqual(hl7.field(msh, 10, pembatas), 'MSG0001');
  assert.strictEqual(hl7.field(msh, 12, pembatas), '2.4');
});

test('jenisPesan dan controlId terbaca benar', () => {
  assert.strictEqual(hl7.jenisPesan(PESAN_ORU), 'ORU^R01');
  assert.strictEqual(hl7.controlId(PESAN_ORU), 'MSG0001');
});

test('keNormal menerjemahkan ORU^R01 menjadi payload LIS', () => {
  const payload = hl7.keNormal(PESAN_ORU, 'KIMIA-01');

  assert.strictEqual(payload.instrument_code, 'KIMIA-01');
  assert.strictEqual(payload.protocol, 'hl7');
  assert.strictEqual(payload.samples.length, 1);

  const s = payload.samples[0];
  // OBR-3 (filler) diutamakan sebagai sample id.
  assert.strictEqual(s.sample_id, '2608310001');
  assert.strictEqual(s.instrument_sample_id, 'PLC001');
  assert.strictEqual(s.patient.name, 'BUDI SANTOSO');
  assert.strictEqual(s.patient.birthdate, '1990-01-15 00:00:00');
  assert.strictEqual(s.collected_at, '2026-08-31 11:50:00');

  assert.strictEqual(s.results.length, 2);
  assert.deepStrictEqual(
    s.results.map((r) => [r.code, r.value, r.unit, r.flags]),
    [['GDS', '243', 'mg/dL', 'H'], ['K', '6.9', 'mmol/L', 'HH']]
  );
  assert.strictEqual(s.results[0].ref_range, '70-140');
  assert.strictEqual(s.results[1].comment, 'Sampel hemolisis ringan');
});

test('sample id diambil dari OBR-2 bila OBR-3 kosong', () => {
  const pesan = [
    'MSH|^~\\&|Sim|LAB|LIS|LAB|20260831120000||ORU^R01|M1|P|2.4',
    'OBR|1|2608319999||PANEL|R|20260831120000',
    'OBX|1|NM|HB^Hemoglobin||12.4|g/dL|13.2-17.3|L|||F',
  ].join('\r');

  const payload = hl7.keNormal(pesan, 'HEMA-01');

  assert.strictEqual(payload.samples[0].sample_id, '2608319999');
});

test('nilai bertipe CE diambil dari komponen kode, bukan teksnya', () => {
  const pesan = [
    'MSH|^~\\&|Sim|LAB|LIS|LAB|20260831120000||ORU^R01|M1|P|2.4',
    'OBR|1||2608310001|PANEL|R|20260831120000',
    'OBX|1|CE|HBSAG^HBsAg||NEG^Non Reaktif||||||F',
  ].join('\r');

  const payload = hl7.keNormal(pesan, 'IMUNO-01');

  assert.strictEqual(payload.samples[0].results[0].value, 'NEG');
});

test('beberapa OBR dalam satu pesan menghasilkan beberapa sampel', () => {
  const pesan = [
    'MSH|^~\\&|Sim|LAB|LIS|LAB|20260831120000||ORU^R01|M1|P|2.4',
    'OBR|1||SAMPEL-A|PANEL|R|20260831120000',
    'OBX|1|NM|GDS^Glukosa||110|mg/dL|70-140|N|||F',
    'OBR|2||SAMPEL-B|PANEL|R|20260831120500',
    'OBX|1|NM|GDS^Glukosa||243|mg/dL|70-140|H|||F',
  ].join('\r');

  const payload = hl7.keNormal(pesan, 'KIMIA-01');

  assert.strictEqual(payload.samples.length, 2);
  assert.strictEqual(payload.samples[0].sample_id, 'SAMPEL-A');
  assert.strictEqual(payload.samples[1].results[0].value, '243');
});

test('MLLP merakit pesan yang tiba terpotong', () => {
  const sesi = new hl7.Hl7Session({ write: () => {}, logger: loggerSenyap, nama: 'UJI' });

  const diterima = [];
  sesi.on('message', (p) => diterima.push(p));

  const bingkai = bungkus(PESAN_ORU);

  // Umpankan dalam potongan kecil yang tidak rapi.
  for (let i = 0; i < bingkai.length; i += 7) {
    sesi.feed(bingkai.subarray(i, i + 7));
  }

  assert.strictEqual(diterima.length, 1);
  assert.strictEqual(hl7.jenisPesan(diterima[0]), 'ORU^R01');

  sesi.hancurkan();
});

test('MLLP memisahkan dua pesan yang tiba sekaligus', () => {
  const sesi = new hl7.Hl7Session({ write: () => {}, logger: loggerSenyap, nama: 'UJI' });

  const diterima = [];
  sesi.on('message', (p) => diterima.push(p));

  sesi.feed(Buffer.concat([bungkus(PESAN_ORU), bungkus(PESAN_ORU)]));

  assert.strictEqual(diterima.length, 2);
  sesi.hancurkan();
});

test('MLLP mengabaikan derau sebelum penanda awal', () => {
  const sesi = new hl7.Hl7Session({ write: () => {}, logger: loggerSenyap, nama: 'UJI' });

  const diterima = [];
  sesi.on('message', (p) => diterima.push(p));

  sesi.feed(Buffer.concat([Buffer.from('sampah acak'), bungkus(PESAN_ORU)]));

  assert.strictEqual(diterima.length, 1);
  sesi.hancurkan();
});

test('ACK dibangun dengan MSA|AA dan control id yang benar', () => {
  const ack = hl7.bangunAck(PESAN_ORU, 'LIS');
  const baris = ack.split('\r').filter((b) => b !== '');

  assert.ok(baris[0].startsWith('MSH|^~\\&|LIS|LAB|SimChem|LAB|'));
  // MSH-9 mencerminkan trigger event pesan yang dijawab (ACK^R01 untuk
  // ORU^R01), sesuai spesifikasi HL7 Mindray. "ACK" polos tidak dikenali
  // analyzer dan membuat pengiriman dianggap gagal.
  assert.ok(baris[0].includes('|ACK^R01|'), baris[0]);
  assert.strictEqual(baris[1], 'MSA|AA|MSG0001');
});

test('ACK penolakan membawa kode dan teks alasan', () => {
  const ack = hl7.bangunAck(PESAN_ORU, 'LIS', 'AE', 'Kesalahan internal');
  const baris = ack.split('\r').filter((b) => b !== '');

  assert.strictEqual(baris[1], 'MSA|AE|MSG0001|Kesalahan internal');
});

test('sample id query terbaca dari QRD-8', () => {
  const pesan = [
    'MSH|^~\\&|SimChem|LAB|LIS|LAB|20260831120000||QRY^Q02|Q1|P|2.4',
    'QRD|20260831120000|R|I|20260831120000|||1^RD|2608310001|OTH|||T',
    'QRF|LAB||||',
  ].join('\r');

  assert.strictEqual(hl7.jenisPesan(pesan), 'QRY^Q02');
  assert.strictEqual(hl7.sampleIdQuery(pesan), '2608310001');
});

test('sample id query terbaca dari QPD-3 pada varian QBP', () => {
  const pesan = [
    'MSH|^~\\&|SimChem|LAB|LIS|LAB|20260831120000||QBP^Q11|Q2|P|2.5',
    'QPD|WOS^Work Order Step|Q2|2608310002',
    'RCP|I',
  ].join('\r');

  assert.strictEqual(hl7.sampleIdQuery(pesan), '2608310002');
});

test('ORM worklist memuat PID dan satu OBR per pemeriksaan', () => {
  const pesanQuery = [
    'MSH|^~\\&|SimChem|LAB|LIS|LAB|20260831120000||QRY^Q02|Q1|P|2.4',
    'QRD|20260831120000|R|I|20260831120000|||1^RD|2608310001|OTH|||T',
  ].join('\r');

  const orm = hl7.bangunOrmWorklist({
    sample_id: '2608310001',
    priority: 'S',
    patient: { id: '000123', name: 'BUDI SANTOSO', sex: 'L', birthdate: '1990-01-15' },
    tests: ['GDS', 'UREUM', 'KREAT'],
  }, pesanQuery, 'LIS');

  const baris = orm.split('\r').filter((b) => b !== '');

  assert.ok(baris[0].includes('|ORM^O01|'));
  assert.ok(baris[1].startsWith('PID|1||000123||BUDI SANTOSO||19900115|M'));

  const obr = baris.filter((b) => b.startsWith('OBR|'));
  assert.strictEqual(obr.length, 3);
  assert.ok(obr[0].includes('GDS^GDS'));
  assert.ok(obr[0].includes('|S|'), 'prioritas cito harus tercermin');

  const orc = baris.filter((b) => b.startsWith('ORC|NW|'));
  assert.strictEqual(orc.length, 3);
});

test('ORM worklist berubah menjadi ACK penolakan bila order tidak ada', () => {
  const pesanQuery = 'MSH|^~\\&|SimChem|LAB|LIS|LAB|20260831120000||QRY^Q02|Q9|P|2.4\r';
  const jawaban = hl7.bangunOrmWorklist(null, pesanQuery, 'LIS');

  assert.ok(jawaban.includes('MSA|AR|Q9|Order tidak ditemukan'));
});

test('karakter pembatas pada data pasien tidak merusak pesan keluar', () => {
  const pesanQuery = 'MSH|^~\\&|Sim|LAB|LIS|LAB|20260831120000||QRY^Q02|Q3|P|2.4\r';

  const orm = hl7.bangunOrmWorklist({
    sample_id: 'S|1',
    patient: { id: 'A^B', name: 'BUDI|SANTOSO~JR', sex: 'P', birthdate: '1990-01-15' },
    tests: ['GDS'],
  }, pesanQuery, 'LIS');

  const pid = orm.split('\r').find((b) => b.startsWith('PID'));

  assert.ok(!pid.includes('BUDI|SANTOSO'), 'pembatas field harus dibersihkan');
  assert.ok(pid.includes('BUDI SANTOSO JR'));
  assert.ok(pid.includes('A B'));
});
