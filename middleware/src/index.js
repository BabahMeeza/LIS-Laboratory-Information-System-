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

  server.on('error', (e) => {
    logger.error(`Server kesehatan gagal dijalankan di port ${config.healthPort}: ${e.message}`);
  });

  server.listen(config.healthPort, '192.168.20.85', () => {
    logger.info(`Status middleware: http://127.0.0.1:${config.healthPort}/health`);
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
