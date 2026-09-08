'use strict';

const test = require('node:test');
const assert = require('node:assert');

const astm = require('../src/protocols/astm');

const STX = '\x02';
const ETX = '\x03';
const ENQ = Buffer.from([0x05]);
const EOT = Buffer.from([0x04]);
const ACK = 0x06;
const NAK = 0x15;

/** Susun bingkai ASTM yang sah untuk keperluan uji. */
function bingkai(record, fn) {
  const inti = String(fn % 8) + record + '\r' + ETX;
  return Buffer.from(STX + inti + astm.checksum(inti) + '\r\n', 'latin1');
}

const loggerSenyap = {
  error() {}, warn() {}, info() {}, debug() {}, trace() {},
  bacaBiner: () => '',
  aktifTrace: () => false,
};

// ---------------------------------------------------------------------

test('checksum dihitung sesuai contoh baku ASTM', () => {
  // Contoh klasik: bingkai "1H|\^&|||" diakhiri CR ETX.
  const inti = '1H|\\^&|||\r' + ETX;
  const hasil = astm.checksum(inti);

  assert.match(hasil, /^[0-9A-F]{2}$/);

  // Verifikasi ulang secara mandiri (jumlah byte modulo 256).
  let jumlah = 0;
  for (const ch of inti) {
    jumlah = (jumlah + ch.charCodeAt(0)) & 0xff;
  }
  assert.strictEqual(hasil, jumlah.toString(16).toUpperCase().padStart(2, '0'));
});

test('bangunBingkai memberi nomor bingkai berurutan dan memutar setelah 7', () => {
  const records = Array.from({ length: 10 }, (_, i) => `R|${i + 1}|^^^X|1||`);
  const bingkaiSemua = astm.bangunBingkai(records, 1);

  assert.strictEqual(bingkaiSemua.length, 10);

  const nomor = bingkaiSemua.map((b) => b.toString('latin1')[1]);
  assert.deepStrictEqual(nomor, ['1', '2', '3', '4', '5', '6', '7', '0', '1', '2']);
});

test('bangunBingkai memecah rekaman panjang menjadi beberapa bingkai ETB/ETX', () => {
  const panjang = 'C|1|I|' + 'x'.repeat(600) + '|G';
  const hasil = astm.bangunBingkai([panjang], 1);

  assert.ok(hasil.length > 1, 'rekaman panjang harus terpecah');

  const teks = hasil.map((b) => b.toString('latin1'));
  // Semua bingkai kecuali terakhir memakai ETB.
  for (let i = 0; i < teks.length - 1; i++) {
    assert.ok(teks[i].includes('\x17'), `bingkai ${i} seharusnya diakhiri ETB`);
  }
  assert.ok(teks[teks.length - 1].includes('\x03'), 'bingkai terakhir diakhiri ETX');
});

test('sesi menjawab ACK untuk bingkai yang benar dan NAK bila checksum salah', () => {
  const keluar = [];
  const sesi = new astm.AstmSession({
    write: (b) => keluar.push(...b),
    logger: loggerSenyap,
    nama: 'UJI',
  });

  sesi.feed(ENQ);
  assert.strictEqual(keluar.pop(), ACK, 'ENQ dijawab ACK');

  sesi.feed(bingkai('H|\\^&|||Uji^1.0|||||||P|1|20260831120000', 1));
  assert.strictEqual(keluar.pop(), ACK, 'bingkai sah dijawab ACK');

  // Bingkai dengan checksum sengaja dirusak.
  const rusak = Buffer.from(STX + '2P|1||X||\r' + ETX + 'ZZ\r\n', 'latin1');
  sesi.feed(rusak);
  assert.strictEqual(keluar.pop(), NAK, 'checksum salah dijawab NAK');

  sesi.hancurkan();
});

test('sesi merakit rekaman dan memancarkannya setelah EOT', () => {
  const sesi = new astm.AstmSession({
    write: () => {},
    logger: loggerSenyap,
    nama: 'UJI',
  });

  let diterima = null;
  sesi.on('records', (records) => { diterima = records; });

  sesi.feed(ENQ);
  sesi.feed(bingkai('H|\\^&|||Uji^1.0|||||||P|1|20260831120000', 1));
  sesi.feed(bingkai('P|1||000123||BUDI^SANTOSO||19900115|M', 2));
  sesi.feed(bingkai('O|1|2608310001||^^^ALL|R|20260831120000', 3));
  sesi.feed(bingkai('R|1|^^^HGB^1|6.4|g/dL||L||F||op||20260831121500|Sim', 4));
  sesi.feed(bingkai('L|1|N', 5));
  sesi.feed(EOT);

  assert.ok(Array.isArray(diterima), 'peristiwa records harus terpancar');
  assert.strictEqual(diterima.length, 5);
  assert.ok(diterima[3].startsWith('R|1|'));

  sesi.hancurkan();
});

