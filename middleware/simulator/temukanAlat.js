/**
 * Penemu alat — mencari cara analyzer berkomunikasi, tanpa menebak.
 *
 * Menu komunikasi analyzer berbeda-beda antar merek, model, dan versi
 * firmware. Sebagian tidak menampilkan nomor port sama sekali karena
 * portnya tetap; sebagian menyembunyikan pilihan protokol di mode servis.
 * Menebak-nebak lewat coba-satu-per-satu memakan waktu berjam-jam di depan
 * alat yang sedang dibutuhkan untuk melayani pasien.
 *
 * Alat ini menjawabnya dengan bukti, dalam dua mode:
 *
 *   1. PINDAI — analyzer bertindak sebagai TCP server
 *      Memeriksa port mana pada analyzer yang terbuka.
 *
 *        node simulator/temukanAlat.js --pindai 192.168.0.15
 *
 *   2. DENGAR — analyzer bertindak sebagai TCP client
 *      Membuka banyak port sekaligus pada komputer ini, lalu menampilkan
 *      byte apa pun yang dikirim analyzer beserta tebakan protokolnya.
 *
 *        node simulator/temukanAlat.js --dengar
 *
 * Jalankan mode DENGAR lebih dulu, lalu tekan "kirim ke LIS" pada analyzer.
 * Bila ada yang muncul, arah koneksinya sudah pasti dan portnya terbaca
 * langsung. Bila tidak ada, jalankan mode PINDAI.
 */

'use strict';

const net = require('net');

// Port yang lazim dipakai analyzer klinik. Bukan daftar tertutup — bila
// manual alat menyebut angka lain, tambahkan lewat --port.
const PORT_LAZIM = [
  // Mindray memakai sepasang port: satu untuk hasil (satu arah), satu
  // untuk permintaan worklist (dua arah), biasanya berurutan.
  // BC-5800 memakai 5500 dan 5501; BC-5000 terpantau di 5100, sehingga
  // pasangan dua arahnya kemungkinan 5101.
  5100, 5101, 5500, 5501, 5502,
  5150, 5151, 5200, 5201, 5250, 5300, 5000, 5001,
  4000, 4001, 3001, 6000, 6100, 7000, 8000, 9100, 10000, 12000
];

function arg(nama, bawaan) {
  const i = process.argv.indexOf(`--${nama}`);
  return i !== -1 && process.argv[i + 1] !== undefined ? process.argv[i + 1] : bawaan;
}

function daftarPort() {
  const tambahan = arg('port', '');
  if (tambahan === '') return PORT_LAZIM;

  const extra = tambahan.split(',').map((p) => parseInt(p.trim(), 10)).filter(Boolean);
  return [...new Set([...extra, ...PORT_LAZIM])];
}

/** Tebak protokol dari byte pertama yang dikirim alat. */
function tebakProtokol(buf) {
  if (buf.length === 0) return 'tidak ada data';

  const b = buf[0];
  if (b === 0x05) return 'ASTM E1381 (diawali ENQ)';
  if (b === 0x0b) return 'HL7 v2 lewat MLLP (diawali VT 0x0B)';
  if (buf.toString('latin1', 0, 3) === 'MSH') return 'HL7 v2 tanpa MLLP (langsung MSH)';
  if (b === 0x02) return 'ASTM (langsung STX, tanpa ENQ)';

  const teks = buf.toString('latin1', 0, 8);
  if (/^[HPOQRLC]\|/.test(teks)) return 'ASTM E1394 mentah (tanpa kerangka E1381)';

  return 'tidak dikenali — kirimkan potongan hex di bawah untuk ditelaah';
}

function hex(buf, maks = 64) {
  return [...buf.subarray(0, maks)]
    .map((b) => b.toString(16).padStart(2, '0'))
    .join(' ');
}

function cetak(buf, maks = 200) {
  return buf
    .toString('latin1', 0, maks)
    .replace(/\x05/g, '<ENQ>').replace(/\x06/g, '<ACK>')
    .replace(/\x02/g, '<STX>').replace(/\x03/g, '<ETX>')
    .replace(/\x04/g, '<EOT>').replace(/\x0b/g, '<VT>')
    .replace(/\x1c/g, '<FS>').replace(/\r/g, '<CR>\n     ')
    .replace(/\n(?!     )/g, '<LF>');
}

// ------------------------------------------------------------------ PINDAI

