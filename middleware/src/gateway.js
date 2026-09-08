'use strict';

/**
 * Gateway: menghubungkan setiap alat laboratorium dengan LIS.
 *
 * Untuk setiap alat aktif, gateway membuka transport yang sesuai
 * (TCP server, TCP client, atau serial), membungkusnya dengan sesi
 * protokol (ASTM, HL7, atau raw), lalu:
 *
 *   hasil dari alat  → normalisasi → spool → kirim ke LIS
 *   host query alat  → tanya LIS   → jawab ke alat
 *   antrian worklist → ambil dari LIS → dorong ke alat dua arah
 */

const logger = require('./logger');
const { config } = require('./config');
const lis = require('./lisClient');
const spool = require('./spool');

const TcpServerTransport = require('./transports/tcpServer');
const TcpClientTransport = require('./transports/tcpClient');
const SerialTransport = require('./transports/serial');

const astm = require('./protocols/astm');
const hl7 = require('./protocols/hl7');
const raw = require('./protocols/raw');

class Gateway {
  constructor() {
    /** @type {Map<string,object>} kode alat → { alat, transport, sesi, status } */
    this.alat = new Map();
    this.timerHeartbeat = null;
    this.timerKonfigurasi = null;
    this.timerSpool = null;
    this.timerWorklist = null;
    this.mulaiPada = new Date();
  }

  // -------------------------------------------------------------------
  // Siklus hidup
  // -------------------------------------------------------------------

  async mulai() {
    await this.muatKonfigurasi();

    this.timerSpool = setInterval(() => {
      spool.proses().catch((e) => logger.error(`Pemrosesan spool gagal: ${e.message}`));
    }, 15000);

    this.timerHeartbeat = setInterval(
      () => this.kirimHeartbeat(),
      Math.max(10, config.heartbeatSeconds) * 1000
    );

    this.timerKonfigurasi = setInterval(
      () => this.muatKonfigurasi().catch((e) => logger.error(`Muat ulang konfigurasi gagal: ${e.message}`)),
      Math.max(60, config.configRefreshSeconds) * 1000
    );

    this.timerWorklist = setInterval(
      () => this.tarikWorklist(),
      Math.max(5, config.worklistPollSeconds) * 1000
    );

    // Kirim heartbeat pertama segera agar status di LIS cepat terlihat.
    setTimeout(() => this.kirimHeartbeat(), 2000);
  }

  async hentikan() {
    for (const t of [this.timerHeartbeat, this.timerKonfigurasi, this.timerSpool, this.timerWorklist]) {
      if (t) clearInterval(t);
    }

    for (const entri of this.alat.values()) {
      try {
        entri.transport.stop();
      } catch { /* diamkan */ }
      for (const sesi of entri.sesi.values()) {
        try {
          sesi.hancurkan();
        } catch { /* diamkan */ }
      }
    }
    this.alat.clear();

    // Beri kesempatan terakhir mengosongkan antrian.
    try {
      await spool.proses();
    } catch { /* diamkan */ }
  }

  // -------------------------------------------------------------------
  // Konfigurasi alat
  // -------------------------------------------------------------------

  async muatKonfigurasi() {
    let data;
    try {
      data = await lis.ambilKonfigurasiAlat();
    } catch (e) {
      logger.error(`Tidak dapat mengambil konfigurasi alat dari LIS: ${e.message}`);

      // Kesalahan kredensial tidak akan pulih dengan menunggu, jadi
      // langkah perbaikannya disebutkan sekaligus alih-alih membiarkan
      // pesan 401 berulang tiap siklus tanpa petunjuk apa pun.
      if (e.status === 401 || e.status === 403) {
        const kunci = config.lis.apiKey || '(kosong)';
        logger.error('  Kunci yang dipakai: ' + kunci);
        if (/^lis_(mw|kz)_0{8}/.test(kunci)) {
          logger.error('  Ini kunci bawaan skema. bin/install.php sengaja menonaktifkannya.');
        }
        logger.error('  Perbaikan: buka LIS → Pengaturan → Kredensial API →');
        logger.error('  "Terbitkan kredensial baru" dengan cakupan "instrument",');
        logger.error('  salin LIS_API_KEY dan LIS_API_SECRET ke middleware/.env,');
        logger.error('  lalu jalankan ulang middleware. Secret hanya tampil sekali.');
      }

      return;
    }

    const daftar = Array.isArray(data.instruments) ? data.instruments : [];
    const kodeBaru = new Set(daftar.map((a) => a.code));

    this._peringatkanPortBentrok(daftar);

    // Tutup alat yang sudah tidak aktif di LIS.
    for (const [kode, entri] of this.alat.entries()) {
      if (!kodeBaru.has(kode)) {
        logger.info(`[${kode}] Alat dinonaktifkan di LIS, koneksi ditutup`);
        entri.transport.stop();
        this.alat.delete(kode);
      }
    }

    for (const alat of daftar) {
      const lama = this.alat.get(alat.code);

      if (lama === undefined) {
        this._pasang(alat);
        continue;
      }

      // Buka ulang hanya bila parameter koneksi benar-benar berubah.
      if (this._tandaKoneksi(lama.alat) !== this._tandaKoneksi(alat)) {
        logger.info(`[${alat.code}] Konfigurasi koneksi berubah, membuka ulang`);
        lama.transport.stop();
        this.alat.delete(alat.code);
        this._pasang(alat);
      } else {
        lama.alat = alat; // perbarui atribut non-koneksi
      }
    }

    if (this.alat.size === 0) {
      logger.warn(
        'Tidak ada alat aktif di LIS. Tambahkan alat pada menu "Alat Laboratorium" ' +
        'lalu centang "Alat aktif".'
      );
    }
  }

