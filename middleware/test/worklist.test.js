'use strict';

/**
 * Uji regresi bentuk worklist.
 *
 * Analyzer sungguhan menolak worklist yang cacat tanpa memberi pesan yang
 * berguna: sampel dijalankan dengan profil bawaan, atau diam saja. Kegagalan
 * seperti itu sangat mahal ditelusuri di lapangan, jadi bentuk kawatnya
 * dikunci di sini — bukan hanya diperiksa sekali secara manual.
 *
 * Yang dikunci:
 *   - susunan rekaman H / P / O / L pada ASTM E1394
 *   - universal test ID berbentuk ^^^KODE
 *   - kode yang dikirim adalah KODE ALAT, bukan kode LIS
 *   - jawaban untuk sampel tak dikenal: L|1|I, bukan bingkai cacat
 *   - checksum dan penomoran bingkai E1381
 *   - ORM^O01 pada HL7 memuat satu pasang ORC/OBR per pemeriksaan
 */

const test = require('node:test');
const assert = require('node:assert');

const astm = require('../src/protocols/astm');
const hl7 = require('../src/protocols/hl7');

/** Worklist seperti yang dikembalikan API LIS. */
const WORKLIST = {
  sample_id: '2609020008',
  order_no: 'LIS-260902-0004',
  lab_no: '2609020004',
  priority: 'S',
  patient: { id: '000123', name: 'BUDI SANTOSO', sex: 'L', birthdate: '1990-01-15' },
  // Kode alat, bukan kode LIS: HGB bukan HB, HCT bukan HT.
  tests: ['HGB', 'HCT', 'RBC', 'WBC', 'PLT']
};

// ---------------------------------------------------------------- ASTM

test('ASTM: jawaban worklist tersusun H, P, O, L', () => {
  const rekaman = astm.bangunJawabanQuery(WORKLIST);
  const jenis = rekaman.map((r) => r[0]);

  assert.deepStrictEqual(jenis, ['H', 'P', 'O', 'L']);
});

test('ASTM: header mengumumkan pembatas |\\^& sesuai E1394', () => {
  const [h] = astm.bangunJawabanQuery(WORKLIST);

  assert.ok(h.startsWith('H|\\^&'), `header tidak sesuai: ${h}`);
});

test('ASTM: rekaman O memuat sample ID dan universal test ID ^^^KODE', () => {
  const rekaman = astm.bangunJawabanQuery(WORKLIST);
  const o = rekaman.find((r) => r.startsWith('O|'));
  const medan = o.split('|');

  assert.strictEqual(medan[2], '2609020008', 'sample ID harus ada di medan spesimen');

  const uji = medan[4].split('\\');
  assert.strictEqual(uji.length, WORKLIST.tests.length);

  for (const u of uji) {
    const k = u.split('^');
    assert.strictEqual(k.length, 4, `universal test ID harus ^^^KODE, dapat: ${u}`);
    assert.strictEqual(k[0], '');
    assert.strictEqual(k[1], '');
    assert.strictEqual(k[2], '');
    assert.ok(k[3].length > 0, 'komponen keempat wajib berisi kode');
  }
});

test('ASTM: kode yang dikirim adalah kode alat, bukan kode LIS', () => {
  const rekaman = astm.bangunJawabanQuery(WORKLIST);
  const o = rekaman.find((r) => r.startsWith('O|'));
  const kode = o.split('|')[4].split('\\').map((u) => u.split('^')[3]);

  assert.deepStrictEqual(kode, WORKLIST.tests);
  assert.ok(kode.includes('HGB'), 'HGB (kode alat) harus terkirim');
  assert.ok(!kode.includes('HB'), 'HB (kode LIS) tidak boleh bocor ke alat');
});

test('ASTM: prioritas CITO dikirim sebagai S', () => {
  const o = astm.bangunJawabanQuery(WORKLIST).find((r) => r.startsWith('O|'));

  assert.strictEqual(o.split('|')[5], 'S');
});

