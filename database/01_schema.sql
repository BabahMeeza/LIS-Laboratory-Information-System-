-- =====================================================================
--  LIS — Laboratory Information System
--  Skema database inti
--  Target: MySQL 5.7+ / MariaDB 10.4+ (XAMPP)
--  Charset: utf8mb4 (mendukung nama pasien dengan karakter non-ASCII)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `db_lis`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `db_lis`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. PENGGUNA & KEAMANAN
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `nama`          VARCHAR(100) NOT NULL,
  `nip`           VARCHAR(30)  DEFAULT NULL COMMENT 'NIP/NIK pegawai, dikirim ke Khanza sbg petugas',
  `email`         VARCHAR(100) DEFAULT NULL,
  `role`          ENUM('admin','manajer','verifikator','analis','sampling','viewer') NOT NULL DEFAULT 'analis',
  `gelar`         VARCHAR(50)  DEFAULT NULL COMMENT 'Contoh: dr., Sp.PK — dicetak pada lembar hasil',
  `ttd_path`      VARCHAR(255) DEFAULT NULL COMMENT 'Path gambar tanda tangan untuk laporan',
  `aktif`         TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at` DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kredensial untuk pemanggil API (middleware alat, konektor Khanza)
CREATE TABLE IF NOT EXISTS `api_clients` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`        VARCHAR(100) NOT NULL,
  `api_key`     VARCHAR(64)  NOT NULL COMMENT 'Identitas publik, dikirim di header X-API-Key',
  `secret_hash` VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt; untuk memverifikasi secret yang dikirim apa adanya',
  -- Verifikasi HMAC memerlukan secret asli, sehingga hash saja tidak cukup.
  -- Secret disimpan terenkripsi AES-256-GCM memakai security.app_key.
  `secret_enc`  TEXT         DEFAULT NULL COMMENT 'Secret terenkripsi untuk perhitungan HMAC per klien',
  `scopes`      VARCHAR(255) NOT NULL DEFAULT 'instrument' COMMENT 'CSV: instrument,khanza,admin',
  `ip_whitelist` VARCHAR(255) DEFAULT NULL COMMENT 'CSV IP/CIDR; kosong = semua',
  `aktif`       TINYINT(1)   NOT NULL DEFAULT 1,
  `last_used_at` DATETIME    DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_clients_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembatasan percobaan login. Disimpan di database, bukan session,
