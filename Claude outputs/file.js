'use strict';

/**
 * Transport BERKAS — untuk alat yang bertukar data lewat folder, bukan
 * soket. Dipakai BioSystems A15, yang menulis hasil ke berkas teks pada
 * PC-nya sendiri dan membaca worklist dari berkas lain.
 *
 * MENGAPA MENYISIR, BUKAN MENGAWASI
 *
 * fs.watch() terlihat lebih elegan, tetapi tidak dapat diandalkan pada
 * folder berbagi jaringan — dan folder A15 memang diakses lewat UNC dari
 * mesin lain. Pada SMB, peristiwa perubahan sering tidak sampai sama
 * sekali. Penyisiran berkala membosankan tetapi tidak pernah melewatkan
 * berkas; untuk hasil pasien, itu pertukaran yang benar.
 *
 * MENGAPA MENCATAT POSISI, BUKAN "SUDAH DIPROSES"
 *
 * Berkas ekspor online A15 TIDAK ditulis sekali lalu selesai — ia
 * BERTAMBAH setiap kali ada hasil baru, dengan nama yang sama sepanjang
 * sesi. Menandai berkas "sudah diproses" berarti seluruh hasil yang
 * ditambahkan sesudahnya hilang tanpa jejak. Karena itu yang dicatat
 * adalah POSISI BYTE terakhir yang sudah dibaca per berkas, dan tiap
 * penyisiran hanya mengambil bagian yang belum terbaca.
 *
 * Posisi itu disimpan ke disk, sehingga middleware yang di-restart tidak
 * membaca ulang hasil lama — yang akan memunculkan hasil ganda pada
 * order yang sama.
 *
 * SATU HAL YANG SENGAJA TIDAK DILAKUKAN
 *
 * Manual A15 menyebut program LIS boleh menghapus berkas online setelah
 * sesi selesai. Transport ini TIDAK menghapus apa pun. Menghapus berkas
 * hasil pasien atas inisiatif sendiri adalah tindakan yang tak dapat
 * dibatalkan, dan bila terjadi sebelum hasilnya benar-benar tersimpan di
 * LIS, datanya hilang untuk selamanya. Berkas dibiarkan; A15 sendiri
 * yang membersihkannya.
 */

const fs = require('fs');
const path = require('path');
const { EventEmitter } = require('events');

class FileTransport extends EventEmitter {
  constructor(alat, logger) {
    super();
    this.alat    = alat;
    this.logger  = logger;
    this.berhenti = false;
    this.timer   = null;

    this.folderKeluar = alat.file?.folderKeluar ?? null;
    this.folderMasuk  = alat.file?.folderMasuk ?? null;
    this.jedaMs       = Math.max(2000, alat.file?.jedaMs ?? 5000);
    // Hanya berkas ekspor ONLINE. Folder Export A15 juga memuat
    // EXPAuto(...).txt (dibuat otomatis saat reset) dan Exp(...).txt
    // (tombol Export results) yang berisi hasil YANG SAMA — membaca
    // semuanya berarti tiap hasil diproses dua sampai tiga kali.
    this.polaBerkas   = alat.file?.pola
      ? new RegExp(alat.file.pola, 'i')
      : (alat.protocol === 'a15' ? /^online.*\.txt$/i : /\.txt$/i);

    // Posisi baca terakhir per berkas, disimpan agar tahan restart.
    this.berkasPosisi = path.join(
      alat.file?.folderStatus ?? process.cwd(),
      `.posisi-${alat.code}.json`
    );
    this.posisi = this._muatPosisi();
  }

  get label() {
    return this.alat.code;
  }

  // -----------------------------------------------------------------

  start() {
    this.berhenti = false;

    if (!this.folderKeluar) {
      const pesan = 'Folder keluar belum diatur';
      this.emit('status', 'error', pesan);
      this.logger.error(`[${this.label}] ${pesan} — alat dilewati.`);
      this.logger.error(`[${this.label}] Isi kolom Folder Keluar pada menu Alat, mis.`);
      this.logger.error(`[${this.label}] \\\\192.168.20.xxx\\A15\\Export`);
      return;
    }

    this.logger.info(`[${this.label}] Menyisir ${this.folderKeluar} tiap ${Math.round(this.jedaMs / 1000)} detik`);

    // Sambungan berbasis berkas tidak punya "koneksi", tetapi sisa
    // middleware bekerja dengan konsep itu. Satu koneksi semu dibuat agar
    // sesi protokol dan pencatatan status tetap seragam.
    this.emit('connection', {
      id: 'berkas',
      remote: this.folderKeluar,
      write: () => { /* penulisan worklist lewat kirimWorklist() */ },
    });

    this._sisir();
    this.timer = setInterval(() => this._sisir(), this.jedaMs);
  }

  stop() {
    this.berhenti = true;
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
    this._simpanPosisi();
    this.emit('disconnect', 'berkas');
    this.emit('status', 'offline', null);
  }

  adaKoneksi() {
    return !this.berhenti && this.folderKeluar !== null;
  }

  broadcast() { /* alat berkas tidak menerima siaran */ }

  // -----------------------------------------------------------------