test('ASTM: prioritas rutin dikirim sebagai R', () => {
  const o = astm
    .bangunJawabanQuery(Object.assign({}, WORKLIST, { priority: 'R' }))
    .find((r) => r.startsWith('O|'));

  assert.strictEqual(o.split('|')[5], 'R');
});

test('ASTM: rekaman P memuat identitas pasien dengan jenis kelamin E1394', () => {
  const p = astm.bangunJawabanQuery(WORKLIST).find((r) => r.startsWith('P|'));
  const medan = p.split('|');

  assert.strictEqual(medan[3], '000123', 'nomor rekam medis');
  assert.strictEqual(medan[5], 'BUDI SANTOSO', 'nama pasien');
  assert.strictEqual(medan[7], '19900115', 'tanggal lahir YYYYMMDD');
  assert.strictEqual(medan[8], 'M', 'L harus dipetakan ke M');
});

test('ASTM: jenis kelamin P dipetakan ke F', () => {
  const w = Object.assign({}, WORKLIST, {
    patient: Object.assign({}, WORKLIST.patient, { sex: 'P' })
  });
  const p = astm.bangunJawabanQuery(w).find((r) => r.startsWith('P|'));

  assert.strictEqual(p.split('|')[8], 'F');
});

test('ASTM: sampel tak dikenal dijawab L|1|I, bukan bingkai cacat', () => {
  for (const kosong of [null, undefined, Object.assign({}, WORKLIST, { tests: [] })]) {
    const rekaman = astm.bangunJawabanQuery(kosong);

    assert.strictEqual(rekaman[0][0], 'H', 'tetap diawali header');
    const l = rekaman[rekaman.length - 1];
    assert.strictEqual(l, 'L|1|I', `terminator harus L|1|I, dapat: ${l}`);
    assert.ok(!rekaman.some((r) => r.startsWith('O|')),
      'tidak boleh ada rekaman order kosong');
  }
});

test('ASTM: worklist berisi diakhiri L|1|F', () => {
  const rekaman = astm.bangunJawabanQuery(WORKLIST);

  assert.strictEqual(rekaman[rekaman.length - 1], 'L|1|F');
});

test('ASTM: bingkai worklist bernomor urut dengan checksum sah', () => {
  const rekaman = astm.bangunJawabanQuery(WORKLIST);
  const bingkai = astm.bangunBingkai(rekaman, 1);

  bingkai.forEach((b, i) => {
    const s = b.toString('latin1');

    assert.strictEqual(s.charCodeAt(0), 0x02, 'diawali STX');
    assert.strictEqual(s[1], String((i + 1) % 8), 'nomor bingkai berurutan');

    const akhir = Math.max(s.indexOf('\x03'), s.indexOf('\x17'));
    assert.ok(akhir > 0, 'harus ditutup ETX atau ETB');

    const inti = s.slice(1, akhir + 1);
    const csTertulis = s.slice(akhir + 1, akhir + 3);

    assert.strictEqual(csTertulis, astm.checksum(inti),
      `checksum bingkai ${i + 1} tidak cocok`);
  });
});

// ----------------------------------------------------------------- HL7

const QUERY_HL7 = [
  'MSH|^~\\&|SimChem|LAB|LIS|LAB|20260902133059||QRY^Q02|MSG001|P|2.4',
  'QRD|20260902133059|R|I|20260902133059|||1^RD|2609020010|OTH|||T'
].join('\r');

test('HL7: worklist dijawab ORM^O01', () => {
  const balas = hl7.bangunOrmWorklist(WORKLIST, QUERY_HL7);

  assert.ok(balas.includes('ORM^O01'), 'jenis pesan harus ORM^O01');
  assert.ok(balas.startsWith('MSH|^~\\&|'), 'diawali MSH dengan pembatas baku');
});

test('HL7: satu pasang ORC/OBR per pemeriksaan', () => {
  const balas = hl7.bangunOrmWorklist(WORKLIST, QUERY_HL7);
  const baris = balas.split('\r').filter((b) => b !== '');

  const orc = baris.filter((b) => b.startsWith('ORC|'));
  const obr = baris.filter((b) => b.startsWith('OBR|'));

  assert.strictEqual(orc.length, WORKLIST.tests.length);
  assert.strictEqual(obr.length, WORKLIST.tests.length);
});