-- agar tidak dapat diakali dengan menghapus cookie.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(50)  NOT NULL,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `gagal`        INT          NOT NULL DEFAULT 0,
  `locked_until` DATETIME     DEFAULT NULL,
  `terakhir_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_attempts` (`username`,`ip`),
  KEY `idx_la_locked` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `actor`      VARCHAR(100) NOT NULL DEFAULT 'system',
  `aksi`       VARCHAR(60)  NOT NULL,
  `ref_type`   VARCHAR(40)  DEFAULT NULL,
  `ref_id`     VARCHAR(40)  DEFAULT NULL,
  `deskripsi`  VARCHAR(255) DEFAULT NULL,
  `data_lama`  TEXT         DEFAULT NULL,
  `data_baru`  TEXT         DEFAULT NULL,
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_ref` (`ref_type`,`ref_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(60)  NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `grup`       VARCHAR(40)  NOT NULL DEFAULT 'umum',
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. MASTER PASIEN & PERUJUK
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `patients` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `no_rm`         VARCHAR(20)  NOT NULL COMMENT 'Nomor rekam medis internal LIS',
  `khanza_no_rkm_medis` VARCHAR(20) DEFAULT NULL COMMENT 'Nomor RM di SIMRS Khanza',
  `nik`           VARCHAR(20)  DEFAULT NULL,
  `nama`          VARCHAR(100) NOT NULL,
  `tempat_lahir`  VARCHAR(50)  DEFAULT NULL,
  `tgl_lahir`     DATE         DEFAULT NULL,
  `jk`            ENUM('L','P','X') NOT NULL DEFAULT 'X',
  `alamat`        VARCHAR(255) DEFAULT NULL,
  `telepon`       VARCHAR(30)  DEFAULT NULL,
  `email`         VARCHAR(100) DEFAULT NULL,
  `gol_darah`     VARCHAR(5)   DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_patients_no_rm` (`no_rm`),
  KEY `idx_patients_khanza` (`khanza_no_rkm_medis`),
  KEY `idx_patients_nama` (`nama`),
  KEY `idx_patients_nik` (`nik`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doctors` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`      VARCHAR(30)  NOT NULL,
  `nama`      VARCHAR(100) NOT NULL,
  `spesialis` VARCHAR(60)  DEFAULT NULL,
  `is_perujuk_luar` TINYINT(1) NOT NULL DEFAULT 0,
  `aktif`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctors_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. MASTER PEMERIKSAAN
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `test_categories` (
  `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`  VARCHAR(20)  NOT NULL,
  `nama`  VARCHAR(80)  NOT NULL,
  `urut`  INT          NOT NULL DEFAULT 0,
  `aktif` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_test_categories_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `specimen_types` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`      VARCHAR(20)  NOT NULL,
  `nama`      VARCHAR(60)  NOT NULL,
  `container` VARCHAR(60)  DEFAULT NULL COMMENT 'Tabung/wadah, mis. EDTA (tutup ungu)',
  `warna`     VARCHAR(20)  DEFAULT NULL COMMENT 'Warna tutup tabung untuk label',
  `volume_ml` DECIMAL(6,2) DEFAULT NULL,
  `aktif`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_specimen_types_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tests` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`             VARCHAR(30)  NOT NULL COMMENT 'Kode internal LIS, mis. HB',
  `nama`             VARCHAR(120) NOT NULL,
  `nama_singkat`     VARCHAR(60)  DEFAULT NULL COMMENT 'Untuk cetak lembar hasil',
  `category_id`      INT UNSIGNED DEFAULT NULL,
  `specimen_type_id` INT UNSIGNED DEFAULT NULL,
  `loinc`            VARCHAR(20)  DEFAULT NULL,
  `satuan`           VARCHAR(30)  DEFAULT NULL,
  `metode`           VARCHAR(80)  DEFAULT NULL,
  `tipe_hasil`       ENUM('numerik','teks','pilihan','narasi') NOT NULL DEFAULT 'numerik',
  `pilihan`          VARCHAR(255) DEFAULT NULL COMMENT 'CSV opsi untuk tipe_hasil=pilihan, mis. Negatif,Positif',
  `desimal`          TINYINT      NOT NULL DEFAULT 2,
  `harga`            DECIMAL(12,2) NOT NULL DEFAULT 0,
  `tat_menit`        INT          NOT NULL DEFAULT 120 COMMENT 'Turn Around Time target (menit)',
  `urut`             INT          NOT NULL DEFAULT 0,
  `is_kritis`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Pantau nilai kritis',
  -- Pemetaan ke SIMRS Khanza
  `khanza_kd_jenis_prw` VARCHAR(20) DEFAULT NULL COMMENT 'jns_perawatan_lab.kd_jenis_prw',
  `khanza_id_template`  INT         DEFAULT NULL COMMENT 'template_laboratorium.id_template',
  `aktif`            TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tests_kode` (`kode`),
  KEY `idx_tests_category` (`category_id`),
  KEY `idx_tests_khanza` (`khanza_kd_jenis_prw`,`khanza_id_template`),
  CONSTRAINT `fk_tests_category` FOREIGN KEY (`category_id`) REFERENCES `test_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tests_specimen` FOREIGN KEY (`specimen_type_id`) REFERENCES `specimen_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nilai rujukan bertingkat: dipilih berdasarkan jenis kelamin + rentang umur (hari)