  /**
   * Peringatkan bila dua alat tcp_server memakai port yang sama.
   *
   * Hanya alat pertama yang berhasil mendengarkan; sisanya gagal dengan
   * EADDRINUSE dan mengulang selamanya. Karena kegagalannya muncul jauh di
   * bawah pada log — dan alat yang berhasil terlihat normal — bentrokan ini
   * mudah tidak disadari sampai analyzer kedua dipasang dan "tidak pernah
   * mengirim apa pun".
   */
  _peringatkanPortBentrok(daftar) {
    const perPort = new Map();

    for (const a of daftar) {
      if (a.transport !== 'tcp_server' || !a.tcp?.port) continue;
      const kunci = String(a.tcp.port);
      if (!perPort.has(kunci)) perPort.set(kunci, []);
      perPort.get(kunci).push(a.code);
    }

    for (const [port, kode] of perPort.entries()) {
      if (kode.length < 2) continue;

      logger.warn(`Port ${port} dipakai oleh ${kode.length} alat: ${kode.join(', ')}`);
      logger.warn(
        `  Hanya satu yang dapat mendengarkan. Beri port berbeda untuk tiap alat `
        + '(mis. 5100, 5200, 5300) pada LIS → Alat Laboratorium → Ubah.'
      );
    }
  }

  _tandaKoneksi(a) {
    return JSON.stringify([
      a.transport, a.protocol, a.mode,
      a.tcp?.host, a.tcp?.port,
      a.serial?.path, a.serial?.baudRate, a.serial?.dataBits,
      a.serial?.stopBits, a.serial?.parity, a.serial?.flowControl,
    ]);
  }

  _pasang(alat) {
    let transport;

    switch (alat.transport) {
      case 'tcp_server':
        transport = new TcpServerTransport(alat, logger);
        break;
      case 'tcp_client':
        transport = new TcpClientTransport(alat, logger);
        break;
      case 'serial':
        transport = new SerialTransport(alat, logger);
        break;
      default:
        logger.error(`[${alat.code}] Transport "${alat.transport}" tidak dikenali`);
        return;
    }

    const entri = {
      alat,
      transport,
      sesi: new Map(),
      status: 'offline',
      galat: null,
      terakhirData: null,
      jumlahPesan: 0,
    };

    this.alat.set(alat.code, entri);

    transport.on('status', (status, galat) => {
      entri.status = status;
      entri.galat = galat;
    });

    transport.on('connection', (koneksi) => {
      const sesi = this._buatSesi(entri, koneksi);
      entri.sesi.set(koneksi.id, sesi);
    });

    transport.on('data', (id, data) => {
      const sesi = entri.sesi.get(id);
      entri.terakhirData = new Date();
      if (sesi !== undefined) {
        try {
          sesi.feed(data);
        } catch (e) {
          logger.error(`[${alat.code}] Kesalahan saat memproses data: ${e.message}`);
        }
      }
    });

    transport.on('disconnect', (id) => {
      const sesi = entri.sesi.get(id);
      if (sesi !== undefined) {
        try {
          sesi.hancurkan();
        } catch { /* diamkan */ }
        entri.sesi.delete(id);
      }
    });

    logger.info(
      `[${alat.code}] ${alat.name} — ${String(alat.protocol).toUpperCase()} / ${alat.transport}` +
      (alat.mode === 'bidirectional' ? ' (dua arah)' : '')
    );

    transport.start();
  }

