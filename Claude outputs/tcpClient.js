'use strict';

/**
 * Transport TCP Client: middleware yang MENYAMBUNG ke analyzer.
 * Dipakai bila analyzer berperan sebagai server (membuka port sendiri),
 * atau bila di antaranya terpasang konverter Serial-to-Ethernet yang
 * dikonfigurasi dalam mode server.
 *
 * Koneksi disambung ulang otomatis dengan jeda yang meningkat bertahap.
 */

const net = require('net');
const { EventEmitter } = require('events');
const { config } = require('../config');

class TcpClientTransport extends EventEmitter {
  constructor(alat, logger) {
    super();
    this.alat = alat;
    this.logger = logger;
    this.socket = null;
    this.timer = null;
    this.percobaan = 0;
    this.berhenti = false;
  }

  get label() {
    return `${this.alat.code}`;
  }

  start() {
    this.berhenti = false;
    this._sambung();
  }

  _sambung() {
    const host = this.alat.tcp?.host;
    const port = this.alat.tcp?.port;

    if (!host || !port) {
      this.emit('status', 'error', 'Host atau port TCP belum diatur');
      this.logger.error(`[${this.label}] Host/port TCP belum diatur, alat dilewati`);
      return;
    }

    // 0.0.0.0 dan :: berarti "semua antarmuka MESIN INI". Itu alamat untuk
    // MENDENGAR, dan tidak pernah sah sebagai tujuan panggilan — meneleponnya
    // sama dengan menelepon diri sendiri.
    //
    // Bila nilai itu sampai ke sini, sebabnya selalu sama: barisnya diisi
    // untuk tcp_server (Host 0.0.0.0) tetapi transport-nya masih tcp_client.
    // Tanpa penjagaan ini gejalanya cuma ECONNREFUSED berulang tiap beberapa
    // detik — galat yang menuntun orang memeriksa port dan firewall, padahal
    // tidak satu pun dari keduanya bersalah.
    if (host === '0.0.0.0' || host === '::' || host === '0:0:0:0:0:0:0:0') {
      const pesan = `Host ${host} tidak sah untuk tcp_client — itu alamat untuk mendengar, bukan tujuan`;
      this.emit('status', 'error', pesan);
      this.logger.error(`[${this.label}] ${pesan}.`);
      this.logger.error(`[${this.label}] Alat ini tampaknya dimaksudkan sebagai tcp_server — alat yang`);
      this.logger.error(`[${this.label}] menelepon LIS. Buka LIS → Alat Laboratorium → ${this.alat.code} → Ubah,`);
      this.logger.error(`[${this.label}] pilih Transport = "TCP Server (LIS mendengarkan)", Host = 0.0.0.0,`);
      this.logger.error(`[${this.label}] Port = ${port}. Middleware berhenti mencoba menyambung untuk alat ini.`);

      // Tidak dijadwalkan sambung ulang: ini salah konfigurasi, bukan
      // gangguan sementara. Mengulanginya hanya membanjiri log.
      this.berhenti = true;
      return;
    }

    this.logger.debug(`[${this.label}] Menyambung ke ${host}:${port}…`);

    const socket = net.createConnection({ host, port });
    this.socket = socket;
    socket.setNoDelay(true);
    socket.setKeepAlive(true, 30000);

    socket.on('connect', () => {
      this.percobaan = 0;
      this.logger.info(`[${this.label}] Tersambung ke analyzer ${host}:${port}`);
      this.emit('status', 'online', null);
      this.emit('connection', {
        id: 'utama',
        remote: `${host}:${port}`,
        write: (buffer) => {
          if (!socket.destroyed && socket.writable) {
            socket.write(buffer);
          }
        },
      });
    });

    socket.on('data', (data) => this.emit('data', 'utama', data));

    socket.on('error', (e) => {
      this.emit('status', 'error', e.message);
      this.logger.warn(`[${this.label}] Koneksi gagal: ${e.message}`);

      // Kode galat TCP membedakan beberapa masalah yang penanganannya
      // berlainan, dan perbedaan itu hilang bila hanya pesannya dicatat.
      // Yang paling sering tertukar: ETIMEDOUT dikira salah port, lalu
      // portnya diotak-atik berjam-jam padahal paketnya tidak pernah sampai.
      const petunjuk = {
        ETIMEDOUT:
          'tidak ada jawaban sama sekali — paket tidak sampai, atau dibuang diam-diam.\n'
          + `         Biasanya alat tidak berada di ${host}, jaringannya terpisah tanpa\n`
          + '         rute, atau ada firewall di tengah. Port hampir tidak pernah jadi\n'
          + '         sebabnya: port yang salah memberi ECONNREFUSED, bukan ini.',
        ECONNREFUSED:
          `mesin di ${host} menjawab, tetapi tidak ada yang mendengar di port ${port}.\n`
          + '         Periksa nomor portnya, dan periksa apakah alat memang disetel\n'
          + '         sebagai server. Bila justru alat yang menelepon LIS, transport\n'
          + '         pada menu Alat harus tcp_server, bukan tcp_client.',
        EHOSTUNREACH:
          `jaringan menyatakan ${host} tidak terjangkau — periksa rute antar-subnet.`,
        ENETUNREACH:
          'jaringan tujuan tidak terjangkau dari mesin ini — periksa rute dan gateway.',
        ECONNRESET:
          'sambungan diputus oleh alat. Biasanya alat menolak pihak yang tidak\n'
          + '         dikenalinya, atau sudah memegang satu sambungan lain.',
      }[e.code];

      if (petunjuk) {
        this.logger.warn(`[${this.label}] ${e.code}: ${petunjuk}`);
      }
    });

    socket.on('close', () => {
      this.emit('disconnect', 'utama');
      this.emit('status', 'offline', null);
      this.socket = null;

      if (!this.berhenti) {
        this._jadwalkanSambungUlang();
      }
    });
  }

  _jadwalkanSambungUlang() {
    this.percobaan += 1;

    // Jeda meningkat sampai maksimum 60 detik agar log tidak membanjir
    // saat alat memang sedang dimatikan.
    const jeda = Math.min(60000, config.reconnectDelayMs * Math.min(this.percobaan, 12));

    if (this.percobaan <= 3 || this.percobaan % 10 === 0) {
      this.logger.info(`[${this.label}] Mencoba sambung ulang dalam ${Math.round(jeda / 1000)} detik (percobaan ${this.percobaan})`);
    }

    this.timer = setTimeout(() => this._sambung(), jeda);
  }

  broadcast(buffer) {
    if (this.socket !== null && !this.socket.destroyed && this.socket.writable) {
      this.socket.write(buffer);
    }
  }

  adaKoneksi() {
    return this.socket !== null && !this.socket.destroyed;
  }

  stop() {
    this.berhenti = true;

    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    if (this.socket !== null) {
      this.socket.destroy();
      this.socket = null;
    }
  }
}

module.exports = TcpClientTransport;