test('sesi merakit rekaman yang terpecah lintas bingkai ETB', () => {
  const sesi = new astm.AstmSession({
    write: () => {},
    logger: loggerSenyap,
    nama: 'UJI',
  });

  let diterima = null;
  sesi.on('records', (records) => { diterima = records; });

  // Rekaman panjang dipecah oleh bangunBingkai, lalu diumpankan kembali.
  const panjang = 'C|1|I|' + 'y'.repeat(400) + '|G';
  const potongan = astm.bangunBingkai([panjang], 1);

  sesi.feed(ENQ);
  for (const p of potongan) {
    sesi.feed(p);
  }
  sesi.feed(EOT);

  assert.strictEqual(diterima.length, 1, 'potongan harus dirakit menjadi satu rekaman');
  assert.strictEqual(diterima[0], panjang);

  sesi.hancurkan();
});

test('sesi menerima bingkai yang tiba terpotong byte demi byte', () => {
  const sesi = new astm.AstmSession({
    write: () => {},
    logger: loggerSenyap,
    nama: 'UJI',
  });

  let diterima = null;
  sesi.on('records', (records) => { diterima = records; });

  const semua = Buffer.concat([
    ENQ,
    bingkai('H|\\^&|||Uji|||||||P|1|20260831120000', 1),
    bingkai('R|1|^^^K^1|6.9|mmol/L||HH||F', 2),
    bingkai('L|1|N', 3),
    EOT,
  ]);

  // Umpankan satu byte pada satu waktu — meniru saluran serial lambat.
  for (const byte of semua) {
    sesi.feed(Buffer.from([byte]));
  }

  assert.strictEqual(diterima.length, 3);
  assert.ok(diterima[1].includes('6.9'));

  sesi.hancurkan();
});

test('rekaman Q memicu peristiwa query dengan sample id yang benar', () => {
  const sesi = new astm.AstmSession({
    write: () => {},
    logger: loggerSenyap,
    nama: 'UJI',
  });

  let query = null;
  sesi.on('query', (q) => { query = q; });

  sesi.feed(ENQ);
  sesi.feed(bingkai('Q|1|^2608310001||ALL||||||||O', 1));

  assert.ok(query !== null, 'peristiwa query harus terpancar');
  assert.strictEqual(query.sampleId, '2608310001');

  sesi.hancurkan();
});

test('keNormal menerjemahkan rekaman menjadi payload LIS', () => {
  const records = [
    'H|\\^&|||Sim^1.0|||||||P|1|20260831120000',
    'P|1||000123||BUDI^SANTOSO||19900115|M',
    'O|1|2608310001|2608310001|^^^ALL|R|20260831120000|20260831115000',
    'R|1|^^^WBC^1|15.80|10*3/uL||H||F||op||20260831121500|Sim',
    'R|2|^^^HGB^1|6.4|g/dL||L||F||op||20260831121500|Sim',
    'C|1|I|Sampel lipemik|G',
    'L|1|N',
  ];

  const payload = astm.keNormal(records, 'HEMA-01', 'mentah');

  assert.strictEqual(payload.instrument_code, 'HEMA-01');
  assert.strictEqual(payload.protocol, 'astm');
  assert.strictEqual(payload.samples.length, 1);

  const s = payload.samples[0];
  assert.strictEqual(s.sample_id, '2608310001');
  assert.strictEqual(s.patient.name, 'BUDI SANTOSO');
  assert.strictEqual(s.patient.sex, 'M');
  assert.strictEqual(s.patient.birthdate, '1990-01-15 00:00:00');
  assert.strictEqual(s.collected_at, '2026-08-31 11:50:00');

  assert.strictEqual(s.results.length, 2);
  assert.deepStrictEqual(
    s.results.map((r) => [r.code, r.value, r.unit, r.flags]),
    [['WBC', '15.80', '10*3/uL', 'H'], ['HGB', '6.4', 'g/dL', 'L']]
  );
  assert.strictEqual(s.results[0].completed_at, '2026-08-31 12:15:00');
  assert.strictEqual(s.results[1].comment, 'Sampel lipemik');
});

test('keNormal memisahkan beberapa sampel dalam satu sesi', () => {
  const records = [
    'H|\\^&|||Sim|||||||P|1|20260831120000',
    'O|1|SAMPEL-A||^^^ALL|R|20260831120000',
    'R|1|^^^GDS|110|mg/dL||N||F',
    'O|2|SAMPEL-B||^^^ALL|R|20260831120500',
    'R|1|^^^GDS|243|mg/dL||H||F',
    'L|1|N',
  ];

  const payload = astm.keNormal(records, 'KIMIA-01');

  assert.strictEqual(payload.samples.length, 2);
  assert.strictEqual(payload.samples[0].sample_id, 'SAMPEL-A');
  assert.strictEqual(payload.samples[1].sample_id, 'SAMPEL-B');
  assert.strictEqual(payload.samples[1].results[0].value, '243');
});