  // -------------------------------------------------------------------
  // Sesi protokol
  // -------------------------------------------------------------------

  _buatSesi(entri, koneksi) {
    const alat = entri.alat;

    if (alat.protocol === 'astm') {
      const sesi = new astm.AstmSession({
        write: koneksi.write,
        logger,
        nama: alat.code,
        timeoutMs: config.astmSessionTimeoutMs,
      });

      sesi.on('records', (records, mentah) => {
        entri.jumlahPesan += 1;
        const payload = astm.keNormal(records, alat.code, mentah);
        this._terimaHasil(alat, payload);
      });

      sesi.on('query', ({ sampleId }) => {
        this._jawabQueryAstm(alat, sesi, sampleId);
      });

      sesi.on('error', (e) => logger.error(`[${alat.code}] ${e.message}`));

      return sesi;
    }

    if (alat.protocol === 'hl7') {
      const sesi = new hl7.Hl7Session({
        write: koneksi.write,
        logger,
        nama: alat.code,
        // Encoding mengikuti pengaturan alat. Untuk Mindray isi "utf8":
        // MSH-18 pada pesannya berbunyi "UNICODE", yang berarti UTF-8.
        encoding: alat.encoding,
      });

      sesi.on('message', (pesan) => {
        entri.jumlahPesan += 1;
        this._tanganiPesanHl7(alat, sesi, pesan);
      });

      return sesi;
    }

    // Protokol raw
    const sesi = new raw.RawSession({ logger, nama: alat.code });

    sesi.on('text', (teks) => {
      entri.jumlahPesan += 1;
      const payload = raw.keNormal(teks, alat.code);
      this._terimaHasil(alat, payload);
    });

    return sesi;
  }

  _tanganiPesanHl7(alat, sesi, pesan) {
    const jenis = hl7.jenisPesan(pesan);
    logger.debug(`[${alat.code}] Pesan HL7 diterima: ${jenis || '(tanpa jenis)'}`);

    // Permintaan worklist dari alat.
    //
    // ORM^O01 disertakan karena itulah bentuk yang dipakai analyzer
    // Mindray: "the main unit requests LIS to re-fill the order message"
    // (BC-5800 LIS Protocol Manual, D.4.2). Dijawab dengan ORR^O02.
    if (jenis.startsWith('QRY') || jenis.startsWith('QBP') || jenis.startsWith('ORM')) {
      const sampleId = hl7.sampleIdQuery(pesan);
      logger.info(`[${alat.code}] Host query HL7 untuk sample "${sampleId}"`);

      // Mode "off" berarti BENAR-BENAR tidak membalas — bukan membalas ACK.
      //
      // Analyzer yang parsernya rapuh dapat jatuh oleh balasan apa pun yang
      // tidak ia harapkan, termasuk ACK. Diam adalah satu-satunya jawaban
      // yang pasti aman: alat akan menunggu sampai batas ACK-nya habis lalu
      // menampilkan "tidak ada jawaban dari LIS" — gagal dengan tenang,
      // bukan fault yang menuntut restart.
      if (String(config.hl7WorklistReply).toLowerCase() === 'off') {
        logger.warn(
          `[${alat.code}] Permintaan worklist TIDAK dijawab (HL7_WORKLIST_REPLY=off).`
        );
        logger.warn(
          '  Alat akan menampilkan kehabisan waktu menunggu LIS. Itu disengaja:'
        );
        logger.warn(
          '  lebih baik alat menunggu sia-sia daripada menerima jawaban yang'
        );
        logger.warn(
          '  dapat membuatnya fault. Masukkan Sample ID langsung di layar alat.'
        );

        return;
      }

      lis.ambilWorklistSampel(sampleId, alat.code)
        .then((worklist) => {
          // Sebagian dialek (mis. Mindray) menjawab dengan lebih dari satu
          // pesan berurutan: pengakuan query dulu, baru isinya.
          const balasan = hl7.bangunOrmWorklist(worklist, pesan, 'LIS', config.hl7WorklistReply);
          for (const b of (Array.isArray(balasan) ? balasan : [balasan])) {
            sesi.kirim(b);
          }
          if (worklist === null) {
            logger.warn(`[${alat.code}] Sample "${sampleId}" tidak ditemukan di LIS`);
          } else {
            logger.info(`[${alat.code}] Worklist dikirim: ${(worklist.tests || []).join(', ')}`);
          }
        })
        .catch((e) => {
          logger.error(`[${alat.code}] Gagal mengambil worklist: ${e.message}`);
          sesi.kirim(hl7.bangunAck(pesan, 'LIS', 'AE', 'Kesalahan internal LIS'));
        });

      return;
    }

    // Data kontrol mutu.
    //
    // QC dikirim sebagai ORU^R01 juga — yang membedakannya hanya MSH-11.
    // Memprosesnya sebagai hasil pasien berbahaya: PID-3 pada pesan QC
    // berisi nomor lot kontrol, bukan nomor rekam medis, dan bila nomor
    // itu kebetulan cocok dengan sebuah barcode, nilai kontrol akan masuk
    // ke hasil pasien.
    if ((jenis.startsWith('ORU') || jenis.startsWith('OUL')) && hl7.adalahQc(pesan)) {
      logger.info(
        `[${alat.code}] Data kontrol mutu diterima (MSH-11=${hl7.processingId(pesan)}) — `
        + 'disimpan sebagai pesan mentah, tidak diproses sebagai hasil pasien.'
      );

      lis.kirimPesanMentah(alat.code, 'hl7', pesan, null)
        .catch((e) => logger.debug(`[${alat.code}] Pencatatan pesan QC gagal: ${e.message}`));

      sesi.kirim(hl7.bangunAck(pesan, 'LIS', 'AA'));

      return;
    }

    // Pesan hasil pasien.
    if (jenis.startsWith('ORU') || jenis.startsWith('OUL')) {
      const payload = hl7.keNormal(pesan, alat.code);
      const aman = this._terimaHasil(alat, payload);

      // Jawab sesuai kenyataan. Membalas "AA" padahal hasilnya gagal
      // disimpan berarti memberi tahu analyzer bahwa datanya sudah aman —
      // alat lalu menghapusnya dari antrian kirim, dan hasil pasien hilang
      // tanpa jejak. Dengan "AE", alat tahu pengirimannya gagal dan dapat
      // mengulang (Auto Retransmit) atau menahan hasilnya.
      sesi.kirim(aman
        ? hl7.bangunAck(pesan, 'LIS', 'AA')
        : hl7.bangunAck(pesan, 'LIS', 'AE', 'Hasil gagal disimpan di LIS'));

      return;
    }

    // Jenis lain: cukup diakui agar alat tidak menahan antrian kirimnya.
    sesi.kirim(hl7.bangunAck(pesan, 'LIS', 'AA'));
  }

