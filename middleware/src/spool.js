'use strict';

/**
 * Antrian tahan-mati-listrik untuk hasil yang belum berhasil dikirim ke LIS.
 *
 * Ini bagian terpenting dari keandalan middleware: hasil pemeriksaan yang
 * sudah dikeluarkan alat tidak boleh hilang hanya karena LIS sedang mati,
 * jaringan putus, atau XAMPP di-restart. Setiap payload ditulis lebih dulu
 * sebagai berkas JSON di folder spool, baru dicoba dikirim. Berkas dihapus
 * hanya setelah LIS mengonfirmasi penerimaan.
 */

const fs = require('fs');
const path = require('path');
const { config } = require('./config');
const logger = require('./logger');
const lis = require('./lisClient');

const MAKS_PERCOBAAN = 60;

class Spool {
  constructor() {
    this.dir = config.spoolDir;
    this.gagalDir = path.join(this.dir, 'gagal');
    this.sedangKirim = false;

    for (const d of [this.dir, this.gagalDir]) {
      if (!fs.existsSync(d)) {
        fs.mkdirSync(d, { recursive: true });
      }
    }
  }

  /** Simpan payload ke disk sebelum mencoba mengirim. */
  simpan(payload) {
    const nama = `${Date.now()}-${process.pid}-${Math.random().toString(36).slice(2, 8)}.json`;
    const berkas = path.join(this.dir, nama);

    const isi = {
      dibuat: new Date().toISOString(),
      percobaan: 0,
      payload,
    };

    fs.writeFileSync(berkas, JSON.stringify(isi), 'utf8');
    logger.debug(`Payload disimpan ke spool: ${nama}`);

    return berkas;
  }

  /** Daftar berkas antrian, terlama lebih dulu. */
  daftar() {
    try {
      return fs
        .readdirSync(this.dir)
        .filter((f) => f.endsWith('.json'))
        .sort();
    } catch {
      return [];
    }
  }

  jumlah() {
    return this.daftar().length;
  }

  jumlahGagal() {
    try {
      return fs.readdirSync(this.gagalDir).filter((f) => f.endsWith('.json')).length;
    } catch {
      return 0;
    }
  }

  /**
   * Coba kirim seluruh antrian. Aman dipanggil berulang; pengiriman
   * bersamaan dicegah dengan penanda sederhana.
   */
  async proses() {
    if (this.sedangKirim) {
      return { terkirim: 0, tertunda: this.jumlah() };
    }

    this.sedangKirim = true;
    let terkirim = 0;

    try {
      for (const nama of this.daftar()) {
        const berkas = path.join(this.dir, nama);

        let isi;
        try {
          isi = JSON.parse(fs.readFileSync(berkas, 'utf8'));
        } catch (e) {
          logger.error(`Berkas spool rusak, dipindahkan: ${nama} (${e.message})`);
          this._pindahkanKeGagal(berkas, nama);
          continue;
        }

        try {
          const hasil = await lis.kirimHasil(isi.payload);
          fs.unlinkSync(berkas);
          terkirim += 1;

          const d = hasil?.data ?? {};
          const tersimpan = d.tersimpan ?? 0;
          const total = d.total_hasil ?? 0;
          const ringkas = `${d.tersimpan ?? '?'} dari ${d.total_hasil ?? '?'} nilai`;

          if (total > 0 && tersimpan < total) {
            // "0 dari 15" pada tingkat INFO terbaca seperti keberhasilan.
            // Pengiriman memang berhasil, tetapi LIS tidak menyimpan
            // nilainya — itu perlu terlihat sebagai peringatan, lengkap
            // dengan sebab yang dilaporkan LIS.
            // Bentuk respons LIS: rincian[] → detail[] → pesan[].
            const sebab = [];
            for (const r of d.rincian ?? []) {
              if (r.error) sebab.push(r.error);
              for (const det of r.detail ?? []) {
                for (const p of det.pesan ?? []) sebab.push(p);
              }
            }

            logger.warn(
              `LIS menerima kiriman tetapi hanya menyimpan ${ringkas}`,
              { berkas: nama, sebab: sebab.slice(0, 5) }
            );
            logger.warn(
              '  Nilai yang tidak tersimpan ada di LIS → Alat Laboratorium → '
                + 'Hasil Belum Terpetakan. Tidak ada yang dibuang.'
            );
          } else {
            logger.info(`Hasil terkirim ke LIS: ${ringkas}`, { berkas: nama });
          }
        } catch (e) {
          isi.percobaan = (isi.percobaan || 0) + 1;
          isi.galatTerakhir = e.message;

          if (e.permanen === true) {
            // LIS menolak payload ini secara permanen (mis. alat tidak
            // terdaftar). Mengulang tidak akan menolong; pindahkan agar
            // antrian tidak macet, tetapi jangan dibuang.
            logger.error(`LIS menolak payload secara permanen: ${e.message}`, { berkas: nama });
            fs.writeFileSync(berkas, JSON.stringify(isi), 'utf8');
            this._pindahkanKeGagal(berkas, nama);
            continue;
          }

          if (isi.percobaan >= MAKS_PERCOBAAN) {
            logger.error(
              `Payload menyerah setelah ${MAKS_PERCOBAAN} percobaan: ${e.message}`,
              { berkas: nama }
            );
            fs.writeFileSync(berkas, JSON.stringify(isi), 'utf8');
            this._pindahkanKeGagal(berkas, nama);
            continue;
          }

          fs.writeFileSync(berkas, JSON.stringify(isi), 'utf8');
          logger.warn(
            `Pengiriman ke LIS gagal (percobaan ${isi.percobaan}), tetap di antrian: ${e.message}`
          );

          // Kegagalan jaringan biasanya berlaku untuk semua berkas —
          // hentikan putaran agar tidak membanjiri log.
          break;
        }
      }
    } finally {
      this.sedangKirim = false;
    }

    return { terkirim, tertunda: this.jumlah() };
  }

  _pindahkanKeGagal(berkas, nama) {
    try {
      fs.renameSync(berkas, path.join(this.gagalDir, nama));
    } catch (e) {
      logger.error(`Gagal memindahkan berkas spool: ${e.message}`);
    }
  }
}

module.exports = new Spool();
