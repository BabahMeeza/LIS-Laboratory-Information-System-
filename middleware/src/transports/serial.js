'use strict';

/**
 * Transport Serial RS232.
 *
 * Memerlukan paket opsional `serialport`:
 *     cd middleware && npm install serialport
 *
 * Paket ini sengaja dijadikan optionalDependencies agar middleware tetap
 * dapat dipasang di server yang sama sekali tidak memakai alat serial —
 * pemasangan `serialport` memerlukan kompilasi modul asli yang tidak
 * selalu tersedia.
 *
 * Menemukan nama port:
 *   Windows : Device Manager → Ports (COM & LPT) → mis. COM3
 *   macOS   : ls /dev/tty.*  → mis. /dev/tty.usbserial-1410
 *   Linux   : ls /dev/ttyUSB* /dev/ttyS*
 */

const { EventEmitter } = require('events');
const { config } = require('../config');

let SerialPort = null;
let galatMuat = null;

try {
  ({ SerialPort } = require('serialport'));
} catch (e) {
  galatMuat = e;
}

class SerialTransport extends EventEmitter {
  constructor(alat, logger) {
    super();
    this.alat = alat;
    this.logger = logger;
    this.port = null;
    this.timer = null;
    this.berhenti = false;
    this.percobaan = 0;
  }

  get label() {
    return `${this.alat.code}`;
  }

  static tersedia() {
    return SerialPort !== null;
  }

  start() {
    if (SerialPort === null) {
      const pesan =
        'Paket "serialport" belum terpasang. Jalankan: cd middleware && npm install serialport';
      this.logger.error(`[${this.label}] ${pesan}`);
      if (galatMuat) {
        this.logger.debug(`[${this.label}] Detail: ${galatMuat.message}`);
      }
      this.emit('status', 'error', pesan);
      return;
    }

    this.berhenti = false;
    this._buka();
  }

  _buka() {
    const s = this.alat.serial || {};
    const jalur = s.path;

    if (!jalur) {
      this.emit('status', 'error', 'Nama port serial belum diatur');
      this.logger.error(`[${this.label}] Nama port serial belum diatur, alat dilewati`);
      return;
    }

    const opsi = {
      path: jalur,
      baudRate: s.baudRate || 9600,
      dataBits: s.dataBits || 8,
      stopBits: s.stopBits || 1,
      parity: s.parity || 'none',
      rtscts: s.flowControl === 'rtscts',
      xon: s.flowControl === 'xonxoff',
      xoff: s.flowControl === 'xonxoff',
      autoOpen: false,
    };

    this.logger.debug(
      `[${this.label}] Membuka ${jalur} @ ${opsi.baudRate} ${opsi.dataBits}${(opsi.parity || 'n')[0].toUpperCase()}${opsi.stopBits}`
    );

    let port;
    try {
      port = new SerialPort(opsi);
    } catch (e) {
      this.emit('status', 'error', e.message);
      this.logger.error(`[${this.label}] Gagal menyiapkan port serial: ${e.message}`);
      if (!this.berhenti) {
        this._jadwalkanBukaUlang();
      }
      return;
    }

    this.port = port;

    port.open((e) => {
      if (e) {
        this.emit('status', 'error', e.message);
        this.logger.warn(`[${this.label}] Gagal membuka ${jalur}: ${e.message}`);
        if (!this.berhenti) {
          this._jadwalkanBukaUlang();
        }
        return;
      }

      this.percobaan = 0;
      this.logger.info(`[${this.label}] Port serial ${jalur} terbuka`);
      this.emit('status', 'online', null);
      this.emit('connection', {
        id: 'serial',
        remote: jalur,
        write: (buffer) => {
          if (port.isOpen) {
            port.write(buffer);
          }
        },
      });
    });

    port.on('data', (data) => this.emit('data', 'serial', data));

    port.on('error', (e) => {
      this.logger.warn(`[${this.label}] Kesalahan port serial: ${e.message}`);
      this.emit('status', 'error', e.message);
    });

    port.on('close', () => {
      this.logger.info(`[${this.label}] Port serial ditutup`);
      this.emit('disconnect', 'serial');
      this.emit('status', 'offline', null);
      this.port = null;

      if (!this.berhenti) {
        this._jadwalkanBukaUlang();
      }
    });
  }

  _jadwalkanBukaUlang() {
    this.percobaan += 1;
    const jeda = Math.min(60000, config.reconnectDelayMs * Math.min(this.percobaan, 12));

    if (this.percobaan <= 3 || this.percobaan % 10 === 0) {
      this.logger.info(`[${this.label}] Mencoba membuka ulang port dalam ${Math.round(jeda / 1000)} detik`);
    }

    this.timer = setTimeout(() => this._buka(), jeda);
  }

  broadcast(buffer) {
    if (this.port !== null && this.port.isOpen) {
      this.port.write(buffer);
    }
  }

  adaKoneksi() {
    return this.port !== null && this.port.isOpen;
  }

  stop() {
    this.berhenti = true;

    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    if (this.port !== null && this.port.isOpen) {
      this.port.close(() => { /* diamkan */ });
    }
    this.port = null;
  }
}

module.exports = SerialTransport;
