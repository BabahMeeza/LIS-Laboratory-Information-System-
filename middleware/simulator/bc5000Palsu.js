/**
 * BC-5000 palsu — meniru topologi Mindray BC-5000 yang sebenarnya.
 *
 * Berbeda dari simulator lain, alat ini berperan sebagai TCP SERVER: ia
 * mendengarkan dan menunggu LIS menyambung, persis seperti BC-5000 yang
 * layar komunikasinya hanya memuat alamat dirinya sendiri tanpa medan
 * tujuan. Setelah LIS tersambung, alat mendorong hasil ORU^R01 lalu
 * MENUNGGU ACK — mencontoh "ACK Synchronous Transmission" dengan batas
 * waktu yang dapat diatur pada alat (bawaan 10 detik).
 *
 * Dipakai untuk membuktikan jalur satu arah sebelum menyentuh alat asli:
 * bila ACK datang terlambat atau berkode selain AA, alat sungguhan akan
 * menganggap pengiriman gagal.
 *
 *   node simulator/bc5000Palsu.js [--port 5100] [--sample 2609020008]
 *                                 [--ack-timeout 10]
 */

'use strict';

const net = require('net');

const VT = 0x0b, FS = 0x1c, CR = 0x0d;

function arg(nama, bawaan) {
  const i = process.argv.indexOf(`--${nama}`);
  return i !== -1 && process.argv[i + 1] !== undefined ? process.argv[i + 1] : bawaan;
}

const port    = parseInt(arg('port', '5100'), 10);
const duaArah = process.argv.includes('--dua-arah');
const sample  = arg('sample', '2609020008');
const batasMs = parseInt(arg('ack-timeout', '10'), 10) * 1000;

const cap = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);

/**
 * Hasil CBC seperti yang dikirim BC-5000 sungguhan.
 *
 * Kode OBX-3 memakai bentuk "ID^Nama^EncodeSys" sesuai manual Mindray
 * Z-110-002561-00-1.0 bagian 4.7. Yang dipakai LIS untuk mengenali
 * parameter adalah ID-nya (kode LOINC), bukan namanya.
 */
const HASIL = [
  // ID        Nama     Sys    nilai     satuan SI  rujukan       flag
  ['6690-2',  'WBC',   'LN',  '15.80',  '10*9/L',  '4.00-10.00', 'H'],
  ['789-8',   'RBC',   'LN',  '3.92',   '10*12/L', '4.30-5.80',  'L'],
  ['718-7',   'HGB',   'LN',  '64',     'g/L',     '130-175',    'L'],
  ['4544-3',  'HCT',   'LN',  '21.5',   '%',       '40.0-50.0',  'L'],
  ['787-2',   'MCV',   'LN',  '84.2',   'fL',      '82.0-100.0', 'N'],
  ['785-6',   'MCH',   'LN',  '27.1',   'pg',      '27.0-34.0',  'N'],
  ['786-4',   'MCHC',  'LN',  '324',    'g/L',     '316-354',    'N'],
  ['777-3',   'PLT',   'LN',  '96',     '10*9/L',  '125-350',    'L'],
  ['770-8',   'NEU%',  'LN',  '78.4',   '%',       '50.0-70.0',  'H'],
  ['736-9',   'LYM%',  'LN',  '14.2',   '%',       '20.0-40.0',  'L'],
  // Butir non-parameter yang juga dikirim alat — harus diabaikan LIS,
  // bukan menumpuk sebagai hasil menggantung.
  ['08001',   'Take Mode',  '99MRC', 'O',   '', '', ''],
  ['08003',   'Test Mode',  '99MRC', 'CBC', '', '', ''],
  ['12014',   'Anemia',     '99MRC', 'T',   '', '', ''],
];

