#!/usr/bin/env node
'use strict';

/**
 * Pemeriksa koneksi middleware. Jalankan DI SERVER TEMPAT MIDDLEWARE BERADA.
 *
 * MENGAPA BERKAS INI ADA
 * ----------------------
 * "Middleware gagal" hampir selalu berarti salah satu dari TIGA sambungan
 * yang berbeda putus, dan ketiganya bergantung pada alamat yang berlainan.
 * Pindah server memutus ketiganya sekaligus, tetapi gejalanya di layar
 * hanya satu kalimat yang sama.
 *
 *   1. middleware -> LIS      LIS_BASE_URL pada middleware/.env
 *      Dipakai kirim denyut hidup, ambil konfigurasi alat, kirim hasil.
 *
 *   2. LIS -> middleware      middleware.health_url pada config/config.php
 *      HANYA untuk menampilkan status di halaman Alat. Bila yang putus
 *      cuma ini, middleware sebenarnya bekerja normal — layarnya saja yang
 *      berkata mati.
 *
 *   3. alat -> middleware     host dan port yang disetel di analyzer
 *      Tidak dapat diperiksa dari sini; analyzer harus menunjuk alamat
 *      server yang baru.
 *
 * Skrip ini memeriksa (1) dan (2), dan melaporkan port dengar untuk (3).
 * Tidak mengubah apa pun.
 *
 *   node bin/cek-koneksi.js
 */

const http = require('http');
const https = require('https');
const net = require('net');
const dns = require('dns');
const os = require('os');
const { URL } = require('url');

const { config, periksa } = require('../src/config');

let gagal = 0;

function judul(t) {
  console.log('\n' + t);
  console.log('-'.repeat(69));
}

function lapor(nama, ok, ket = '') {
  if (!ok) gagal++;
  console.log(`  ${ok ? 'ok   ' : 'GAGAL'} ${nama}${ket ? '  — ' + ket : ''}`);
}

function minta(url, headers, timeoutMs) {
  return new Promise((resolve) => {
    let u;
    try {
      u = new URL(url);
    } catch (e) {
      return resolve({ ok: false, error: 'URL tidak sah: ' + e.message });
    }

    const mod = u.protocol === 'https:' ? https : http;
    const req = mod.request(
      { method: 'GET', hostname: u.hostname, port: u.port || (u.protocol === 'https:' ? 443 : 80), path: u.pathname + u.search, headers },
      (res) => {
        let body = '';
        res.on('data', (c) => { body += c; });
        res.on('end', () => resolve({ ok: true, status: res.statusCode, body: body.slice(0, 400) }));
      }
    );

    req.setTimeout(timeoutMs, () => { req.destroy(); resolve({ ok: false, error: 'batas waktu ' + timeoutMs + ' ms' }); });
    req.on('error', (e) => resolve({ ok: false, error: e.code || e.message }));
    req.end();
  });
}

function portTerbuka(host, port, timeoutMs) {
  return new Promise((resolve) => {
    const s = new net.Socket();
    s.setTimeout(timeoutMs);
    s.once('connect', () => { s.destroy(); resolve(true); });
    s.once('timeout', () => { s.destroy(); resolve(false); });
    s.once('error', () => { s.destroy(); resolve(false); });
    s.connect(port, host);
  });
}