test('HL7: OBR memuat kode alat dan nomor urut yang benar', () => {
  const balas = hl7.bangunOrmWorklist(WORKLIST, QUERY_HL7);
  const obr = balas.split('\r').filter((b) => b.startsWith('OBR|'));

  obr.forEach((b, i) => {
    const medan = b.split('|');
    assert.strictEqual(medan[1], String(i + 1), 'OBR-1 berurutan mulai dari 1');
    assert.strictEqual(medan[2], WORKLIST.sample_id, 'OBR-2 memuat sample ID');
    assert.strictEqual(medan[4].split('^')[0], WORKLIST.tests[i], 'OBR-4 memuat kode alat');
    assert.strictEqual(medan[5], 'S', 'prioritas CITO diteruskan sebagai S');
  });
});

test('HL7: PID memuat identitas pasien', () => {
  const balas = hl7.bangunOrmWorklist(WORKLIST, QUERY_HL7);
  const pid = balas.split('\r').find((b) => b.startsWith('PID|'));

  assert.ok(pid, 'PID wajib ada');
  assert.ok(pid.includes('000123'), 'memuat nomor rekam medis');
  assert.ok(pid.includes('BUDI SANTOSO'), 'memuat nama pasien');
});

test('HL7: sampel tak dikenal tidak menghasilkan ORM berisi order kosong', () => {
  for (const kosong of [null, undefined, Object.assign({}, WORKLIST, { tests: [] })]) {
    const balas = hl7.bangunOrmWorklist(kosong, QUERY_HL7);

    if (balas === null || balas === '') continue; // tidak menjawab juga sah

    const obr = balas.split('\r').filter((b) => b.startsWith('OBR|'));
    assert.strictEqual(obr.length, 0, 'tidak boleh ada OBR tanpa pemeriksaan');
  }
});

// -------------------------------------------- Dialek HL7 per analyzer

const QRY_231 = [
  'MSH|^~\\&|BC-5000|LAB|LIS|LAB|20260903||QRY^Q02|MSG1|P|2.3.1',
  'QRD|20260903|R|I|Q1|||1^RD|2609020008|OTH|||T'
].join('\r');

const QBP_24 = [
  'MSH|^~\\&|BC-5000|LAB|LIS|LAB|20260903||QBP^Q11|MSG2|P|2.4',
  'QPD|WOS^Work Order Step|TAG9|2609020008'
].join('\r');

test('HL7: versi balasan mencerminkan MSH-12 analyzer, bukan dipatok', () => {
  const v231 = hl7.bangunOrmWorklist(WORKLIST, QRY_231);
  const v24 = hl7.bangunOrmWorklist(WORKLIST, QBP_24);

  assert.ok(v231.split('\r')[0].endsWith('|2.3.1'),
    'analyzer 2.3.1 harus dijawab 2.3.1');
  assert.ok(v24.split('\r')[0].endsWith('|2.4'),
    'analyzer 2.4 harus dijawab 2.4');
});

test('HL7: ACK juga mencerminkan versi analyzer', () => {
  // MSH-12 adalah medan ke-12; MSH kini diakhiri medan charset sehingga
  // versi tidak lagi berada di ujung baris.
  const versi = (p) => hl7.bangunAck(p).split('\r')[0].split('|')[11];

  assert.strictEqual(versi(QRY_231), '2.3.1');
  assert.strictEqual(versi(QBP_24), '2.4');
});

test('HL7: versi tidak masuk akal jatuh ke bawaan 2.3.1', () => {
  const rusak = 'MSH|^~\\&|X|LAB|LIS|LAB|20260903||QRY^Q02|M|P|BUKANVERSI\r';

  assert.strictEqual(hl7.versiHl7(rusak), '2.3.1');
});

