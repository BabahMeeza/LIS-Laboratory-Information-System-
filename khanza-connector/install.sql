-- =====================================================================
--  Konektor SIMRS Khanza — tabel bridging
--
--  Jalankan pada server SIMRS Khanza.
--
--  Struktur di bawah mengikuti database bridging resmi Khanza
--  (sik_bridging_lab). Bila database tersebut sudah ada di instalasi
--  Anda, skrip ini aman dijalankan: seluruh pernyataan memakai
--  CREATE TABLE IF NOT EXISTS sehingga tabel yang sudah ada tidak
--  disentuh dan datanya tidak hilang.
--
--  Mesin penyimpanan dan charset sengaja disamakan dengan bawaan Khanza
--  (MyISAM / latin1) agar konsisten dengan tabel bridging lain.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `sik_bridging_lab`
  DEFAULT CHARACTER SET latin1;

USE `sik_bridging_lab`;

-- ---------------------------------------------------------------------
-- Permintaan pemeriksaan (Khanza → LIS)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permintaan_lab` (
  `noorder`             varchar(15)  NOT NULL,
  `no_rawat`            varchar(17)  NOT NULL,
  `no_rkm_medis`        varchar(15)  NOT NULL,
  `nm_pasien`           varchar(40)  NOT NULL,
  `email`               varchar(50)  NOT NULL DEFAULT '',
  `jk`                  enum('L','P') DEFAULT NULL,
  `tmp_lahir`           varchar(15)  DEFAULT NULL,
  `tgl_lahir`           date         DEFAULT NULL,
  `alamat`              varchar(200) DEFAULT NULL,
  `tgl_permintaan`      date         NOT NULL,
  `jam_permintaan`      time         NOT NULL,
  `kode_dokter_perujuk` varchar(20)  NOT NULL DEFAULT '',
  `dokter_perujuk`      varchar(50)  NOT NULL DEFAULT '',
  `status`              enum('ralan','ranap') NOT NULL DEFAULT 'ralan',
  `kode_ruang`          varchar(20)  NOT NULL DEFAULT '',
  `nama_ruang`          varchar(50)  NOT NULL DEFAULT '',
  `kode_carabayar`      varchar(20)  NOT NULL DEFAULT '',
  `nama_carabayar`      varchar(50)  NOT NULL DEFAULT '',
  `informasi_tambahan`  varchar(60)  NOT NULL DEFAULT '',
  `diagnosa_klinis`     varchar(80)  NOT NULL DEFAULT '',
  `status_ambil`        enum('0','1') NOT NULL DEFAULT '0',
  PRIMARY KEY (`noorder`),
  KEY `idx_status_ambil` (`status_ambil`),
  KEY `idx_no_rawat` (`no_rawat`),
  KEY `idx_tgl` (`tgl_permintaan`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- ---------------------------------------------------------------------
-- Detail pemeriksaan yang diminta
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `detail_permintaan_lab` (
  `noorder`      varchar(15) NOT NULL,
  `kd_jenis_prw` varchar(15) NOT NULL,
  `id_template`  int(11)     NOT NULL,
  KEY `idx_noorder` (`noorder`),
  KEY `idx_prw` (`kd_jenis_prw`,`id_template`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- ---------------------------------------------------------------------
-- Hasil pemeriksaan (LIS → Khanza)
--
-- Aplikasi desktop Khanza membaca tabel ini pada menu bridging
-- laboratorium, lalu memindahkannya ke periksa_lab / detail_periksa_lab.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `detail_hasil_lab` (
  `noorder`       varchar(15) NOT NULL,
  `kd_jenis_prw`  varchar(15) NOT NULL,
  `id_template`   int(11)     NOT NULL,
  `nilai`         varchar(60) NOT NULL DEFAULT '',
  `nilai_rujukan` varchar(30) NOT NULL DEFAULT '',
  `keterangan`    varchar(60) NOT NULL DEFAULT '',
  KEY `idx_noorder` (`noorder`),
  KEY `idx_prw` (`kd_jenis_prw`,`id_template`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- ---------------------------------------------------------------------
-- Pengguna database khusus konektor (opsional tapi dianjurkan)
--
-- Jangan memakai root untuk konektor. Buat pengguna terbatas yang hanya
-- boleh menyentuh database bridging, ditambah hak BACA pada tabel master
-- pemeriksaan di database utama.
--
-- Hapus tanda komentar dan ganti kata sandinya sebelum dijalankan.
-- ---------------------------------------------------------------------

-- CREATE USER 'lis_konektor'@'localhost' IDENTIFIED BY 'GANTI_KATA_SANDI_INI';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `sik_bridging_lab`.* TO 'lis_konektor'@'localhost';
-- GRANT SELECT ON `sik`.`template_laboratorium` TO 'lis_konektor'@'localhost';
-- GRANT SELECT ON `sik`.`jns_perawatan_lab`     TO 'lis_konektor'@'localhost';
-- FLUSH PRIVILEGES;