  _jawabQueryAstm(alat, sesi, sampleId) {
    if (sampleId === '') {
      sesi.sendRecords(astm.bangunJawabanQuery(null, 'LIS'));
      return;
    }

    lis.ambilWorklistSampel(sampleId, alat.code)
      .then((worklist) => {
        if (worklist === null) {
          logger.warn(`[${alat.code}] Sample "${sampleId}" tidak ditemukan di LIS`);
          sesi.sendRecords(astm.bangunJawabanQuery(null, 'LIS'));
          return;
        }

        logger.info(
          `[${alat.code}] Worklist untuk ${sampleId}: ${(worklist.tests || []).join(', ') || '(kosong)'}`
        );
        sesi.sendRecords(astm.bangunJawabanQuery(worklist, 'LIS'));
      })
      .catch((e) => {
        logger.error(`[${alat.code}] Gagal mengambil worklist: ${e.message}`);
        sesi.sendRecords(astm.bangunJawabanQuery(null, 'LIS'));
      });
  }

  // -------------------------------------------------------------------
  // Hasil
  // -------------------------------------------------------------------

  _terimaHasil(alat, payload) {
    const jumlahSampel = payload.samples.length;
    const jumlahHasil = payload.samples.reduce((n, s) => n + s.results.length, 0);

    if (jumlahHasil === 0) {
      logger.debug(`[${alat.code}] Pesan tanpa hasil, hanya dicatat sebagai pesan mentah`);

      // Tetap simpan agar jejak komunikasi lengkap saat menelusuri masalah.
      lis.kirimPesanMentah(alat.code, payload.protocol, payload.raw || '', null)
        .catch((e) => logger.debug(`[${alat.code}] Pencatatan pesan mentah gagal: ${e.message}`));

      return true;
    }

    logger.info(
      `[${alat.code}] Menerima ${jumlahHasil} hasil untuk ${jumlahSampel} sampel ` +
      `(${payload.samples.map((s) => s.sample_id || '?').join(', ')})`
    );

    // Tulis ke disk lebih dulu — hasil tidak boleh hilang bila LIS mati.
    try {
      spool.simpan(payload);
    } catch (e) {
      logger.error(`[${alat.code}] GAGAL menyimpan hasil ke spool: ${e.message}`);
      logger.error('  Hasil ini TIDAK tersimpan. Periksa ruang disk dan izin tulis '
        + 'pada folder middleware/spool.');

      return false;
    }

    spool.proses().catch((e) => logger.error(`Pengiriman spool gagal: ${e.message}`));

    return true;
  }