test('HL7: QBP^Q11 dijawab RSP^K11 lengkap dengan MSA dan QAK', () => {
  const balas = hl7.bangunOrmWorklist(WORKLIST, QBP_24);
  const baris = balas.split('\r').filter((b) => b !== '');

  assert.ok(baris[0].includes('RSP^K11'), 'QBP harus dijawab RSP^K11');
  assert.ok(baris[1].startsWith('MSA|AA|MSG2'), 'RSP wajib membawa MSA');
  assert.ok(baris[2].startsWith('QAK|TAG9|OK'), 'RSP wajib membawa QAK');
});

test('HL7: QRY^Q02 tetap dijawab ORM^O01', () => {
  assert.ok(hl7.bangunOrmWorklist(WORKLIST, QRY_231).includes('ORM^O01'));
});

test('HL7: MSH-6 diisi fasilitas penerima, tidak dibiarkan kosong', () => {
  const medan = hl7.bangunOrmWorklist(WORKLIST, QRY_231).split('\r')[0].split('|');

  assert.strictEqual(medan[4], 'BC-5000', 'MSH-5 = pengirim query');
  assert.strictEqual(medan[5], 'LAB', 'MSH-6 = fasilitas pengirim query');
});

test('HL7: pengaman "off" tidak pernah mengirim order', () => {
  const balas = hl7.bangunOrmWorklist(WORKLIST, QRY_231, 'LIS', 'off');

  assert.ok(/\|ACK(\^[A-Z0-9]+)?\|/.test(balas), 'hanya ACK yang dikirim');
  assert.ok(!balas.includes('OBR|'), 'tidak boleh ada satu pun OBR');
  assert.ok(!balas.includes('ORC|'), 'tidak boleh ada satu pun ORC');
});

// -------------------------------------- Dialek Mindray (ORM^O01 → ORR^O02)
//
// Sumber: BC-5800 LIS Protocol Manual (P/N 046-000243-00), bagian D.4.2
// dan Tabel 12-15. Analyzer meminta order dengan ORM^O01 berisi
// ORC|RF||<SampleID>||IP; LIS menjawab ORR^O02 berisi ORC|AF|<SampleID>.

const ORM_MINDRAY = [
  'MSH|^~\\&|BC-5000|Mindray|LIS|LAB|20260903||ORM^O01|7|P|2.3.1||||||UNICODE',
  'ORC|RF||2609020008||IP'
].join('\r');

const W_MINDRAY = Object.assign({}, WORKLIST, {
  department: 'IGD',
  bed: 'BN12',
  patient: { id: '11112', name: 'Aqila Nurhaliza', sex: 'P', birthdate: '2019-04-22' },
  tests: ['6690-2', '789-8', '718-7', '770-8', '736-9']
});

test('Mindray: ORM^O01 dijawab ORR^O02, bukan ORM', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');
  const msh = balas.split('\r')[0].split('|');

  assert.strictEqual(msh[8], 'ORR^O02',
    'manual: "ORR^O02 ... Affirming of the ORM^O01 message"');
});

test('Mindray: sample ID dibaca dari ORC-3 permintaan', () => {
  assert.strictEqual(hl7.sampleIdQuery(ORM_MINDRAY), '2609020008');
});

test('Mindray: balasan memuat ORC|AF dengan sample ID pada ORC-2', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');
  const orc = balas.split('\r').find((b) => b.startsWith('ORC|'));

  assert.ok(orc, 'ORC wajib ada pada balasan berisi order');
  assert.strictEqual(orc.split('|')[1], 'AF', 'AF = affirm the re-filled order');
  assert.strictEqual(orc.split('|')[2], '2609020008', 'ORC-2 memuat sample ID');
});

test('Mindray: MSA menggemakan control id permintaan', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');
  const msa = balas.split('\r').find((b) => b.startsWith('MSA|'));

  assert.strictEqual(msa, 'MSA|AA|7');
});