function pesanHasil() {
  const baris = [
    // MSH-18 "UNICODE" = pesan dikirim sebagai UTF-8, sesuai manual.
    `MSH|^~\\&|BC-5000|Mindray|LIS|LAB|${cap}||ORU^R01|${Date.now()}|P|2.3.1||||||UNICODE`,
    // PID-5 berbentuk "LastName^FirstName"; PID-8 berupa kata utuh.
    `PID|1||11112^^^^MR||Nurhaliza^Aqila||20190422000000|Female`,
    `PV1|1||IGD^^BN12`,
    `OBR|1|${sample}|${sample}|00001^Automated Count^99MRC||${cap}|${cap}`,
  ];

  HASIL.forEach(([id, nama, sys, nilai, satuan, rujukan, flag], i) => {
    const tipe = satuan === '' ? 'IS' : 'NM';
    baris.push(
      `OBX|${i + 1}|${tipe}|${id}^${nama}^${sys}||${nilai}|${satuan}|${rujukan}|${flag}|||F|||${cap}`
    );
  });

  return baris.join('\r') + '\r';
}

function bungkus(pesan) {
  return Buffer.concat([
    Buffer.from([VT]),
    Buffer.from(pesan, 'utf8'),
    Buffer.from([FS, CR]),
  ]);
}

let lulus = true;

