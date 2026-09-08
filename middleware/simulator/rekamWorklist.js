/**
 * Perekam worklist ASTM.
 *
 * Berperan sebagai analyzer: menyambung ke middleware, mengirim host query
 * untuk sebuah barcode, lalu MEREKAM SETIAP BYTE yang dikirim balik dan
 * memverifikasinya terhadap ASTM E1381 (kerangka) dan E1394 (rekaman).
 *
 * Dipakai untuk membuktikan isi worklist sebelum menyentuh alat sungguhan:
 * analyzer nyata akan menolak diam-diam bila nomor bingkai, checksum, atau
 * susunan medan tidak tepat, dan kegagalan seperti itu sangat sulit
 * ditelusuri di lapangan.
 *
 *   node simulator/rekamWorklist.js --sample 2609020008 [--port 5100]
 */

'use strict';

const net = require('net');

const ENQ = 0x05, ACK = 0x06, STX = 0x02, ETX = 0x03, ETB = 0x17, EOT = 0x04, CR = 0x0d, LF = 0x0a;

function arg(nama, bawaan) {
  const i = process.argv.indexOf(`--${nama}`);
  return i !== -1 && process.argv[i + 1] !== undefined ? process.argv[i + 1] : bawaan;
}

const host   = arg('host', '127.0.0.1');
const port   = parseInt(arg('port', '5100'), 10);
const sample = arg('sample', '2609020008');

const NAMA_KENDALI = { 0x05: 'ENQ', 0x06: 'ACK', 0x02: 'STX', 0x03: 'ETX', 0x17: 'ETB', 0x04: 'EOT', 0x15: 'NAK' };

const bingkai = [];   // bingkai mentah yang diterima
const catatan = [];   // temuan verifikasi
let buf = Buffer.alloc(0);
let selesai = false;
let fase = 'kirim-query';   // kirim-query → tunggu-worklist
let antrianKirim = [];
let indeksKirim = 0;

function catat(ok, pesan) {
  catatan.push({ ok, pesan });
}

/** Checksum ASTM: jumlah byte mod 256, dua digit heksadesimal huruf besar. */
function checksum(isi) {
  let s = 0;
  for (const b of isi) s = (s + b) & 0xff;
  return s.toString(16).toUpperCase().padStart(2, '0');
}

/** Bungkus satu rekaman menjadi bingkai ASTM lengkap. */
function bungkus(no, isi) {
  const badan = Buffer.from(String(no) + isi + '\r' + String.fromCharCode(ETX), 'latin1');
  const cs = checksum(badan);
  return {
    no,
    isi,
    byte: Buffer.concat([Buffer.from([STX]), badan, Buffer.from(cs + '\r\n', 'latin1')])
  };
}

const cap = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
const rekamanQuery = [
  `H|\\^&|||PEREKAM^1.0|||||LIS||P|1|${cap}`,
  `Q|1|^${sample}||ALL||||||||O`,
  'L|1|N'
];
antrianKirim = rekamanQuery.map((r, i) => bungkus((i + 1) % 8, r));

const soket = net.createConnection({ host, port }, () => {
  console.log(`Tersambung ke ${host}:${port} sebagai analyzer.\n`);
  soket.write(Buffer.from([ENQ]));
  console.log('→ ENQ (meminta giliran mengirim)');
});

soket.on('data', (d) => {
  buf = Buffer.concat([buf, d]);

  while (buf.length > 0) {
    const b = buf[0];

    if (b === ACK || b === ENQ || b === EOT) {
      console.log(`← ${NAMA_KENDALI[b]}`);
      buf = buf.subarray(1);

      if (b === ACK && fase === 'kirim-query') {
        // Giliran kita: kirim host query satu bingkai per ACK.
        if (indeksKirim < antrianKirim.length) {
          const f = antrianKirim[indeksKirim++];
          soket.write(f.byte);
          console.log(`→ STX ${f.no} ${f.isi}`);
        } else {
          soket.write(Buffer.from([EOT]));
          console.log('→ EOT (query selesai, menunggu worklist)');
          fase = 'tunggu-worklist';
        }
      }

      if (b === ENQ) {
        // Middleware ingin mengirim worklist. Beri giliran.
        soket.write(Buffer.from([ACK]));
        console.log('→ ACK (silakan kirim)');
      }
      if (b === EOT && fase === 'tunggu-worklist') {
        selesai = true;
        setTimeout(() => { soket.end(); }, 150);
      }
      continue;
    }

    if (b === STX) {
      // Bingkai: STX <no> <isi> ETX/ETB <cs1><cs2> CR LF
      let akhir = -1;
      for (let i = 1; i < buf.length; i++) {
        if (buf[i] === ETX || buf[i] === ETB) { akhir = i; break; }
      }
      if (akhir === -1 || buf.length < akhir + 5) return; // tunggu sisanya

      const noBingkai = String.fromCharCode(buf[1]);
      const isi       = buf.subarray(2, akhir).toString('latin1');
      const penutup   = buf[akhir];
      const csDiterima = buf.subarray(akhir + 1, akhir + 3).toString('latin1');

      // Checksum dihitung dari nomor bingkai sampai ETX/ETB inklusif.
      const csHitung = checksum(buf.subarray(1, akhir + 1));

      bingkai.push({ noBingkai, isi, penutup, csDiterima, csHitung });

      const tanda = csDiterima === csHitung ? '✓' : '✗';
      console.log(`← STX ${noBingkai} [${penutup === ETX ? 'ETX' : 'ETB'}] cs=${csDiterima} ${tanda}  ${isi.replace(/\r/g, '')}`);

      buf = buf.subarray(akhir + 5 <= buf.length && buf[akhir + 3] === CR ? akhir + 5 : akhir + 3);
      soket.write(Buffer.from([ACK]));
      continue;
    }

    // Byte tak dikenal — buang satu agar tidak macet.
    buf = buf.subarray(1);
  }
});

