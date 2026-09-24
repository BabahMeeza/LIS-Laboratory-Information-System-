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
  // 5300/5400 dipakai sebagai jatah ELYTE-01 dan URIN-02 di RSUD Hanau,
  // ikut didengar di sini supaya mode --dengar menangkapnya tanpa --port.
  5150, 5151, 5200, 5201, 5250, 5300, 5301, 5400, 5401, 5000, 5001,
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

    // "Tidak ada port terbuka" menyembunyikan dua keadaan yang jauh
    // berbeda, dan keduanya menuntut langkah yang berlainan:
    //
    //   - SEMUA tidak menjawab  → tidak ada bukti mesin itu ada. Yang
    //     perlu diperiksa dulu alamatnya, bukan portnya.
    //   - ADA yang menolak      → mesinnya hidup dan menjawab; hanya
    //     tidak ada yang mendengar di port itu.
    const menolak = hasil.filter((h) => h.sebab === 'ditolak — mesin hidup').length;

    if (menolak === 0) {
      console.log(`  Tidak satu pun dari ${hasil.length} port menjawab — bahkan menolak pun tidak.`);
      console.log(`  Itu berarti belum ada bukti sama sekali bahwa ${host} ada di jaringan ini.`);
      console.log('  Periksa dulu keberadaannya, baru urusan port:');
      console.log(`     ping -c3 ${host}  &&  arp -n ${host}`);
      console.log('  Bila arp kosong atau "incomplete", alamat itu memang tidak dihuni.\n');
    } else {
      console.log(`  ${menolak} port menolak sambungan, jadi mesin di ${host} hidup dan menjawab —`);
      console.log('  hanya belum ada yang mendengar di port mana pun.\n');
    }

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
    // ECONNREFUSED bukan sekadar "tertutup": ia membuktikan ada mesin hidup
    // di alamat itu yang menjawab. Perbedaan ini menentukan langkah
    // berikutnya, jadi jangan disembunyikan.
    soket.once('error', (e) => tutup(false, e.code === 'ECONNREFUSED' ? 'ditolak — mesin hidup' : e.code));
    soket.connect(port, host);
  });
}


/**
 * Kumpulkan kode parameter dari rekaman yang dikirim alat.
 *
 * Inilah bagian yang menghapus tebakan terakhir. Tanpa ini, pemetaan kode
 * harus ditebak dari singkatan yang lazim — dan untuk alat OEM yang tidak
 * punya dokumen protokol beredar, tebakan itu tidak punya dasar apa pun.
 * Kode yang benar selalu ada di dalam byte yang baru saja dikirim alat;
 * tinggal dibaca.
 *
 * ASTM  : rekaman hasil diawali "R|", kode ada di medan ke-3 dengan
 *         bentuk ^^^KODE atau langsung KODE.
 * HL7   : segmen OBX, kode ada di OBX-3 dengan bentuk ID^Nama^Sistem.
 */
function kodeParameter(buf) {
  const teks = buf.toString('latin1');
  const kode = new Map();          // kode -> contoh nilai

  // --- ASTM: R|1|^^^Na|140|mmol/L|...
  for (const baris of teks.split(/[\r\n\x02\x03]+/)) {
    // Nomor bingkai ASTM E1381 (0-7) mendahului rekaman pada aliran
    // berbingkai: "2R|1|^^^LEU|...". Tanpa \d? di depan, seluruh hasil
    // dari alat yang memakai bingkai terlewat tanpa jejak.
    const m = /^\d?R\|\d*\|([^|]*)\|([^|]*)\|?([^|]*)/.exec(baris.trim());
    if (m === null) continue;

    // Medan ke-3 ASTM: ^^^KODE — ambil bagian tak kosong yang terakhir.
    const bagian = m[1].split('^').filter((x) => x !== '');
    const k = bagian.length > 0 ? bagian[bagian.length - 1] : '';
    if (k !== '') kode.set(k, { nilai: m[2], satuan: m[3] || '' });
  }

  // --- HL7: OBX|1|NM|718-7^HGB^LN||64|g/L|...
  for (const baris of teks.split(/[\r\n]+/)) {
    if (!baris.startsWith('OBX')) continue;
    const f = baris.split('|');
    if (f.length < 6) continue;

    const bagian = (f[3] || '').split('^');
    const k = bagian[0] || '';
    if (k !== '') kode.set(k, { nilai: f[5] || '', satuan: f[6] || '', nama: bagian[1] || '' });
  }

  return kode;
}