test('bacaKodeTes menangani berbagai bentuk universal test id', () => {
  assert.strictEqual(astm.bacaKodeTes('^^^WBC'), 'WBC');
  assert.strictEqual(astm.bacaKodeTes('^^^WBC^1'), 'WBC');
  assert.strictEqual(astm.bacaKodeTes('WBC'), 'WBC');
  assert.strictEqual(astm.bacaKodeTes('^^^^'), '');
  assert.strictEqual(astm.bacaKodeTes(''), '');
});

test('bangunJawabanQuery menyusun rekaman worklist yang sah', () => {
  const records = astm.bangunJawabanQuery({
    sample_id: '2608310001',
    priority: 'S',
    patient: { id: '000123', name: 'BUDI SANTOSO', sex: 'L', birthdate: '1990-01-15' },
    tests: ['WBC', 'HGB', 'PLT'],
  }, 'LIS');

  assert.ok(records[0].startsWith('H|\\^&|'));
  assert.ok(records[1].startsWith('P|1|'));
  assert.ok(records[1].includes('BUDI SANTOSO'));
  assert.ok(records[1].includes('19900115'));
  assert.ok(records[1].endsWith('|M'));

  const o = records[2];
  assert.ok(o.startsWith('O|1|2608310001'));
  assert.ok(o.includes('^^^WBC\\^^^HGB\\^^^PLT'));
  assert.ok(o.includes('|S|'), 'prioritas cito harus tercermin');

  assert.strictEqual(records[3], 'L|1|F');
});

test('bangunJawabanQuery menandai "tidak ada informasi" bila worklist kosong', () => {
  const records = astm.bangunJawabanQuery(null, 'LIS');

  assert.strictEqual(records.length, 2);
  assert.strictEqual(records[1], 'L|1|I');
});

test('jawaban query dapat dirakit ulang oleh penerima ASTM', () => {
  // Uji putar-balik: bingkai yang kami kirim harus dapat dibaca kembali.
  const records = astm.bangunJawabanQuery({
    sample_id: '2608310001',
    patient: { id: '000123', name: 'BUDI', sex: 'P', birthdate: '1990-01-15' },
    tests: ['GDS', 'UREUM'],
  }, 'LIS');

  const sesi = new astm.AstmSession({
    write: () => {},
    logger: loggerSenyap,
    nama: 'UJI',
  });

  let diterima = null;
  sesi.on('records', (r) => { diterima = r; });

  sesi.feed(ENQ);
  for (const b of astm.bangunBingkai(records, 1)) {
    sesi.feed(b);
  }
  sesi.feed(EOT);

  assert.deepStrictEqual(diterima, records);
  sesi.hancurkan();
});

test('pengiriman ditahan selama alat masih menguasai saluran', () => {
  const keluar = [];
  const sesi = new astm.AstmSession({
    write: (b) => keluar.push(...b),
    logger: loggerSenyap,
    nama: 'UJI',
  });

  sesi.feed(ENQ);                                   // alat membuka sesi
  sesi.feed(bingkai('Q|1|^2608310001||ALL||||||||O', 1));

  // Middleware mencoba menjawab di tengah sesi alat.
  sesi.sendRecords(['H|\\^&|||LIS', 'L|1|F']);

  assert.ok(
    !keluar.includes(0x05),
    'ENQ dari host tidak boleh dikirim selama sesi alat berlangsung'
  );
  assert.strictEqual(sesi.tertunda.length, 1, 'pengiriman harus masuk antrian');

  keluar.length = 0;
  sesi.feed(bingkai('L|1|N', 2));
  sesi.feed(EOT);                                   // alat melepas saluran

  assert.ok(keluar.includes(0x05), 'ENQ dikirim setelah alat mengirim EOT');
  assert.strictEqual(sesi.tertunda.length, 0);

  sesi.hancurkan();
});

test('tabrakan ENQ diselesaikan dengan menyerahkan saluran kepada alat', () => {
  const keluar = [];
  const sesi = new astm.AstmSession({
    write: (b) => keluar.push(...b),
    logger: loggerSenyap,
    nama: 'UJI',
  });

  // Saluran menganggur: host mulai mengirim lebih dulu.
  sesi.sendRecords(['H|\\^&|||LIS', 'L|1|F']);
  assert.strictEqual(keluar.pop(), 0x05, 'host mengirim ENQ');

  // Alat ternyata juga mengirim ENQ pada saat bersamaan.
  sesi.feed(ENQ);

  assert.strictEqual(keluar.pop(), ACK, 'host menjawab ACK, saluran diserahkan');
  assert.strictEqual(sesi.kirim, null, 'pengiriman host dibatalkan');
  assert.strictEqual(sesi.tertunda.length, 1, 'pengiriman host ditahan untuk nanti');

  sesi.hancurkan();
});