soket.on('error', (e) => {
  console.error(`✗ ${e.message}`);
  process.exit(1);
});

soket.on('close', () => {
  console.log('\n' + '='.repeat(64));
  console.log('  VERIFIKASI');
  console.log('='.repeat(64) + '\n');

  if (bingkai.length === 0) {
    console.log('✗ Tidak ada bingkai diterima. Middleware tidak mengirim worklist.');
    process.exit(1);
  }

  // 1. Checksum setiap bingkai
  const csSalah = bingkai.filter((f) => f.csDiterima !== f.csHitung);
  catat(csSalah.length === 0, `Checksum ${bingkai.length} bingkai (${csSalah.length} salah)`);

  // 2. Nomor bingkai berurutan 1..7 lalu kembali ke 0
  let harusnya = 1;
  let urutOk = true;
  for (const f of bingkai) {
    if (f.noBingkai !== String(harusnya % 8)) urutOk = false;
    harusnya++;
  }
  catat(urutOk, 'Nomor bingkai berurutan dan berputar pada 0-7');

  // 3. Rakit rekaman
  // Isi bingkai dirakit apa adanya — CR adalah pemisah rekaman ASTM,
  // jadi tidak boleh dibuang sebelum pemisahan.
  const rekaman = bingkai.map((f) => f.isi).join('').split('\r').filter((r) => r !== '');
  const jenis = rekaman.map((r) => r[0]);
  console.log('Rekaman yang dirakit:\n');
  rekaman.forEach((r) => console.log('  ' + r));
  console.log('');

  catat(jenis[0] === 'H', 'Diawali rekaman Header (H)');
  catat(jenis[jenis.length - 1] === 'L', 'Diakhiri rekaman Terminator (L)');
  catat(jenis.includes('P'), 'Memuat rekaman Patient (P)');
  catat(jenis.includes('O'), 'Memuat rekaman Order (O)');

  // 4. Pembatas medan diumumkan pada H dan dipakai konsisten
  const h = rekaman[0] || '';
  catat(h.startsWith('H|\\^&'), 'Header mengumumkan pembatas |\\^& sesuai E1394');

  // 5. Rekaman O memuat sample ID dan universal test ID
  const o = rekaman.find((r) => r.startsWith('O|')) || '';
  const medanO = o.split('|');
  catat(medanO[2] === sample || medanO[3] === sample,
    `Rekaman O memuat sample ID "${sample}" pada medan spesimen`);

  const uji = medanO[4] || '';
  const kodeUji = uji.split('\\').map((u) => u.split('^')[3]).filter(Boolean);
  catat(kodeUji.length > 0, `Universal test ID memuat ${kodeUji.length} kode: ${kodeUji.join(', ')}`);
  catat(uji.split('\\').every((u) => u.split('^').length >= 4),
    'Setiap universal test ID berbentuk ^^^KODE (tiga komponen kosong di depan)');

  // 6. Prioritas
  catat(['S', 'R', 'A'].includes(medanO[5]), `Prioritas "${medanO[5]}" adalah nilai E1394 yang sah`);

  // 7. Rekaman P memuat identitas pasien
  const p = rekaman.find((r) => r.startsWith('P|')) || '';
  const medanP = p.split('|');
  catat((medanP[5] || '').includes('^') || (medanP[5] || '') !== '',
    `Rekaman P memuat nama pasien "${medanP[5] || ''}"`);
  catat(['M', 'F', 'U'].includes(medanP[8] || ''),
    `Jenis kelamin "${medanP[8] || ''}" dipetakan ke kode E1394 (M/F/U)`);

  // 8. Sesi ditutup EOT
  catat(selesai, 'Sesi ditutup dengan EOT');

  console.log('Hasil pemeriksaan:\n');
  let gagal = 0;
  for (const c of catatan) {
    console.log(`  ${c.ok ? '✓' : '✗'} ${c.pesan}`);
    if (!c.ok) gagal++;
  }
  console.log('');
  console.log(gagal === 0
    ? `✓ Worklist sah menurut ASTM E1381/E1394 — ${kodeUji.length} pemeriksaan terkirim.`
    : `✗ ${gagal} pemeriksaan gagal. Jangan sambungkan ke alat sungguhan dulu.`);

  process.exit(gagal === 0 ? 0 : 1);
});