/** Cetak kode yang ditemukan beserta INSERT yang tinggal dijalankan. */
function laporkanKode(buf, kodeAlat) {
  const kode = kodeParameter(buf);

  if (kode.size === 0) {
    console.log('\n  Tidak ada kode parameter yang dapat dibaca dari kiriman ini.');
    console.log('  Mungkin baru pesan pembuka; jalankan satu sampel dengan hasil lengkap.');
    return;
  }

  console.log(`\n  KODE PARAMETER YANG BENAR-BENAR DIKIRIM ALAT (${kode.size})`);
  console.log('  ' + '-'.repeat(60));
  for (const [k, v] of kode) {
    console.log(`    ${k.padEnd(18)} ${String(v.nilai).padEnd(12)} ${v.satuan || ''} ${v.nama ? '(' + v.nama + ')' : ''}`);
  }

  console.log('\n  Pemetaan siap pakai — ganti <TEST> dengan kode pemeriksaan LIS,');
  console.log('  lalu jalankan di database. Baris yang memang tidak dipakai cukup');
  console.log('  dihapus, atau diberi abaikan = 1 agar tidak menumpuk di layar');
  console.log('  "Hasil Belum Terpetakan".\n');
  console.log(`  SET @alat := (SELECT id FROM instruments WHERE kode = '${kodeAlat}');`);
  console.log('  INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`) VALUES');

  const baris = [...kode.entries()].map(([k, v]) =>
    `   (@alat,${JSON.stringify(k).padEnd(20)},(SELECT id FROM tests WHERE kode='<TEST>'),${v.satuan ? JSON.stringify(v.satuan) : 'NULL'})`);
  console.log(baris.join(',\n'));
  console.log('  ON DUPLICATE KEY UPDATE `test_id`=VALUES(`test_id`), `satuan_alat`=VALUES(`satuan_alat`);\n');
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
      const ip = (soket.remoteAddress || '').replace(/^::ffff:/, '');
      const asal = `${ip}:${soket.remotePort}`;
      console.log(`\n${'='.repeat(62)}`);
      console.log(`  ADA YANG MENYAMBUNG  →  port ${p}`);
      console.log(`  dari ${asal}`);
      console.log('='.repeat(62));

      // Tidak setiap sambungan berasal dari analyzer. Port 9100 dan 515
      // adalah port cetak jaringan, dan komputer mana pun di jaringan
      // yang mencari printer akan mengetuknya. Bila itu dianggap alat,
      // nomor port yang salah masuk ke konfigurasi LIS.
      const PORT_CETAK = { 9100: 'cetak mentah (JetDirect/AppSocket)', 515: 'LPD/LPR' };
      if (PORT_CETAK[p]) {
        console.log(`\n  ! Port ${p} adalah port ${PORT_CETAK[p]}, bukan port analyzer.`);
        console.log('    Kemungkinan besar ini komputer lain yang mencari printer.');
        console.log(`    Pastikan ${ip} memang alamat analyzernya sebelum dipakai.`);
      }

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

        // Sambungan tanpa satu byte pun BUKAN bukti bahwa ini analyzer.
        // Pemindai jaringan, penemuan printer, dan pemeriksa kesehatan
        // semuanya menyambung lalu langsung menutup. Menyimpulkan
        // transport dan port dari sambungan kosong pernah membuat nomor
        // port printer masuk ke konfigurasi alat — jadi jangan
        // menyimpulkan apa pun sebelum ada data yang benar-benar datang.
        if (total.length === 0) {
          console.log('\n  BELUM ADA KESIMPULAN');
          console.log('    Sambungan ini tidak mengirim satu byte pun, jadi belum tentu');
          console.log('    berasal dari analyzer — pemindai jaringan dan pencarian printer');
          console.log('    berperilaku persis sama.');
          console.log(`    Cocokkan dulu ${ip} dengan alamat alat yang sebenarnya.`);
          console.log('    Bila memang alatnya, jalankan satu sampel sampai hasilnya keluar.\n');
          return;
        }

        console.log('\n  KESIMPULAN');
        console.log(`    Transport : TCP Server (analyzer menyambung ke LIS)`);
        console.log(`    Port      : ${p}`);
        console.log(`    Protokol  : ${tebakProtokol(total)}`);
        console.log(`\n  Isikan pada LIS → Alat Laboratorium → Ubah:`);
        console.log(`    Transport = TCP Server, Host = 0.0.0.0, Port = ${p}\n`);

        laporkanKode(total, arg('kode', 'KODE-ALAT'));
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
