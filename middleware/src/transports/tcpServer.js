'use strict';

/**
 * Transport TCP Server: middleware membuka port dan MENUNGGU analyzer
 * menyambung. Ini pola paling umum — sebagian besar analyzer klinik
 * dikonfigurasi dengan "Host IP" dan "Host Port" lalu menyambung sendiri
 * ke LIS setiap kali ada hasil.
 *
 * Beberapa alat membuka koneksi baru untuk setiap sampel, jadi transport
 * ini menangani banyak koneksi sekaligus.
 */

const net = require('net');
const { EventEmitter } = require('events');

class TcpServerTransport extends EventEmitter {
  constructor(alat, logger) {
    super();
    this.alat = alat;
    this.logger = logger;
    this.server = null;
    this.koneksi = new Map();
    this.urutan = 0;
  }

  get label() {
    return `${this.alat.code}`;
  }

  start() {
    const host = this.alat.tcp?.host || '0.0.0.0';
    const port = this.alat.tcp?.port;

    if (!port) {
      this.emit('status', 'error', 'Port TCP belum diatur');
      this.logger.error(`[${this.label}] Port TCP belum diatur, alat dilewati`);
      return;
    }

    this.server = net.createServer((socket) => this._terima(socket));

    this.server.on('error', (e) => {
      const galat = this._jelaskanGalat(e, host, port);

      this.logger.error(`[${this.label}] Kesalahan server TCP: ${galat.ringkas}`);
      for (const baris of galat.petunjuk) {
        this.logger.error(`  ${baris}`);
      }
      this.emit('status', 'error', galat.ringkas);

      // Kesalahan konfigurasi tidak pulih dengan menunggu. Mengulang tiap
      // 10 detik hanya membanjiri log dan menutupi masalah lain, jadi
      // pengulangan disediakan hanya untuk sebab yang memang bisa hilang
      // sendiri — mis. port yang masih ditahan proses sebelumnya.
      if (!galat.ulangi) {
        this.logger.error('  Alat ini dilewati sampai konfigurasinya diperbaiki.');
        try {
          this.server.close();
        } catch { /* diamkan */ }
        this.server = null;
        return;
      }

      setTimeout(() => {
        if (this.server !== null) {
          try {
            this.server.close();
          } catch { /* diamkan */ }
          this.server = null;
          this.start();
        }
      }, 10000);
    });

    this.server.listen(port, host, () => {
      this.logger.info(`[${this.label}] Menunggu koneksi analyzer di ${host}:${port}`);

      // Loopback tidak dapat dihubungi analyzer mana pun di jaringan.
      // Gejalanya membingungkan: middleware tampak sehat dan "menunggu",
      // tetapi alat tidak pernah berhasil menyambung dan tidak satu baris
      // pun muncul di log — jadi kondisi ini disebutkan di muka.
      if (host === '127.0.0.1' || host === 'localhost' || host === '::1') {
        this.logger.warn(
          `[${this.label}] Host "${host}" hanya menerima koneksi dari komputer ini sendiri.`
        );
        this.logger.warn(
          '  Analyzer di jaringan TIDAK akan bisa menyambung. Ubah Host menjadi '
          + `0.0.0.0 pada LIS → Alat Laboratorium → ${this.alat.code} → Ubah.`
        );
      }

      this.emit('status', 'offline', null); // menunggu, belum ada alat menyambung
    });
  }