CREATE TABLE IF NOT EXISTS `reference_ranges` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id`       INT UNSIGNED NOT NULL,
  `jk`            ENUM('L','P','A') NOT NULL DEFAULT 'A' COMMENT 'A = semua jenis kelamin',
  `umur_min_hari` INT NOT NULL DEFAULT 0,
  `umur_max_hari` INT NOT NULL DEFAULT 43800 COMMENT 'default ±120 tahun',
  `low`           DECIMAL(14,4) DEFAULT NULL,
  `high`          DECIMAL(14,4) DEFAULT NULL,
  `critical_low`  DECIMAL(14,4) DEFAULT NULL,
  `critical_high` DECIMAL(14,4) DEFAULT NULL,
  `teks_rujukan`  VARCHAR(120) DEFAULT NULL COMMENT 'Tampilan pada lembar hasil, mis. "13.2 - 17.3" atau "Negatif"',
  `nilai_normal_teks` VARCHAR(120) DEFAULT NULL COMMENT 'Untuk tipe_hasil teks/pilihan, mis. Negatif',
  `catatan`       VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_refrange_test` (`test_id`,`jk`,`umur_min_hari`,`umur_max_hari`),
  CONSTRAINT `fk_refrange_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paket/panel pemeriksaan (mis. Darah Lengkap, Profil Lipid)
CREATE TABLE IF NOT EXISTS `test_panels` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`        VARCHAR(30)  NOT NULL,
  `nama`        VARCHAR(120) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `harga`       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '0 = jumlahkan harga item',
  `khanza_kd_jenis_prw` VARCHAR(20) DEFAULT NULL,
  `aktif`       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_test_panels_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `test_panel_items` (
  `panel_id` INT UNSIGNED NOT NULL,
  `test_id`  INT UNSIGNED NOT NULL,
  `urut`     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`panel_id`,`test_id`),
  CONSTRAINT `fk_tpi_panel` FOREIGN KEY (`panel_id`) REFERENCES `test_panels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpi_test`  FOREIGN KEY (`test_id`)  REFERENCES `tests` (`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ALUR KERJA: ORDER → SPESIMEN → HASIL
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `orders` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `no_order`        VARCHAR(20)  NOT NULL COMMENT 'Nomor order internal LIS: LIS-YYMMDD-####',
  `no_lab`          VARCHAR(20)  DEFAULT NULL COMMENT 'Nomor laboratorium (urut harian per unit)',
  `patient_id`      INT UNSIGNED NOT NULL,
  -- Referensi SIMRS Khanza
  `khanza_noorder`  VARCHAR(20)  DEFAULT NULL COMMENT 'permintaan_lab.noorder',
  `khanza_no_rawat` VARCHAR(20)  DEFAULT NULL COMMENT 'reg_periksa.no_rawat',
  `asal`            ENUM('ralan','ranap','igd','luar','mcu') NOT NULL DEFAULT 'ralan',
  `kode_ruang`      VARCHAR(20)  DEFAULT NULL,
  `nama_ruang`      VARCHAR(60)  DEFAULT NULL,
  `kode_carabayar`  VARCHAR(20)  DEFAULT NULL,
  `nama_carabayar`  VARCHAR(60)  DEFAULT NULL,
  `dokter_id`       INT UNSIGNED DEFAULT NULL,
  `dokter_perujuk`  VARCHAR(100) DEFAULT NULL,
  `diagnosa_klinis` VARCHAR(150) DEFAULT NULL,
  `informasi_tambahan` VARCHAR(150) DEFAULT NULL,
  `prioritas`       ENUM('rutin','cito') NOT NULL DEFAULT 'rutin',
  `status`          ENUM('draft','ordered','collected','received','in_progress','resulted','verified','released','cancelled')
                    NOT NULL DEFAULT 'ordered',
  `tgl_order`       DATETIME     NOT NULL,
  `tgl_selesai`     DATETIME     DEFAULT NULL COMMENT 'Waktu rilis, dasar perhitungan TAT',
  `total_harga`     DECIMAL(12,2) NOT NULL DEFAULT 0,
  `catatan`         VARCHAR(255) DEFAULT NULL,
  `alasan_batal`    VARCHAR(255) DEFAULT NULL,
  `sumber`          ENUM('manual','khanza','api') NOT NULL DEFAULT 'manual',
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_no_order` (`no_order`),
  UNIQUE KEY `uq_orders_khanza` (`khanza_noorder`),
  KEY `idx_orders_patient` (`patient_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_tgl` (`tgl_order`),
  KEY `idx_orders_no_rawat` (`khanza_no_rawat`),
  CONSTRAINT `fk_orders_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `test_id`    INT UNSIGNED NOT NULL,
  `panel_id`   INT UNSIGNED DEFAULT NULL COMMENT 'Diisi bila item berasal dari paket',
  `specimen_id` INT UNSIGNED DEFAULT NULL,
  `harga`      DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status`     ENUM('pending','collected','received','in_progress','resulted','verified','released','cancelled','rerun')
               NOT NULL DEFAULT 'pending',
  `urut`       INT NOT NULL DEFAULT 0,
  -- Referensi Khanza per item (dibutuhkan saat menulis balik hasil)
  `khanza_kd_jenis_prw` VARCHAR(20) DEFAULT NULL,
  `khanza_id_template`  INT         DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_items` (`order_id`,`test_id`),
  KEY `idx_oi_test` (`test_id`),
  KEY `idx_oi_status` (`status`),
  KEY `idx_oi_specimen` (`specimen_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_test`  FOREIGN KEY (`test_id`)  REFERENCES `tests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `specimens` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`         INT UNSIGNED NOT NULL,
  `barcode`          VARCHAR(30)  NOT NULL COMMENT 'Sample ID — kunci pencocokan hasil dari alat',
  `specimen_type_id` INT UNSIGNED DEFAULT NULL,
  `volume_ml`        DECIMAL(6,2) DEFAULT NULL,
  `status`           ENUM('pending','collected','received','rejected','disposed') NOT NULL DEFAULT 'pending',
  `collected_at`     DATETIME     DEFAULT NULL,
  `collected_by`     INT UNSIGNED DEFAULT NULL,
  `received_at`      DATETIME     DEFAULT NULL,
  `received_by`      INT UNSIGNED DEFAULT NULL,
  `kondisi`          ENUM('baik','lisis','ikterik','lipemik','beku','kurang','tidak_sesuai') NOT NULL DEFAULT 'baik',
  `alasan_tolak`     VARCHAR(255) DEFAULT NULL,
  `lokasi_simpan`    VARCHAR(60)  DEFAULT NULL,
  `catatan`          VARCHAR(255) DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_specimens_barcode` (`barcode`),
  KEY `idx_specimens_order` (`order_id`),
  KEY `idx_specimens_status` (`status`),
  CONSTRAINT `fk_specimens_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_specimens_type`  FOREIGN KEY (`specimen_type_id`) REFERENCES `specimen_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `results` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED NOT NULL,
  `order_item_id`   INT UNSIGNED NOT NULL,
  `test_id`         INT UNSIGNED NOT NULL,
  `specimen_id`     INT UNSIGNED DEFAULT NULL,
  `nilai`           VARCHAR(255) DEFAULT NULL COMMENT 'Nilai apa adanya (teks)',
  `nilai_num`       DECIMAL(18,6) DEFAULT NULL COMMENT 'Nilai numerik hasil parsing, untuk flag & delta check',
  `satuan`          VARCHAR(30)  DEFAULT NULL,
  `flag`            ENUM('N','L','H','LL','HH','A','') NOT NULL DEFAULT '' COMMENT 'Dihitung LIS: N normal, L/H di luar rujukan, LL/HH kritis, A abnormal teks',
  `flag_alat`       VARCHAR(10)  DEFAULT NULL COMMENT 'Flag mentah dari analyzer',
  `ref_low`         DECIMAL(14,4) DEFAULT NULL,
  `ref_high`        DECIMAL(14,4) DEFAULT NULL,
  `ref_teks`        VARCHAR(120) DEFAULT NULL,
  `metode`          VARCHAR(80)  DEFAULT NULL,
  `instrument_id`   INT UNSIGNED DEFAULT NULL,
  `message_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'instrument_messages.id sumber hasil',
  `is_manual`       TINYINT(1)   NOT NULL DEFAULT 0,
  `status`          ENUM('pending','preliminary','final','verified','corrected','rejected') NOT NULL DEFAULT 'pending',
  `delta_check`     ENUM('ok','flagged','tidak_ada_data') NOT NULL DEFAULT 'tidak_ada_data',
  `delta_persen`    DECIMAL(8,2) DEFAULT NULL,
  `is_kritis`       TINYINT(1)   NOT NULL DEFAULT 0,
  `kritis_dilapor_ke` VARCHAR(100) DEFAULT NULL,
  `kritis_dilapor_at` DATETIME   DEFAULT NULL,
  `kritis_dilapor_by` INT UNSIGNED DEFAULT NULL,
  `catatan`         VARCHAR(255) DEFAULT NULL,
  `entered_by`      INT UNSIGNED DEFAULT NULL,
  `entered_at`      DATETIME     DEFAULT NULL,
  `verified_by`     INT UNSIGNED DEFAULT NULL,
  `verified_at`     DATETIME     DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_results_item` (`order_item_id`),
  KEY `idx_results_order` (`order_id`),
  KEY `idx_results_test` (`test_id`),
  KEY `idx_results_status` (`status`),
  KEY `idx_results_instrument` (`instrument_id`),
  CONSTRAINT `fk_results_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_results_item`  FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_results_test`  FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat perubahan hasil — wajib untuk telusur akreditasi (SNARS/ISO 15189)
CREATE TABLE IF NOT EXISTS `result_history` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `result_id`  BIGINT UNSIGNED NOT NULL,
  `nilai_lama` VARCHAR(255) DEFAULT NULL,
  `nilai_baru` VARCHAR(255) DEFAULT NULL,
  `status_lama` VARCHAR(20) DEFAULT NULL,
  `status_baru` VARCHAR(20) DEFAULT NULL,
  `alasan`     VARCHAR(255) DEFAULT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `sumber`     VARCHAR(30)  NOT NULL DEFAULT 'manual',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rh_result` (`result_id`),
  CONSTRAINT `fk_rh_result` FOREIGN KEY (`result_id`) REFERENCES `results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. ALAT LABORATORIUM (INSTRUMENT INTERFACE)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `instruments` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`          VARCHAR(30)  NOT NULL COMMENT 'Identitas alat yang dikirim middleware',
  `nama`          VARCHAR(100) NOT NULL,
  `merk`          VARCHAR(60)  DEFAULT NULL,
  `model`         VARCHAR(60)  DEFAULT NULL,
  `serial_number` VARCHAR(60)  DEFAULT NULL,
  `category_id`   INT UNSIGNED DEFAULT NULL COMMENT 'Kategori pemeriksaan utama alat',
  `protokol`      ENUM('astm','hl7','raw') NOT NULL DEFAULT 'astm',
  `transport`     ENUM('tcp_server','tcp_client','serial') NOT NULL DEFAULT 'tcp_server',
  `mode`          ENUM('unidirectional','bidirectional') NOT NULL DEFAULT 'unidirectional',
  -- Parameter TCP
  `host`          VARCHAR(60)  DEFAULT NULL COMMENT 'tcp_client: IP alat. tcp_server: bind address',
  `port`          INT          DEFAULT NULL,
  -- Parameter Serial RS232
  `serial_port`   VARCHAR(60)  DEFAULT NULL COMMENT 'mis. COM3 atau /dev/tty.usbserial-1410',
  `baud_rate`     INT          DEFAULT 9600,
  `data_bits`     TINYINT      DEFAULT 8,
  `stop_bits`     TINYINT      DEFAULT 1,
  `parity`        ENUM('none','even','odd','mark','space') DEFAULT 'none',
  `flow_control`  ENUM('none','rtscts','xonxoff') DEFAULT 'none',
  -- Perilaku
  `encoding`      VARCHAR(20)  NOT NULL DEFAULT 'latin1',
  `field_delim`   VARCHAR(4)   NOT NULL DEFAULT '|',
  `auto_verify`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Autovalidasi hasil normal tanpa flag',
  `auto_verify_max_flag` ENUM('N','L','H') NOT NULL DEFAULT 'N',
  `simpan_raw`    TINYINT(1)   NOT NULL DEFAULT 1,
  `aktif`         TINYINT(1)   NOT NULL DEFAULT 1,
  -- Status runtime (di-update middleware)
  `status_koneksi` ENUM('offline','online','error') NOT NULL DEFAULT 'offline',
  `last_seen_at`  DATETIME     DEFAULT NULL,
  `last_error`    VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instruments_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pemetaan kode parameter alat → pemeriksaan LIS
CREATE TABLE IF NOT EXISTS `instrument_test_map` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` INT UNSIGNED NOT NULL,
  `kode_alat`     VARCHAR(60)  NOT NULL COMMENT 'Kode parameter yang dikirim alat, mis. WBC / 2345',
  `test_id`       INT UNSIGNED DEFAULT NULL COMMENT 'NULL = sengaja diabaikan',
  `faktor`        DECIMAL(12,6) NOT NULL DEFAULT 1 COMMENT 'Pengali konversi satuan',
  `offset_nilai`  DECIMAL(12,6) NOT NULL DEFAULT 0,
  `satuan_alat`   VARCHAR(30)  DEFAULT NULL,
  `abaikan`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_itm` (`instrument_id`,`kode_alat`),
  KEY `idx_itm_test` (`test_id`),
  CONSTRAINT `fk_itm_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_itm_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log mentah komunikasi alat — bukti telusur & alat bantu debugging
CREATE TABLE IF NOT EXISTS `instrument_messages` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` INT UNSIGNED DEFAULT NULL,
  `kode_alat`     VARCHAR(30)  DEFAULT NULL COMMENT 'Diisi bila alat belum terdaftar',
  `arah`          ENUM('in','out') NOT NULL DEFAULT 'in',
  `protokol`      VARCHAR(10)  DEFAULT NULL,
  `sample_id`     VARCHAR(60)  DEFAULT NULL,
  `raw`           MEDIUMTEXT   DEFAULT NULL,
  `parsed`        MEDIUMTEXT   DEFAULT NULL COMMENT 'JSON hasil normalisasi',
  `status`        ENUM('diterima','diproses','tidak_cocok','sebagian','error','diabaikan') NOT NULL DEFAULT 'diterima',
  `jml_hasil`     INT NOT NULL DEFAULT 0,
  `jml_tersimpan` INT NOT NULL DEFAULT 0,
  `pesan_error`   VARCHAR(500) DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_im_instrument` (`instrument_id`),
  KEY `idx_im_sample` (`sample_id`),
  KEY `idx_im_status` (`status`),
  KEY `idx_im_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hasil dari alat yang tidak menemukan order — menunggu pencocokan manual
CREATE TABLE IF NOT EXISTS `orphan_results` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`    BIGINT UNSIGNED DEFAULT NULL,
  `instrument_id` INT UNSIGNED DEFAULT NULL,
  `sample_id`     VARCHAR(60)  DEFAULT NULL,
  `payload`       MEDIUMTEXT   DEFAULT NULL COMMENT 'JSON satu sampel beserta hasilnya',
  `alasan`        VARCHAR(120) DEFAULT NULL COMMENT 'sample_tidak_ditemukan / parameter_belum_dipetakan',
  `status`        ENUM('menunggu','terpasang','dibuang') NOT NULL DEFAULT 'menunggu',
  `order_id`      INT UNSIGNED DEFAULT NULL,
  `resolved_by`   INT UNSIGNED DEFAULT NULL,
  `resolved_at`   DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orphan_status` (`status`),
  KEY `idx_orphan_sample` (`sample_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Antrian perintah ke alat (bidirectional / host query)
CREATE TABLE IF NOT EXISTS `instrument_worklist` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` INT UNSIGNED NOT NULL,
  `specimen_id`   INT UNSIGNED DEFAULT NULL,
  `sample_id`     VARCHAR(60)  NOT NULL,
  `payload`       TEXT         DEFAULT NULL COMMENT 'JSON daftar kode tes untuk alat',
  `status`        ENUM('menunggu','terkirim','selesai','gagal') NOT NULL DEFAULT 'menunggu',
  `dikirim_at`    DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_iw_instrument_status` (`instrument_id`,`status`),
  KEY `idx_iw_sample` (`sample_id`),
  CONSTRAINT `fk_iw_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. KONTROL MUTU (QC)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `qc_lots` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` INT UNSIGNED DEFAULT NULL,
  `test_id`       INT UNSIGNED NOT NULL,
  `nama_bahan`    VARCHAR(100) NOT NULL,
  `lot`           VARCHAR(50)  NOT NULL,
  `level`         ENUM('1','2','3') NOT NULL DEFAULT '1',
  `mean`          DECIMAL(18,6) NOT NULL,
  `sd`            DECIMAL(18,6) NOT NULL,
  `cv_target`     DECIMAL(8,4) DEFAULT NULL,
  `tgl_mulai`     DATE DEFAULT NULL,
  `tgl_kadaluarsa` DATE DEFAULT NULL,
  `aktif`         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_qclot_test` (`test_id`,`instrument_id`),
  CONSTRAINT `fk_qclot_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qc_results` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `qc_lot_id`  INT UNSIGNED NOT NULL,
  `nilai`      DECIMAL(18,6) NOT NULL,
  `z_score`    DECIMAL(10,4) DEFAULT NULL,
  `westgard`   VARCHAR(40)  DEFAULT NULL COMMENT 'Aturan yang dilanggar, mis. 1-3s, 2-2s, R-4s',
  `status`     ENUM('in','warning','out') NOT NULL DEFAULT 'in',
  `tgl_uji`    DATETIME NOT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `catatan`    VARCHAR(255) DEFAULT NULL,
  `tindakan`   VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qcres_lot` (`qc_lot_id`,`tgl_uji`),
  CONSTRAINT `fk_qcres_lot` FOREIGN KEY (`qc_lot_id`) REFERENCES `qc_lots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. INTEGRASI SIMRS KHANZA
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `khanza_sync_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `arah`       ENUM('masuk','keluar') NOT NULL,
  `jenis`      VARCHAR(40)  NOT NULL COMMENT 'order / hasil / template / ping',
  `ref_type`   VARCHAR(30)  DEFAULT NULL,
  `ref_id`     VARCHAR(40)  DEFAULT NULL,
  `endpoint`   VARCHAR(255) DEFAULT NULL,
  `payload`    MEDIUMTEXT   DEFAULT NULL,
  `response`   MEDIUMTEXT   DEFAULT NULL,
  `http_code`  INT          DEFAULT NULL,
  `status`     ENUM('sukses','gagal','antri') NOT NULL DEFAULT 'antri',
  `percobaan`  INT          NOT NULL DEFAULT 0,
  `next_retry_at` DATETIME  DEFAULT NULL,
  `pesan`      VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ksl_status` (`status`,`next_retry_at`),
  KEY `idx_ksl_ref` (`ref_type`,`ref_id`),
  KEY `idx_ksl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache master pemeriksaan Khanza untuk keperluan pemetaan
CREATE TABLE IF NOT EXISTS `khanza_templates` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kd_jenis_prw`  VARCHAR(20)  NOT NULL,
  `nm_perawatan`  VARCHAR(150) DEFAULT NULL,
  `id_template`   INT          NOT NULL,
  `pemeriksaan`   VARCHAR(150) DEFAULT NULL,
  `satuan`        VARCHAR(40)  DEFAULT NULL,
  `nilai_rujukan_ld` VARCHAR(60) DEFAULT NULL COMMENT 'Laki-laki dewasa',
  `nilai_rujukan_la` VARCHAR(60) DEFAULT NULL COMMENT 'Laki-laki anak',
  `nilai_rujukan_pd` VARCHAR(60) DEFAULT NULL COMMENT 'Perempuan dewasa',
  `nilai_rujukan_pa` VARCHAR(60) DEFAULT NULL COMMENT 'Perempuan anak',
  `test_id`       INT UNSIGNED DEFAULT NULL COMMENT 'Hasil pemetaan ke tests.id',
  `synced_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kt` (`kd_jenis_prw`,`id_template`),
  KEY `idx_kt_test` (`test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. PENOMORAN
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `counters` (
  `nama`       VARCHAR(40) NOT NULL COMMENT 'mis. order:20260831',
  `nilai`      INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 9. VIEW BANTU
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW `v_worklist` AS
SELECT
  oi.id            AS order_item_id,
  o.id             AS order_id,
  o.no_order,
  o.no_lab,
  o.prioritas,
  o.asal,
  o.tgl_order,
  o.status         AS status_order,
  oi.status        AS status_item,
  p.id             AS patient_id,
  p.no_rm,
  p.nama           AS nama_pasien,
  p.jk,
  p.tgl_lahir,
  t.id             AS test_id,
  t.kode           AS kode_test,
  t.nama           AS nama_test,
  t.satuan,
  t.tipe_hasil,
  tc.nama          AS kategori,
  s.id             AS specimen_id,
  s.barcode,
  s.status         AS status_spesimen,
  r.id             AS result_id,
  r.nilai,
  r.flag,
  r.status         AS status_hasil
FROM order_items oi
JOIN orders   o  ON o.id = oi.order_id
JOIN patients p  ON p.id = o.patient_id
JOIN tests    t  ON t.id = oi.test_id
LEFT JOIN test_categories tc ON tc.id = t.category_id
LEFT JOIN specimens s ON s.id = oi.specimen_id
LEFT JOIN results   r ON r.order_item_id = oi.id
WHERE o.status <> 'cancelled' AND oi.status <> 'cancelled';