async function pindai(host) {
  const port = daftarPort();

  console.log(`\nMemindai ${host} pada ${port.length} port lazim…`);
  console.log('(analyzer sebagai TCP server — LIS yang menyambung)\n');

  const hasil = await Promise.all(port.map((p) => cobaSambung(host, p)));
  const terbuka = hasil.filter((h) => h.terbuka);

  for (const h of hasil) {
    const tanda = h.terbuka ? '✓ TERBUKA' : '  tertutup';
    console.log(`  ${String(h.port).padStart(6)}  ${tanda}${h.sebab ? '  (' + h.sebab + ')' : ''}`);
  }

  console.log('');

  if (terbuka.length === 0) {
    console.log('✗ Tidak ada port terbuka pada alamat itu.\n');
    console.log('  Kemungkinan, berurutan dari yang paling sering:');
    console.log('  1. Analyzer justru bertindak sebagai TCP CLIENT — ia yang menyambung');
    console.log('     ke LIS, bukan sebaliknya. Jalankan:');
    console.log('        node simulator/temukanAlat.js --dengar');
    console.log('     lalu tekan "kirim ke LIS" pada analyzer.');
    console.log('  2. Komunikasi LIS belum dinyalakan pada menu analyzer.');
    console.log('  3. Portnya di luar daftar lazim — tambahkan dengan --port 1234,5678');
    console.log('  4. Ada firewall di antara keduanya. Ping lolos bukan jaminan:');
    console.log('     ping memakai ICMP, sedangkan data memakai TCP.\n');
    return;
  }

  console.log(`✓ ${terbuka.length} port terbuka: ${terbuka.map((h) => h.port).join(', ')}\n`);

  if (terbuka.length >= 2) {
    console.log('  Dua port terbuka biasanya berarti alat Mindray:');
    console.log(`    ${terbuka[0].port} = hasil (satu arah)`);
    console.log(`    ${terbuka[1].port} = permintaan worklist (dua arah)`);
    console.log('  Pakai port hasil untuk mode "Satu arah", dan port worklist');
    console.log('  untuk mode "Dua arah".\n');
  }

  console.log('  Isikan pada LIS → Alat Laboratorium → Ubah,');
  console.log('  dengan Transport "TCP Client" dan Host ' + host + '.\n');
}

function cobaSambung(host, port, batasMs = 2500) {
  return new Promise((selesai) => {
    const soket = new net.Socket();
    let sudah = false;

    const tutup = (terbuka, sebab) => {
      if (sudah) return;
      sudah = true;
      soket.destroy();
      selesai({ port, terbuka, sebab });
    };

    soket.setTimeout(batasMs);
    soket.once('connect', () => tutup(true, null));
    soket.once('timeout', () => tutup(false, 'tidak menjawab'));
    soket.once('error', (e) => tutup(false, e.code === 'ECONNREFUSED' ? null : e.code));
    soket.connect(port, host);
  });
}

// ------------------------------------------------------------------ DENGAR

function dengar() {
  const port = daftarPort();
  const server = [];

  console.log(`\nMendengarkan ${port.length} port pada seluruh antarmuka komputer ini…`);
  console.log('(analyzer sebagai TCP client — analyzer yang menyambung)\n');
  console.log('Sekarang tekan "kirim ke LIS" atau jalankan satu sampel pada analyzer.');
  console.log('Hentikan dengan Ctrl+C.\n');

  for (const p of port) {
    const s = net.createServer((soket) => {
      const asal = `${soket.remoteAddress}:${soket.remotePort}`;
      console.log(`\n${'='.repeat(62)}`);
      console.log(`  ANALYZER MENYAMBUNG  →  port ${p}`);
      console.log(`  dari ${asal}`);
      console.log('='.repeat(62));

      let total = Buffer.alloc(0);

      soket.on('data', (d) => {
        total = Buffer.concat([total, d]);

        console.log(`\n  ${d.length} byte diterima`);
        console.log(`  hex   : ${hex(d)}`);
        console.log(`  teks  : ${cetak(d)}`);
        console.log(`  tebakan protokol: ${tebakProtokol(total)}`);

        // Balas ACK untuk ASTM agar alat mau melanjutkan kiriman.
        if (total[0] === 0x05 && d[0] === 0x05) {
          soket.write(Buffer.from([0x06]));
          console.log('  → dibalas ACK agar alat melanjutkan');
        }
      });

      soket.on('close', () => {
        console.log(`\n  Koneksi ditutup. Total ${total.length} byte.`);
        console.log('\n  KESIMPULAN');
        console.log(`    Transport : TCP Server (analyzer menyambung ke LIS)`);
        console.log(`    Port      : ${p}`);
        console.log(`    Protokol  : ${tebakProtokol(total)}`);
        console.log(`\n  Isikan pada LIS → Alat Laboratorium → Ubah:`);
        console.log(`    Transport = TCP Server, Host = 0.0.0.0, Port = ${p}\n`);
      });

      soket.on('error', () => { /* diamkan */ });
    });

    s.on('error', (e) => {
      if (e.code === 'EADDRINUSE') {
        console.log(`  ! Port ${p} dilewati — sudah dipakai proses lain`);
        console.log(`    (hentikan middleware dulu agar port 5100/5200 bebas)`);
      }
    });

    s.listen(p, '0.0.0.0');
    server.push(s);
  }

  process.on('SIGINT', () => {
    console.log('\n\nBerhenti mendengarkan.');
    for (const s of server) {
      try { s.close(); } catch { /* diamkan */ }
    }
    process.exit(0);
  });
}

// ------------------------------------------------------------------- Mulai

const hostPindai = arg('pindai', '');

if (process.argv.includes('--dengar')) {
  dengar();
} else if (hostPindai !== '') {
  pindai(hostPindai);
} else {
  console.log(`
Penemu alat — cari cara analyzer berkomunikasi, tanpa menebak.

  Analyzer sebagai TCP client (analyzer yang menyambung ke LIS):
    node simulator/temukanAlat.js --dengar

  Analyzer sebagai TCP server (LIS yang menyambung ke analyzer):
    node simulator/temukanAlat.js --pindai 192.168.0.15

  Menambah port di luar daftar lazim:
    node simulator/temukanAlat.js --pindai 192.168.0.15 --port 5600,7100

Jalankan mode --dengar lebih dulu: mode itu tidak memerlukan tebakan apa pun
dan langsung menunjukkan port serta protokol yang sebenarnya dipakai alat.
Hentikan middleware dulu agar port 5100 dan 5200 tidak bentrok.
`);
}