  _sisir() {
    if (this.berhenti) return;

    let daftar;
    try {
      daftar = fs.readdirSync(this.folderKeluar);
    } catch (e) {
      // Folder jaringan putus adalah keadaan biasa, bukan bencana —
      // tetapi harus terlihat, karena selama putus tidak ada hasil yang
      // masuk dan tidak ada yang menyadarinya.
      this.emit('status', 'error', `Folder tidak terbaca: ${e.code}`);
      this.logger.warn(`[${this.label}] Folder ${this.folderKeluar} tidak terbaca: ${e.code}`);
      if (e.code === 'ENOENT') {
        this.logger.warn(`[${this.label}] Folder tidak ada. Periksa ejaan jalur dan nama berbaginya.`);
      } else if (e.code === 'EACCES' || e.code === 'EPERM') {
        this.logger.warn(`[${this.label}] Izin ditolak. Mesin middleware harus punya hak baca pada berbagi itu.`);
      }
      return;
    }

    this.emit('status', 'online', null);

    let adaBaru = false;

    for (const nama of daftar.sort()) {
      if (!this.polaBerkas.test(nama)) continue;

      const penuh = path.join(this.folderKeluar, nama);
      let stat;
      try {
        stat = fs.statSync(penuh);
      } catch {
        continue;
      }
      if (!stat.isFile()) continue;

      const sudah = this.posisi[nama] ?? 0;

      // Berkas menyusut = berkas diganti dengan nama sama (A15 memulai
      // sesi baru). Posisi lama tidak lagi bermakna; mulai dari awal.
      const mulai = stat.size < sudah ? 0 : sudah;

      if (stat.size <= mulai) continue;

      let potongan;
      try {
        const fd  = fs.openSync(penuh, 'r');
        const buf = Buffer.alloc(stat.size - mulai);
        fs.readSync(fd, buf, 0, buf.length, mulai);
        fs.closeSync(fd);
        potongan = buf.toString(this.alat.encoding === 'utf8' ? 'utf8' : 'latin1');
      } catch (e) {
        this.logger.warn(`[${this.label}] Gagal membaca ${nama}: ${e.message}`);
        continue;
      }

      // Baris terakhir mungkin belum selesai ditulis. Hanya bagian sampai
      // pergantian baris TERAKHIR yang diproses; sisanya menunggu
      // penyisiran berikutnya. Tanpa ini, satu hasil dapat terpotong di
      // tengah angka dan tersimpan salah.
      const batas = potongan.lastIndexOf('\n');
      if (batas === -1) continue;

      const siap = potongan.slice(0, batas + 1);
      this.posisi[nama] = mulai + Buffer.byteLength(siap, this.alat.encoding === 'utf8' ? 'utf8' : 'latin1');

      adaBaru = true;
      this.logger.debug(`[${this.label}] ${nama}: ${siap.split(/\r?\n/).filter(Boolean).length} baris baru`);
      this.emit('data', 'berkas', Buffer.from(siap, 'utf8'));
    }

    if (adaBaru) {
      this._simpanPosisi();
    }
  }

  // -----------------------------------------------------------------

  /**
   * Tulis worklist ke folder impor alat.
   *
   * A15 membaca berkas bernama tetap "import.txt", dan hanya saat operator
   * menekan tombol Import session. Jadi menimpa berkas yang belum sempat
   * dibaca akan menghilangkan worklist sebelumnya — karena itu berkas
   * lama diperiksa dulu.
   */
  tulisWorklist(isi) {
    if (!this.folderMasuk) {
      this.logger.warn(`[${this.label}] Folder masuk belum diatur, worklist tidak ditulis.`);
      return false;
    }

    const tujuan = path.join(this.folderMasuk, 'import.txt');

    try {
      if (fs.existsSync(tujuan) && fs.statSync(tujuan).size > 0) {
        this.logger.warn(`[${this.label}] import.txt sebelumnya belum diambil alat — worklist ditambahkan, bukan ditimpa.`);
        fs.appendFileSync(tujuan, isi, 'latin1');
      } else {
        fs.writeFileSync(tujuan, isi, 'latin1');
      }

      return true;
    } catch (e) {
      this.logger.error(`[${this.label}] Gagal menulis worklist: ${e.message}`);

      return false;
    }
  }

  /** Baca Errors.txt bila ada, lalu kosongkan agar tidak terbaca ulang. */
  bacaErrors() {
    if (!this.folderMasuk) return [];

    const berkas = path.join(this.folderMasuk, 'Errors.txt');
    try {
      if (!fs.existsSync(berkas)) return [];
      const isi = fs.readFileSync(berkas, 'latin1');
      if (isi.trim() === '') return [];
      fs.writeFileSync(berkas, '', 'latin1');

      return isi.split(/\r?\n/).filter((b) => b.trim() !== '');
    } catch {
      return [];
    }
  }

  // -----------------------------------------------------------------

  _muatPosisi() {
    try {
      return JSON.parse(fs.readFileSync(this.berkasPosisi, 'utf8'));
    } catch {
      return {};
    }
  }

  _simpanPosisi() {
    try {
      fs.writeFileSync(this.berkasPosisi, JSON.stringify(this.posisi, null, 2), 'utf8');
    } catch (e) {
      // Gagal menyimpan posisi tidak menghentikan pembacaan, tetapi
      // restart berikutnya akan membaca ulang — dan itu harus diketahui.
      this.logger.warn(`[${this.label}] Posisi baca gagal disimpan: ${e.message}`);
    }
  }
}

module.exports = FileTransport;