  /**
   * Terjemahkan galat listen menjadi sebab dan langkah perbaikan.
   *
   * Pesan mentah Node ("listen EADDRNOTAVAIL: address not available
   * 192.168.0.15:5100") benar tetapi tidak menolong: yang perlu diketahui
   * adalah bahwa medan Host berarti "antarmuka jaringan komputer INI yang
   * didengarkan", bukan alamat analyzer — kekeliruan yang paling sering
   * terjadi saat pemasangan pertama.
   *
   * @returns {{ringkas:string, petunjuk:string[], ulangi:boolean}}
   */
  _jelaskanGalat(e, host, port) {
    if (e.code === 'EADDRINUSE') {
      return {
        ringkas: `Port ${port} sudah dipakai proses lain`,
        petunjuk: [
          'Sering kali instance middleware lama yang belum berhenti, atau dua',
          'alat memakai port yang sama.',
          `Cari pemakainya:  lsof -i :${port}    (Windows: netstat -ano | findstr :${port})`
        ],
        ulangi: true
      };
    }

    if (e.code === 'EADDRNOTAVAIL') {
      return {
        ringkas: `Alamat ${host} bukan milik komputer ini`,
        petunjuk: [
          'Medan Host pada konfigurasi alat berarti "antarmuka jaringan komputer',
          'INI yang didengarkan" — BUKAN alamat IP analyzer.',
          '',
          'Isi 0.0.0.0 agar mendengarkan seluruh antarmuka. Itu pilihan yang benar',
          'untuk hampir semua pemasangan.',
          '',
          `Alamat yang dimiliki komputer ini: ${this._alamatLokal().join(', ')}`,
          '',
          'Alamat analyzer tidak diisi di sini sama sekali. Justru sebaliknya:',
          'pada menu komunikasi analyzer, isikan alamat komputer ini sebagai',
          `"Host IP" dan ${port} sebagai "Host Port".`
        ],
        ulangi: false
      };
    }

    if (e.code === 'EACCES') {
      return {
        ringkas: `Tidak diizinkan mendengarkan port ${port}`,
        petunjuk: [
          'Port di bawah 1024 memerlukan hak administrator pada Linux dan macOS.',
          'Pakai port di atas 1024 — 5100, 5200, dan seterusnya.'
        ],
        ulangi: false
      };
    }

    return { ringkas: e.message, petunjuk: [], ulangi: true };
  }

  /** Alamat IPv4 non-loopback milik komputer ini. */
  _alamatLokal() {
    const os = require('os');
    const daftar = ['0.0.0.0 (semua antarmuka)'];

    for (const antarmuka of Object.values(os.networkInterfaces())) {
      for (const a of antarmuka || []) {
        if (a.family === 'IPv4' && !a.internal) daftar.push(a.address);
      }
    }
    daftar.push('127.0.0.1 (hanya komputer ini)');

    return daftar;
  }

  _terima(socket) {
    this.urutan += 1;
    const id = `c${this.urutan}`;
    const asal = `${socket.remoteAddress}:${socket.remotePort}`;

    socket.setNoDelay(true);
    socket.setKeepAlive(true, 30000);

    this.koneksi.set(id, socket);
    this.logger.info(`[${this.label}] Analyzer tersambung dari ${asal}`);
    this.emit('status', 'online', null);
    this.emit('connection', {
      id,
      remote: asal,
      write: (buffer) => {
        if (!socket.destroyed && socket.writable) {
          socket.write(buffer);
        }
      },
    });

    socket.on('data', (data) => this.emit('data', id, data));

    socket.on('error', (e) => {
      this.logger.warn(`[${this.label}] Kesalahan soket ${asal}: ${e.message}`);
    });

    socket.on('close', () => {
      this.koneksi.delete(id);
      this.logger.info(`[${this.label}] Analyzer terputus (${asal})`);
      this.emit('disconnect', id);

      if (this.koneksi.size === 0) {
        this.emit('status', 'offline', null);
      }
    });
  }

  /** Kirim ke seluruh koneksi aktif (umumnya hanya satu). */
  broadcast(buffer) {
    for (const socket of this.koneksi.values()) {
      if (!socket.destroyed && socket.writable) {
        socket.write(buffer);
      }
    }
  }

  adaKoneksi() {
    return this.koneksi.size > 0;
  }

  stop() {
    for (const socket of this.koneksi.values()) {
      socket.destroy();
    }
    this.koneksi.clear();

    if (this.server !== null) {
      try {
        this.server.close();
      } catch { /* diamkan */ }
      this.server = null;
    }
  }
}

module.exports = TcpServerTransport;