(async function main() {
  console.log('='.repeat(69));
  console.log(' PEMERIKSA KONEKSI MIDDLEWARE   ' + new Date().toISOString());
  console.log('='.repeat(69));

  // -------------------------------------------------------------------
  judul('0. Mesin ini');

  const alamat = [];
  for (const [nama, daftar] of Object.entries(os.networkInterfaces())) {
    for (const a of daftar || []) {
      if (a.family === 'IPv4' && !a.internal) alamat.push(`${nama}=${a.address}`);
    }
  }
  console.log('  hostname : ' + os.hostname());
  console.log('  alamat   : ' + (alamat.join(', ') || '(tidak ada IPv4 non-lokal)'));
  console.log('  node     : ' + process.version);

  // -------------------------------------------------------------------
  judul('1. Konfigurasi middleware (.env)');

  console.log('  LIS_BASE_URL        : ' + config.lis.baseUrl);
  console.log('  LIS_API_KEY         : ' + (config.lis.apiKey ? config.lis.apiKey.slice(0, 10) + '……' : '(kosong)'));
  console.log('  LIS_SIGN_REQUESTS   : ' + config.lis.signRequests);
  console.log('  HEALTH_PORT         : ' + config.healthPort);
  console.log('  HL7_WORKLIST_REPLY  : ' + config.hl7WorklistReply);

  const masalah = periksa();
  lapor('konfigurasi lolos validasi middleware', masalah.length === 0, masalah.join('; '));

  // Alamat yang menunjuk diri sendiri adalah jebakan tersering sesudah
  // pindah server: benar di laptop pengembang, salah di mana pun selain itu.
  let host = '';
  try {
    host = new URL(config.lis.baseUrl).hostname;
  } catch (e) { /* sudah dilaporkan di atas */ }

  if (['localhost', '127.0.0.1', '::1'].includes(host)) {
    console.log('');
    console.log('  !! LIS_BASE_URL menunjuk mesin ini sendiri (' + host + ').');
    console.log('     Itu benar HANYA bila LIS berjalan di server yang sama.');
    console.log('     Bila LIS ada di server lain, ganti ke alamat server itu.');
  }

  // -------------------------------------------------------------------
  judul('2. Nama host dapat diterjemahkan');

  if (host && !net.isIP(host)) {
    try {
      const hasil = await dns.promises.lookup(host, { all: true });
      lapor(`nama "${host}" terterjemahkan`, true, hasil.map((h) => h.address).join(', '));
      console.log('     Nama dari berkas hosts hanya berlaku di mesin yang memuatnya.');
      console.log('     Mesin ini memuatnya; pastikan mesin lain yang memakainya juga.');
    } catch (e) {
      lapor(`nama "${host}" terterjemahkan`, false, e.code || e.message);
      console.log('     Tambahkan barisnya di /etc/hosts mesin INI, atau pakai alamat IP.');
    }
  } else if (host) {
    lapor('LIS_BASE_URL memakai alamat IP', true, host + ' (tidak perlu hosts)');
  }

  // -------------------------------------------------------------------
  judul('3. middleware -> LIS');

  const ping = await minta(config.lis.baseUrl + '/api/v1/ping', {}, config.lis.timeoutMs);

  if (!ping.ok) {
    lapor('LIS dapat dihubungi', false, ping.error);
    console.log('');
    console.log('     ECONNREFUSED  : tidak ada yang mendengar di alamat/port itu.');
    console.log('                     Periksa port pada LIS_BASE_URL. LIS yang dilayani');
    console.log('                     Apache biasanya di port 80, bukan 8080.');
    console.log('     EHOSTUNREACH  : jaringan tidak sampai ke sana.');
    console.log('     ENOTFOUND     : nama host tidak dikenal di mesin ini.');
    console.log('     batas waktu   : biasanya firewall membuang paketnya diam-diam.');
  } else {
    lapor('LIS menjawab', ping.status === 200, 'HTTP ' + ping.status);

    if (ping.status === 404) {
      console.log('     404 berarti alamatnya sampai ke server web, tetapi bukan ke LIS.');
      console.log('     Bila LIS dipasang di sub-folder, LIS_BASE_URL harus memuatnya,');
      console.log('     mis. http://lis.lokal/LIS/public');
    }
    if (ping.status === 503) {
      console.log('     503 berarti LIS terkunci oleh lisensi. Seluruh kredensial API');
      console.log('     memang dinonaktifkan dalam keadaan itu. Periksa di server LIS:');
      console.log('       php bin/lisensi.php');
    }
  }

  // -------------------------------------------------------------------
  judul('4. Kredensial API');

  if (!ping.ok) {
    console.log('  Dilewati — LIS belum dapat dihubungi.');
  } else {
    const res = await minta(
      config.lis.baseUrl + '/api/v1/instruments',
      { 'X-API-Key': config.lis.apiKey },
      config.lis.timeoutMs
    );

    if (!res.ok) {
      lapor('kredensial diterima', false, res.error);
    } else {
      lapor('kredensial diterima', res.status === 200, 'HTTP ' + res.status);

      if (res.status === 401) {
        console.log('     Kunci tidak dikenal atau nonaktif pada database LIS ini.');
        console.log('     Database produksi yang baru tidak memuat kredensial dari');
        console.log('     server lama. Buat baru di Pengaturan > Kredensial API,');
        console.log('     lalu salin ke LIS_API_KEY.');
      }
      if (res.status === 403) {
        console.log('     Kunci dikenal tetapi ditolak. Dua sebab, dan log LIS');
        console.log('     menyebut yang mana pada baris "Autentikasi API ditolak":');
        console.log('       - cakupan kredensial bukan "instrument"');
        console.log('       - alamat mesin ini di luar Batas IP kredensial');
        console.log('     Alamat yang dilihat LIS belum tentu yang Anda kira;');
        console.log('     pesan 403-nya menyebutkan alamat itu apa adanya.');
      }
      if (res.status === 503) {
        console.log('     LIS terkunci oleh lisensi.');
      }
      if (res.status === 200) {
        try {
          const j = JSON.parse(res.body);
          const n = Array.isArray(j.data) ? j.data.length : (Array.isArray(j) ? j.length : '?');
          console.log('     alat aktif terbaca : ' + n);
        } catch (e) { /* tidak apa-apa */ }
      }
    }
  }

  // -------------------------------------------------------------------
  judul('5. LIS -> middleware (status di halaman Alat)');

  console.log('  HEALTH_BIND : ' + config.healthBind);
  console.log('  HEALTH_PORT : ' + config.healthPort);
  console.log('');

  const lokal = await portTerbuka('127.0.0.1', config.healthPort, 2000);
  lapor(`dapat dihubungi dari mesin ini (127.0.0.1:${config.healthPort})`, lokal,
    lokal ? '' : 'middleware belum berjalan, atau HEALTH_PORT berbeda');

  if (!lokal) {
    console.log('');
    console.log('     Periksa dulu apakah prosesnya memang hidup:');
    console.log('       ps aux | grep "[n]ode src/index.js"');
    console.log('     Lalu lihat baris "Server kesehatan gagal" pada logs/.');
    console.log('     EADDRINUSE berarti port dipakai proses lain.');
  }

  // Inilah jebakannya, dan ia tidak kentara: port yang terikat ke
  // 127.0.0.1 memang TIDAK PERNAH terbuka ke jaringan. Dari mesin lain
  // hasilnya persis sama dengan middleware yang mati.
  const terikatLokal = ['127.0.0.1', 'localhost', '::1'].includes(config.healthBind);
  const ipLuar = alamat[0] ? alamat[0].split('=')[1] : '';

  if (terikatLokal) {
    console.log('');
    console.log('  !! HEALTH_BIND = ' + config.healthBind + ' — port ini HANYA terbuka di mesin ini.');
    console.log('     Dari mesin lain hasilnya sama persis dengan middleware mati,');
    console.log('     padahal middleware bekerja normal. Bila LIS ada di mesin lain,');
    console.log('     isi HEALTH_BIND=0.0.0.0 pada .env lalu jalankan ulang middleware.');
  } else if (ipLuar) {
    const luar = await portTerbuka(ipLuar, config.healthPort, 2000);
    lapor(`dapat dihubungi lewat jaringan (${ipLuar}:${config.healthPort})`, luar,
      luar ? '' : 'terhalang firewall, atau middleware belum dijalankan ulang');
  }

  console.log('');
  console.log('  Pada config/config.php milik LIS, middleware.health_url harus');
  console.log('  menunjuk mesin INI:');
  console.log('    satu mesin  : http://127.0.0.1:' + config.healthPort + '/health');
  if (ipLuar) {
    console.log('    beda mesin  : http://' + ipLuar + ':' + config.healthPort + '/health');
  }
  console.log('');
  console.log('  Perlu diingat: bagian ini HANYA mempengaruhi tulisan status di');
  console.log('  layar. Middleware yang sehat tetap bekerja walau baris ini salah,');
  console.log('  dan hasil dari alat tetap mengalir ke LIS.');

  // -------------------------------------------------------------------
  judul('6. alat -> middleware');

  console.log('  Tidak dapat diperiksa dari sini. Analyzer menyimpan sendiri alamat');
  console.log('  tujuannya, dan alamat itu tidak ikut berpindah saat server pindah.');
  console.log('');
  console.log('  Pada setiap alat, alamat tujuan harus menunjuk mesin ini:');
  for (const a of alamat) {
    console.log('    ' + a.split('=')[1]);
  }
  console.log('  Port mengikuti kolom Port pada menu Alat di LIS (mis. 5100).');

  // -------------------------------------------------------------------
  console.log('\n' + '='.repeat(69));
  console.log(gagal === 0
    ? ' Seluruh pemeriksaan yang dapat dilakukan dari sini LULUS.'
    : ` ${gagal} pemeriksaan GAGAL — lihat keterangan di bawah masing-masing.`);
  process.exit(gagal === 0 ? 0 : 1);
})();