test('Mindray: order tak ditemukan dijawab MSA|AR tanpa ORC', () => {
  const balas = hl7.bangunOrmWorklist(null, ORM_MINDRAY, 'LIS', 'mindray');

  assert.ok(balas.includes('ORR^O02'), 'tetap ORR^O02 yang sah');
  assert.ok(balas.includes('MSA|AR|7'), 'ditolak lembut lewat MSA|AR');
  assert.ok(!balas.includes('ORC|'), 'tidak boleh ada ORC tanpa order');
  assert.ok(!balas.includes('OBR|'), 'tidak boleh ada OBR tanpa order');
});

test('Mindray: PID memakai bentuk ID^^^^MR dan gender kata utuh', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');
  const pid = balas.split('\r').find((b) => b.startsWith('PID|')).split('|');

  assert.strictEqual(pid[3], '11112^^^^MR', 'PID-3 berbentuk "patient ID^^^^MR"');
  assert.strictEqual(pid[5], 'Aqila Nurhaliza', 'PID-5 nama pasien');
  assert.strictEqual(pid[8], 'Female', 'PID-8 berupa kata, bukan huruf F');
});

test('Mindray: PV1-3 berbentuk "Department^^Bed No."', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');
  const pv1 = balas.split('\r').find((b) => b.startsWith('PV1|'));

  assert.strictEqual(pv1.split('|')[3], 'IGD^^BN12');
});

test('Mindray: mode uji CBC+5DIFF bila hitung jenis diminta', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');

  assert.ok(balas.includes('08003^Test Mode^99MRC||CBC+5DIFF'),
    'NEU%/LYM% pada order berarti mode 5-diff');
});

test('Mindray: mode uji CBC bila hanya parameter dasar', () => {
  const w = Object.assign({}, W_MINDRAY, { tests: ['6690-2', '789-8', '718-7'] });
  const balas = hl7.bangunOrmWorklist(w, ORM_MINDRAY, 'LIS', 'mindray');

  assert.ok(balas.includes('08003^Test Mode^99MRC||CBC|'),
    'tanpa hitung jenis, mode cukup CBC');
});

test('Mindray: modeUji mengenali kode diff LOINC maupun singkatan', () => {
  assert.strictEqual(hl7.modeUji(['6690-2', '770-8']), 'CBC+5DIFF');
  assert.strictEqual(hl7.modeUji(['6690-2', '718-7']), 'CBC');
  assert.strictEqual(hl7.modeUji([]), 'CBC');
});

test('Mindray: balasan mencerminkan charset dan versi alat', () => {
  const balas = hl7.bangunOrmWorklist(W_MINDRAY, ORM_MINDRAY, 'LIS', 'mindray');
  const msh = balas.split('\r')[0].split('|');

  assert.strictEqual(msh[11], '2.3.1');
  assert.strictEqual(msh[17], 'UNICODE');
});

// ------------------------------------- ACK sesuai spesifikasi Mindray

test('ACK: MSH-9 mencerminkan trigger event, ACK^R01 untuk ORU^R01', () => {
  const oru = 'MSH|^~\\&|BC-5000|Mindray|LIS|LAB|20101206164344||ORU^R01|1|P|2.3.1||||||UNICODE\r';
  const msh = hl7.bangunAck(oru, 'LIS').split('\r')[0].split('|');

  assert.strictEqual(msh[8], 'ACK^R01',
    'manual Mindray: "the MSH-9 field should be ACK^R01"');
});

test('ACK: MSH-18 menggemakan charset alat (UNICODE)', () => {
  const oru = 'MSH|^~\\&|BC-5000|Mindray|LIS|LAB|20101206164344||ORU^R01|1|P|2.3.1||||||UNICODE\r';
  const msh = hl7.bangunAck(oru, 'LIS').split('\r')[0].split('|');

  assert.strictEqual(msh[17], 'UNICODE');
});

test('ACK: MSH-11 menggemakan processing ID (Q untuk pesan QC)', () => {
  const qc = 'MSH|^~\\&|BC-5000|Mindray|LIS|LAB|20101206164344||ORU^R01|9|Q|2.3.1||||||UNICODE\r';
  const msh = hl7.bangunAck(qc, 'LIS').split('\r')[0].split('|');

  assert.strictEqual(msh[10], 'Q', 'pesan QC harus dibalas dengan processing ID Q');
});