  // -------------------------------------------------------------------
  // Worklist keluar & heartbeat
  // -------------------------------------------------------------------

  /** Dorong antrian worklist dari LIS ke alat dua arah yang sedang tersambung. */
  async tarikWorklist() {
    for (const entri of this.alat.values()) {
      const alat = entri.alat;

      if (alat.mode !== 'bidirectional' || !entri.transport.adaKoneksi()) {
        continue;
      }

      // Jangan pernah mendorong apa pun ke alat HL7 saat pengiriman
      // worklist dimatikan.
      //
      // Sebelumnya jalur ini tetap berjalan dan mengirimkan ACK yang tidak
      // pernah diminta alat — persis lalu lintas tak diminta yang hendak
      // dicegah oleh pengaman "off". Pada analyzer dengan parser rapuh,
      // pesan tak diminta itulah yang paling berbahaya: alat tidak sedang
      // menunggu apa pun ketika pesan datang.
      if (alat.protocol === 'hl7'
        && String(config.hl7WorklistReply).toLowerCase() === 'off') {
        continue;
      }

      let antrian;
      try {
        antrian = await lis.ambilAntrianWorklist(alat.code, true);
      } catch (e) {
        logger.debug(`[${alat.code}] Pengambilan antrian worklist gagal: ${e.message}`);
        continue;
      }

      if (!Array.isArray(antrian) || antrian.length === 0) {
        continue;
      }

      const sesi = [...entri.sesi.values()][0];
      if (sesi === undefined) {
        continue;
      }

      logger.info(`[${alat.code}] Mendorong ${antrian.length} sampel dari antrian worklist`);

      for (const item of antrian) {
        try {
          if (alat.protocol === 'astm') {
            sesi.sendRecords(astm.bangunJawabanQuery(item, 'LIS'));
          } else if (alat.protocol === 'hl7') {
            // Tanpa pesan asal, susun pesan query semu sebagai acuan.
            // Versinya 2.3.1 — versi yang dipakai mayoritas analyzer — agar
            // balasan mandiri tidak mengumumkan versi yang lebih baru
            // daripada yang dipahami alat.
            const semu = 'MSH|^~\\&|' + alat.code + '|LAB|LIS|LAB|' +
              hl7.capWaktuSekarang() + '||QRY^Q02|1|P|2.3.1\r';
            const balasan = hl7.bangunOrmWorklist(item, semu, 'LIS', config.hl7WorklistReply);
            for (const b of (Array.isArray(balasan) ? balasan : [balasan])) {
              sesi.kirim(b);
            }
          }
        } catch (e) {
          logger.error(`[${alat.code}] Gagal mengirim worklist: ${e.message}`);
        }
      }
    }
  }

  async kirimHeartbeat() {
    const daftar = [...this.alat.values()].map((e) => ({
      code: e.alat.code,
      status: e.status,
      error: e.galat,
    }));

    if (daftar.length === 0) {
      return;
    }

    try {
      await lis.heartbeat(daftar);
    } catch (e) {
      logger.debug(`Heartbeat gagal: ${e.message}`);
    }
  }

  /** Ringkasan untuk endpoint /health. */
  ringkasan() {
    return {
      status: 'berjalan',
      versi: '1.0.0',
      mulai: this.mulaiPada.toISOString(),
      uptime_detik: Math.round(process.uptime()),
      lis: config.lis.baseUrl,
      spool: {
        tertunda: spool.jumlah(),
        gagal: spool.jumlahGagal(),
      },
      alat: [...this.alat.values()].map((e) => ({
        kode: e.alat.code,
        nama: e.alat.name,
        protokol: e.alat.protocol,
        transport: e.alat.transport,
        mode: e.alat.mode,
        status: e.status,
        galat: e.galat,
        koneksi_aktif: e.sesi.size,
        pesan_diterima: e.jumlahPesan,
        data_terakhir: e.terakhirData === null ? null : e.terakhirData.toISOString(),
      })),
    };
  }
}

module.exports = new Gateway();
