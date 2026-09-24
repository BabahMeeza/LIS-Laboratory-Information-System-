'use strict';

/**
 * Titik masuk middleware alat laboratorium.
 *
 * Jalankan dengan:  npm start
 * Uji tanpa alat :  npm run simulate:astm  (di terminal lain)
 */

const http = require('http');
const { config, periksa } = require('./config');
const logger = require('./logger');
const lis = require('./lisClient');
const gateway = require('./gateway');
const spool = require('./spool');

const GARIS = '─'.repeat(64);

async function main() {
  process.stdout.write(`\n${GARIS}\n`);
  process.stdout.write('  LIS — Middleware Alat Laboratorium\n');
  process.stdout.write(`  ASTM E1381/E1394 · HL7 v2 MLLP · Serial RS232\n`);
  process.stdout.write(`${GARIS}\n\n`);

  const masalah = periksa();
  if (masalah.length > 0) {
    logger.error('Konfigurasi belum lengkap:');
    for (const m of masalah) {
      logger.error(`  • ${m}`);
    }
    logger.error('Perbaiki berkas middleware/.env lalu jalankan ulang.');
    process.exit(1);
  }

  logger.info(`LIS  : ${config.lis.baseUrl}`);
  logger.info(`Log  : ${config.logLevel}`);
  logger.info(`Spool: ${config.spoolDir} (${spool.jumlah()} menunggu kirim)`);

  // Pastikan LIS terjangkau sebelum membuka port alat, agar kesalahan
  // konfigurasi terlihat langsung dan bukan setelah hasil pertama hilang.
  try {
    const pong = await lis.ping();
    logger.info(`LIS merespons: ${pong?.data?.aplikasi ?? 'LIS'} v${pong?.data?.versi ?? '?'} — database ${pong?.data?.database ?? '?'}`);

    // Server pengembangan PHP melayani satu permintaan pada satu waktu.
    // Itu tidak terasa pada halaman web, tetapi mematikan bagi worklist
    // dua arah yang tenggatnya hanya dua detik. Lihat periksaServerLis().
    const srv = await lis.periksaServerLis();

    if (srv.dev) {
      logger.warn('');
      logger.warn(`LIS dilayani server pengembangan PHP (${srv.server}).`);

      if (srv.serial) {
        logger.warn('  Server ini melayani SATU permintaan pada satu waktu —');
        logger.warn(`  empat permintaan bersamaan memakan ${srv.msParalel} ms.`);
        logger.warn('');
        logger.warn('  Alat memberi LIS hanya 2 detik untuk menjawab permintaan');
        logger.warn('  worklist. Bila ada permintaan lain yang sedang dilayani —');
        logger.warn('  seseorang membuka halaman LIS di peramban, satu berkas CSS —');
        logger.warn('  panggilan worklist mengantre dan tenggatnya terlampaui.');
        logger.warn('  Kegagalannya tidak meninggalkan jejak di sisi LIS.');
        logger.warn('');
        logger.warn('  Perbaikan cepat — jalankan ulang LIS dengan pekerja ganda:');
        logger.warn('    PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8080 -t public');
        logger.warn('');
        logger.warn('  Untuk pemakaian sungguhan, pindah ke Apache atau nginx.');
      } else {
        logger.warn('  Pekerja ganda terdeteksi aktif — permintaan bersamaan terlayani.');
        logger.warn('  Tetap pindah ke Apache/nginx untuk pemakaian sungguhan.');
      }
      logger.warn('');
    }
  } catch (e) {
    logger.warn(`LIS belum dapat dihubungi: ${e.message}`);
    logger.warn('Middleware tetap berjalan: hasil dari alat akan disimpan di spool dan dikirim setelah LIS kembali.');
  }

  await gateway.mulai();

  // Endpoint kesehatan sederhana untuk halaman "Alat Laboratorium" di LIS.
  const server = http.createServer((req, res) => {
    if (req.url === '/health' || req.url === '/') {
      const ringkasan = gateway.ringkasan();
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(ringkasan, null, 2));
      return;
    }

    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ pesan: 'Tidak ditemukan' }));
  });

  // Kegagalan membuka port ini TIDAK mematikan middleware — ia hanya
  // membuat halaman Alat berkata "tidak merespons". Membedakan keduanya
  // penting: yang satu berarti hasil pasien berhenti mengalir, yang satu
  // lagi hanya tulisan di layar.
  server.on('error', (e) => {
    logger.error(`Server kesehatan gagal dijalankan di ${config.healthBind}:${config.healthPort} — ${e.code || e.message}`);

    if (e.code === 'EADDRINUSE') {
      logger.error('  Port itu sudah dipakai proses lain — biasanya middleware lain');
      logger.error('  yang masih berjalan. Hentikan dulu, atau ganti HEALTH_PORT.');
    }
    if (e.code === 'EADDRNOTAVAIL') {
      logger.error(`  Alamat ${config.healthBind} tidak ada di mesin ini. Isi HEALTH_BIND`);
      logger.error('  dengan 127.0.0.1 (satu mesin) atau 0.0.0.0 (semua antarmuka).');
    }
    if (e.code === 'EACCES') {
      logger.error('  Tidak diizinkan membuka port itu. Port di bawah 1024 menuntut root.');
    }

    logger.warn('  Middleware TETAP BERJALAN. Hasil dari alat tidak terpengaruh;');
    logger.warn('  hanya status di halaman Alat yang tidak dapat ditampilkan.');
  });

  server.listen(config.healthPort, config.healthBind, () => {
    logger.info(`Status middleware: http://${config.healthBind}:${config.healthPort}/health`);

    if (config.healthBind === '127.0.0.1' || config.healthBind === 'localhost') {
      logger.info('  Terbatas pada mesin ini saja. Bila LIS berada di mesin lain,');
      logger.info('  isi HEALTH_BIND=0.0.0.0 pada .env agar halaman Alat dapat membacanya.');
    }

    logger.info('Middleware siap. Tekan Ctrl+C untuk berhenti.\n');
  });

  const matikan = async (sinyal) => {
    logger.info(`\nMenerima ${sinyal}, menutup koneksi dengan rapi…`);
    server.close();
    await gateway.hentikan();
    logger.info('Middleware berhenti.');
    process.exit(0);
  };

  process.on('SIGINT', () => { matikan('SIGINT'); });
  process.on('SIGTERM', () => { matikan('SIGTERM'); });

  process.on('uncaughtException', (e) => {
    logger.error(`Pengecualian tak tertangani: ${e.stack || e.message}`);
  });

  process.on('unhandledRejection', (e) => {
    logger.error(`Promise ditolak tanpa penanganan: ${e?.stack || e}`);
  });
}

main().catch((e) => {
  logger.error(`Middleware gagal dijalankan: ${e.stack || e.message}`);
  process.exit(1);
});