test('ACK: MSA-2 sama dengan MSH-10 pesan yang diterima', () => {
  const oru = 'MSH|^~\\&|BC-5000|Mindray|LIS|LAB|20101206164344||ORU^R01|12345|P|2.3.1\r';
  const msa = hl7.bangunAck(oru, 'LIS').split('\r').find((b) => b.startsWith('MSA|'));

  assert.strictEqual(msa.split('|')[2], '12345');
});

test('HL7: sesi membaca UTF-8 secara bawaan, bukan latin1', () => {
  const potongan = [];
  const sesi = new hl7.Hl7Session({
    write: (b) => potongan.push(b),
    logger: { aktifTrace: () => false, trace: () => {}, debug: () => {}, warn: () => {} },
    nama: 'uji'
  });

  assert.strictEqual(sesi.encoding, 'utf8');

  // Nama pasien beraksen harus utuh melewati kerangka MLLP.
  const pesan = 'MSH|^~\\&|A|B|C|D|20260903||ORU^R01|1|P|2.3.1||||||UNICODE\r'
    + 'PID|1||1||Ário^Núñez||19900101|Male\r';

  const bingkai = Buffer.concat([
    Buffer.from([0x0b]), Buffer.from(pesan, 'utf8'), Buffer.from([0x1c, 0x0d])
  ]);

  let diterima = null;
  sesi.on('message', (m) => { diterima = m; });
  sesi.feed(bingkai);

  assert.ok(diterima !== null, 'pesan harus terbaca utuh');
  assert.ok(diterima.includes('Ário^Núñez'),
    'huruf beraksen harus utuh, bukan rusak menjadi byte latin1');
});

// =====================================================================
//  Kesesuaian terhadap spesifikasi Mindray
//
//  Empat hal berikut adalah persyaratan protokol, bukan pilihan gaya.
//  Melanggarnya membuat alat menerima data yang tidak dapat diurai, atau
//  membuat LIS salah menafsirkan data yang diterimanya.
// =====================================================================

const PEMBATAS = { field: '|', component: '^', repeat: '~', escape: '\\', subcomponent: '&' };

test('Escape: pembatas di dalam teks dipulihkan utuh pulang-pergi', () => {
  const asli = 'Siti & Rekan ^ Ny|A~B';
  const lewat = hl7.bukaEscape(hl7.pasangEscape(asli, PEMBATAS), PEMBATAS);

  assert.strictEqual(lewat, asli, 'data tidak boleh berubah saat melewati escape');
});

test('Escape: karakter escape sendiri ditangani lebih dulu', () => {
  const asli = 'a\\b';

  assert.strictEqual(hl7.pasangEscape(asli, PEMBATAS), 'a\\E\\b');
  assert.strictEqual(hl7.bukaEscape('a\\E\\b', PEMBATAS), asli);
});

test('Escape: \\.br\\ dipulihkan menjadi CR sesuai tabel spesifikasi', () => {
  assert.strictEqual(hl7.bukaEscape('baris1\\.br\\baris2', PEMBATAS), 'baris1\rbaris2');
});

test('Escape: urutan tak berpasangan tidak merusak sisa teks', () => {
  assert.strictEqual(hl7.bukaEscape('nama \\S tanpa penutup', PEMBATAS),
    'nama \\S tanpa penutup');
});

test('Hasil: nama pasien beresc ape dibaca sebagai karakter aslinya', () => {
  const pesan = 'MSH|^~\\&|BC|M|LIS|LAB|20260903||ORU^R01|1|P|2.3.1||||||UNICODE\r'
    + 'PID|1||11112^^^^MR||Siti \\T\\ Rekan||19900101|Female\r'
    + 'OBR|1|2609020008|2609020008|00001^Automated Count^99MRC\r'
    + 'OBX|1|NM|718-7^HGB^LN||140|g/L|130-175|N|||F\r';

  const p = hl7.keNormal(pesan, 'HEMA-03');

  assert.strictEqual(p.samples[0].patient.name, 'Siti & Rekan');
});