const server = net.createServer((soket) => {
  const asal = `${soket.remoteAddress}:${soket.remotePort}`;
  console.log(`\n✓ LIS menyambung dari ${asal}`);
  console.log('  (BC-5000 asli juga menunggu disambungi, bukan menyambung sendiri)\n');

  let buf = Buffer.alloc(0);
  let dikirimPada = 0;
  let timer = null;

  // Mode dua arah: alat meminta worklist lebih dulu dengan ORM^O01,
  // menunggu ORR^O02, baru menjalankan sampelnya.
  const mintaWorklist = () => {
    const orm = [
      `MSH|^~\\&|BC-5000|Mindray|LIS|LAB|${cap}||ORM^O01|7|P|2.3.1||||||UNICODE`,
      `ORC|RF||${sample}||IP`,
    ].join('\r') + '\r';

    dikirimPada = Date.now();
    soket.write(bungkus(orm));

    console.log(`→ Meminta worklist (ORM^O01) untuk sampel ${sample}`);
    console.log('  Manual menetapkan LIS harus menjawab dalam 2 detik…\n');

    timer = setTimeout(() => {
      console.log('✗ TIDAK ADA jawaban worklist dalam 2 detik.');
      lulus = false;
      selesai();
    }, 2000);
  };

  const kirimHasil = () => {
    const pesan = pesanHasil();
    dikirimPada = Date.now();
    soket.write(bungkus(pesan));

    console.log(`→ Mengirim ORU^R01 — ${HASIL.length} parameter untuk sampel ${sample}`);
    console.log(`  Menunggu ACK, batas ${batasMs / 1000} detik…\n`);

    timer = setTimeout(() => {
      console.log(`✗ TIDAK ADA ACK dalam ${batasMs / 1000} detik.`);
      console.log('  Pada alat sungguhan ini muncul sebagai kegagalan pengiriman.');
      lulus = false;
      selesai();
    }, batasMs);
  };

  const selesai = () => {
    if (timer) clearTimeout(timer);
    soket.end();
    setTimeout(() => {
      console.log('='.repeat(60));
      console.log(lulus
        ? '✓ Jalur satu arah bekerja: hasil terkirim, ACK diterima tepat waktu.'
        : '✗ Jalur satu arah BERMASALAH. Jangan lanjut ke alat sungguhan.');
      console.log('='.repeat(60));
      server.close();
      process.exit(lulus ? 0 : 1);
    }, 200);
  };

  soket.on('data', (d) => {
    buf = Buffer.concat([buf, d]);

    let awal;
    while ((awal = buf.indexOf(VT)) !== -1) {
      const akhir = buf.indexOf(FS, awal + 1);
      if (akhir === -1) break;

      const pesan = buf.subarray(awal + 1, akhir).toString('utf8');
      buf = buf.subarray(akhir + 2);

      const jeda = Date.now() - dikirimPada;
      console.log(`← Balasan diterima setelah ${jeda} ms:`);
      pesan.split('\r').filter(Boolean).forEach((b) => console.log('    ' + b));
      console.log('');

      const msa = pesan.split('\r').find((b) => b.startsWith('MSA|'));
      const kode = msa ? msa.split('|')[1] : '(tidak ada MSA)';

      // Mode dua arah: periksa ORR^O02 lalu lanjut mengirim hasil.
      if (duaArah && (pesan.split('\r')[0] || '').includes('ORR^O02')) {
        if (timer) clearTimeout(timer);

        const orc = pesan.split('\r').find((b) => b.startsWith('ORC|'));
        const pid = pesan.split('\r').find((b) => b.startsWith('PID|'));
        const mode = (pesan.match(/08003\^Test Mode\^99MRC\|\|([^|]*)/) || [])[1] || '';

        const cekW = [
          [kode === 'AA', `MSA "${kode}" (AA = order ditemukan)`],
          [jeda < 2000, `Tiba dalam ${jeda} ms, batas manual 2000 ms`],
          [orc !== undefined && orc.split('|')[1] === 'AF',
            `ORC-1 "${orc ? orc.split('|')[1] : '-'}" (AF = affirm the re-filled order)`],
          [orc !== undefined && orc.split('|')[2] === sample,
            `ORC-2 memuat sample ID "${sample}"`],
          [pid !== undefined, 'Identitas pasien terkirim'],
          [mode !== '', `Mode pemeriksaan "${mode}"`],
        ];

        console.log('  Pemeriksaan jawaban worklist:');
        for (const [ok, teks] of cekW) {
          console.log(`  ${ok ? '✓' : '✗'} ${teks}`);
          if (!ok) lulus = false;
        }
        console.log('');
        console.log('  Alat sekarang menjalankan sampel dan mengirim hasilnya…\n');

        setTimeout(kirimHasil, 300);
        return;
      }
      const versi = (pesan.split('\r')[0] || '').split('|')[11] || '?';

      const msh = pesan.split('\r')[0] || '';
      const jenisBalas = msh.split('|')[8] || '';
      const charset = (msh.split('|')[17] || '').trim();

      const cek = [
        [msa !== undefined, 'Balasan memuat segmen MSA'],
        [kode === 'AA', `Kode ACK "${kode}" (AA = diterima)`],
        [jeda < batasMs, `Tiba dalam ${jeda} ms, batas alat ${batasMs} ms`],
        [versi === '2.3.1', `Versi HL7 balasan "${versi}" cocok dengan alat (2.3.1)`],
        [jenisBalas === 'ACK^R01',
          `MSH-9 balasan "${jenisBalas}" — manual mensyaratkan ACK^R01`],
        [charset === 'UNICODE',
          `MSH-18 balasan "${charset}" — manual mensyaratkan UNICODE`],
      ];

      for (const [ok, teks] of cek) {
        console.log(`  ${ok ? '✓' : '✗'} ${teks}`);
        if (!ok) lulus = false;
      }
      console.log('');

      selesai();
    }
  });

  soket.on('error', () => { /* diamkan */ });

  // Beri jeda sedikit agar LIS sempat menyiapkan sesinya.
  setTimeout(duaArah ? mintaWorklist : kirimHasil, 800);
});

server.listen(port, '0.0.0.0', () => {
  console.log('='.repeat(60));
  console.log('  BC-5000 palsu — mode TCP server, seperti alat sungguhan');
  console.log('='.repeat(60));
  console.log(`  Mendengarkan di 0.0.0.0:${port}`);
  console.log(`  Protokol   : HL7 v2.3.1 lewat MLLP`);
  console.log(`  ACK sinkron: ya, batas ${batasMs / 1000} detik`);
  console.log(`  Sampel     : ${sample}`);
  console.log(`  Mode       : ${duaArah ? 'dua arah (minta worklist lalu kirim hasil)' : 'satu arah (kirim hasil saja)'}`);
  console.log('\n  Sekarang jalankan middleware dengan alat bertransport');
  console.log(`  "TCP Client" ke 127.0.0.1:${port}.`);
});