test('Hasil: OBX bertipe ED (histogram base64) tidak masuk aliran hasil', () => {
  const pesan = 'MSH|^~\\&|BC|M|LIS|LAB|20260903||ORU^R01|1|P|2.3.1||||||UNICODE\r'
    + 'OBR|1|2609020008|2609020008|00001^Automated Count^99MRC\r'
    + 'OBX|1|NM|718-7^HGB^LN||140|g/L|130-175|N|||F\r'
    + 'OBX|2|ED|15000^WBC Histogram. Binary^99MRC||^Application^Octer-stream^Base64^AAAA==|||||F\r'
    + 'OBX|3|NM|777-3^PLT^LN||250|10*9/L|100-300|N|||F\r';

  const p = hl7.keNormal(pesan, 'HEMA-03');
  const kode = p.samples[0].results.map((r) => r.code);

  assert.deepStrictEqual(kode, ['718-7', '777-3'], 'data biner harus dilewati');
});

test('QC: MSH-11 Q, D dan T dikenali sebagai kontrol mutu', () => {
  const buat = (pid) =>
    `MSH|^~\\&|BC-5000|Mindray|LIS|LAB|20260903||ORU^R01|1|${pid}|2.3.1||||||UNICODE\r`;

  assert.strictEqual(hl7.adalahQc(buat('P')), false, 'P adalah hasil pasien');
  assert.strictEqual(hl7.adalahQc(buat('Q')), true, 'Q = hasil QC (BC-5000)');
  assert.strictEqual(hl7.adalahQc(buat('D')), true, 'D = pengaturan QC (BC-5800)');
  assert.strictEqual(hl7.adalahQc(buat('T')), true, 'T = hasil QC (BC-5800)');
});

test('MLLP: heartbeat 0x02 tidak menumpuk di buffer', () => {
  const log = { aktifTrace: () => false, trace: () => {}, warn: () => {}, debug: () => {} };
  const sesi = new hl7.Hl7Session({ write: () => {}, logger: log, nama: 'uji' });

  let n = 0;
  sesi.on('message', () => { n += 1; });

  const pesan = 'MSH|^~\\&|A|B|C|D|20260903||ORU^R01|1|P|2.3.1||||||UNICODE\r';
  const bingkai = Buffer.concat([
    Buffer.from([0x0b]), Buffer.from(pesan, 'utf8'), Buffer.from([0x1c, 0x0d])
  ]);

  // Analyzer Mindray mengirim 0x02 tiap 3 detik di sela pesan.
  for (let i = 0; i < 300; i++) sesi.feed(Buffer.from([0x02]));
  sesi.feed(bingkai);
  for (let i = 0; i < 300; i++) sesi.feed(Buffer.from([0x02]));
  sesi.feed(bingkai);
  for (let i = 0; i < 300; i++) sesi.feed(Buffer.from([0x02]));

  assert.strictEqual(n, 2, 'kedua pesan tetap terbaca di sela heartbeat');
  assert.strictEqual(sesi.buffer.length, 0, 'buffer tidak boleh menyimpan derau');
});

test('MLLP: pesan terpotong tidak menahan pesan berikutnya selamanya', () => {
  const log = { aktifTrace: () => false, trace: () => {}, warn: () => {}, debug: () => {} };
  const sesi = new hl7.Hl7Session({ write: () => {}, logger: log, nama: 'uji' });

  let n = 0;
  sesi.on('message', () => { n += 1; });

  // Potongan pertama tiba tanpa penutup, lalu menyusul.
  sesi.feed(Buffer.concat([Buffer.from([0x0b]), Buffer.from('MSH|^~\\&|A', 'utf8')]));
  assert.strictEqual(n, 0, 'pesan belum lengkap belum boleh diteruskan');

  sesi.feed(Buffer.concat([
    Buffer.from('|B|C|D|20260903||ORU^R01|1|P|2.3.1\r', 'utf8'),
    Buffer.from([0x1c, 0x0d])
  ]));

  assert.strictEqual(n, 1, 'pesan lengkap setelah potongan kedua');
});
