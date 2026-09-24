-- =====================================================================
--  LIS — Migrasi 04: Mutu, Ketertelusuran, dan Keselamatan Pasien
-- =====================================================================
--  Menutup celah yang ditemukan saat membandingkan rancangan ini dengan
--  LIS yang sudah berjalan di dunia kesehatan. Rujukan tiap bagian
--  dicantumkan agar keputusan rancangan dapat ditelusuri kembali:
--
--    ISO 15189:2022  — 7.3 pra-analitik, 7.4 analitik, 7.6 mutu,
--                      7.8 pelaporan
--    CLSI GP47       — pelaporan nilai kritis (read-back wajib)
--    CLSI AUTO10/15  — autovalidasi: aturan harus terdokumentasi,
--                      berversi, dan disetujui sebelum dipakai
--    IHE PaLM LTW    — hasil membawa identitas alat pemeriksa
--    OpenELIS/SENAITE— run analitik sebagai entitas, QC menggendong
--                      rilis, penarikan lot reagen
--    SATUSEHAT       — LOINC + UCUM wajib untuk pertukaran data
--
--  Migrasi ini ADITIF. Tidak ada kolom atau tabel lama yang dihapus,
--  sehingga aman dijalankan pada basis data yang sudah berisi data.
--
--    mysql -u root db_lis < database/04_standar_mutu.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. LOT REAGEN, KALIBRATOR, DAN BAHAN KONTROL
--
--    Tanpa tabel ini, pertanyaan "pasien mana saja yang hasilnya keluar
--    memakai lot reagen yang baru ditarik pabrikan?" tidak dapat
--    dijawab. ISO 15189:2022 7.4.2 mensyaratkan catatan tiap reagen dan
--    bahan habis pakai yang memengaruhi mutu pemeriksaan.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reagent_lots` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id`  INT UNSIGNED DEFAULT NULL COMMENT 'NULL = dipakai lintas alat',
  `jenis`          ENUM('reagen','kalibrator','kontrol','bahan_habis') NOT NULL DEFAULT 'reagen',
  `nama`           VARCHAR(120) NOT NULL,
  `pabrikan`       VARCHAR(80)  DEFAULT NULL,
  `nomor_katalog`  VARCHAR(60)  DEFAULT NULL,
  `lot`            VARCHAR(60)  NOT NULL,
  `tgl_terima`     DATE DEFAULT NULL,
  `tgl_buka`       DATE DEFAULT NULL COMMENT 'Mulai berlakunya masa pakai setelah dibuka',
  `masa_buka_hari` INT  DEFAULT NULL COMMENT 'Open-vial stability; NULL = ikut tgl_kadaluarsa saja',
  `tgl_kadaluarsa` DATE DEFAULT NULL,
  `status`         ENUM('aktif','habis','kadaluarsa','ditarik') NOT NULL DEFAULT 'aktif',
  -- Penarikan (recall) — ISO 15189 7.4.2; dipakai untuk menelusuri
  -- hasil pasien yang terlanjur keluar memakai lot ini.
  `ditarik_at`     DATETIME DEFAULT NULL,
  `ditarik_by`     INT UNSIGNED DEFAULT NULL,
  `alasan_tarik`   VARCHAR(255) DEFAULT NULL,
  `catatan`        VARCHAR(255) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reagent_lot` (`instrument_id`,`nama`,`lot`),
  KEY `idx_reagent_status` (`status`,`tgl_kadaluarsa`),
  CONSTRAINT `fk_reagent_instrument` FOREIGN KEY (`instrument_id`)
    REFERENCES `instruments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. RUN ANALITIK
--
--    Entitas yang selama ini hilang. Satu run mengikat pada satu titik
--    waktu: alat, operator, lot reagen yang terpasang, dan hasil QC yang
--    berlaku. Inilah yang membuat "QC menggendong rilis" dan penelusuran
--    lot menjadi mungkin — pola yang dipakai OpenELIS Global maupun
--    SENAITE, dan yang diandaikan ISO 15189:2022 7.3.7.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `analytical_runs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`          VARCHAR(30) NOT NULL COMMENT 'RUN-YYMMDD-ALAT-##',
  `instrument_id` INT UNSIGNED NOT NULL,
  `mulai_at`      DATETIME NOT NULL,
  `selesai_at`    DATETIME DEFAULT NULL,
  `operator_id`   INT UNSIGNED DEFAULT NULL,
  `status`        ENUM('terbuka','selesai','ditolak') NOT NULL DEFAULT 'terbuka',
  -- Ringkasan QC yang berlaku untuk run ini. Diisi QcGateService, bukan
  -- diketik manusia; 'belum' berarti QC hari itu belum dijalankan.
  `qc_status`     ENUM('belum','lolos','peringatan','gagal') NOT NULL DEFAULT 'belum',
  `qc_dinilai_at` DATETIME DEFAULT NULL,
  `qc_catatan`    VARCHAR(255) DEFAULT NULL,
  `jml_hasil`     INT NOT NULL DEFAULT 0,
  `catatan`       VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_run_kode` (`kode`),
  KEY `idx_run_instrument` (`instrument_id`,`mulai_at`),
  KEY `idx_run_qc` (`qc_status`),
  CONSTRAINT `fk_run_instrument` FOREIGN KEY (`instrument_id`)
    REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lot reagen yang terpasang saat run berjalan (banyak-ke-banyak).
CREATE TABLE IF NOT EXISTS `analytical_run_lots` (
  `run_id`         BIGINT UNSIGNED NOT NULL,
  `reagent_lot_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`run_id`,`reagent_lot_id`),
  KEY `idx_arl_lot` (`reagent_lot_id`),
  CONSTRAINT `fk_arl_run` FOREIGN KEY (`run_id`)
    REFERENCES `analytical_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arl_lot` FOREIGN KEY (`reagent_lot_id`)
    REFERENCES `reagent_lots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. PELAPORAN NILAI KRITIS — CLSI GP47
--
--    Kolom kritis_dilapor_* pada tabel results hanya mencatat bahwa
--    pelaporan terjadi. GP47 menuntut lebih: batas waktu yang terukur,
--    antrean yang belum terlapor, dan pembacaan ulang (read-back) oleh
--    penerima. Tabel ini menyimpan proses itu, bukan sekadar hasilnya.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `critical_notifications` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `result_id`      BIGINT UNSIGNED NOT NULL,
  `order_id`       INT UNSIGNED NOT NULL,
  `patient_id`     INT UNSIGNED NOT NULL,
  `test_id`        INT UNSIGNED NOT NULL,
  `nilai`          VARCHAR(255) DEFAULT NULL,
  `satuan`         VARCHAR(30)  DEFAULT NULL,
  `flag`           VARCHAR(10)  DEFAULT NULL,
  `terdeteksi_at`  DATETIME NOT NULL,
  `batas_menit`    INT NOT NULL DEFAULT 30 COMMENT 'Target waktu lapor sejak terdeteksi',
  `jatuh_tempo_at` DATETIME NOT NULL,
  `status`         ENUM('menunggu','terlapor','terlambat','dibatalkan') NOT NULL DEFAULT 'menunggu',
  -- Pelaporan
  `dilapor_at`     DATETIME DEFAULT NULL,
  `dilapor_by`     INT UNSIGNED DEFAULT NULL,
  `cara`           ENUM('telepon','langsung','wa','sistem','lainnya') DEFAULT NULL,
  `penerima_nama`  VARCHAR(100) DEFAULT NULL,
  `penerima_peran` VARCHAR(60)  DEFAULT NULL COMMENT 'mis. DPJP, perawat jaga',
  -- Read-back: penerima MENGULANG nilai yang didengarnya. Bila tidak
  -- sama dengan nilai hasil, pelaporan belum sah menurut GP47.
  `bacaan_ulang`   VARCHAR(60) DEFAULT NULL,
  `bacaan_cocok`   TINYINT(1)  DEFAULT NULL,
  `terlambat_menit` INT DEFAULT NULL,
  `catatan`        VARCHAR(255) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_critnotif_result` (`result_id`),
  KEY `idx_critnotif_status` (`status`,`jatuh_tempo_at`),
  KEY `idx_critnotif_patient` (`patient_id`),
  CONSTRAINT `fk_critnotif_result` FOREIGN KEY (`result_id`)
    REFERENCES `results` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_critnotif_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ATURAN AUTOVALIDASI SEBAGAI DATA BERVERSI — CLSI AUTO10 / AUTO15
--
--    Sebelumnya aturan autovalidasi tertanam dalam kode PHP. Standar
--    menuntut aturan yang terdokumentasi, berversi, disetujui sebelum
--    berlaku, dan dapat ditunjukkan kepada asesor apa adanya pada
--    tanggal tertentu. Karena itu aturan dipindahkan menjadi data.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `verification_rule_sets` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`         VARCHAR(100) NOT NULL,
  `versi`        VARCHAR(20)  NOT NULL DEFAULT '1.0',
  `status`       ENUM('draft','aktif','arsip') NOT NULL DEFAULT 'draft',
  `berlaku_dari` DATETIME DEFAULT NULL,
  `berlaku_sampai` DATETIME DEFAULT NULL,
  `disetujui_by` INT UNSIGNED DEFAULT NULL,
  `disetujui_at` DATETIME DEFAULT NULL,
  `keterangan`   VARCHAR(255) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vrs` (`nama`,`versi`),
  KEY `idx_vrs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `verification_rules` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_set_id`    INT UNSIGNED NOT NULL,
  `urut`           INT NOT NULL DEFAULT 0 COMMENT 'Aturan pertama yang cocok menentukan hasil',
  `nama`           VARCHAR(120) NOT NULL,
  -- Cakupan: NULL berarti berlaku untuk semua
  `test_id`        INT UNSIGNED DEFAULT NULL,
  `instrument_id`  INT UNSIGNED DEFAULT NULL,
  `jk`             ENUM('L','P','A') NOT NULL DEFAULT 'A',
  `umur_min_hari`  INT DEFAULT NULL,
  `umur_max_hari`  INT DEFAULT NULL,
  -- Syarat (semua yang terisi harus terpenuhi)
  `flag_maks`      ENUM('N','L','H','LL','HH') DEFAULT NULL COMMENT 'Flag paling berat yang masih boleh lolos',
  `nilai_min`      DECIMAL(18,6) DEFAULT NULL COMMENT 'Rentang nilai yang boleh diautovalidasi',
  `nilai_maks`     DECIMAL(18,6) DEFAULT NULL,
  `delta_maks_persen` DECIMAL(8,2) DEFAULT NULL,
  `tolak_jika_kritis`   TINYINT(1) NOT NULL DEFAULT 1,
  `tolak_jika_qc_gagal` TINYINT(1) NOT NULL DEFAULT 1,
  `tolak_jika_qc_belum` TINYINT(1) NOT NULL DEFAULT 1,
  `tolak_jika_satuan_bentrok` TINYINT(1) NOT NULL DEFAULT 1,
  `tolak_jika_lot_kadaluarsa` TINYINT(1) NOT NULL DEFAULT 1,
  `tolak_jika_flag_alat` VARCHAR(120) DEFAULT NULL COMMENT 'CSV flag alat yang menahan, mis. R,*,+++',
  -- Tindakan
  `aksi`           ENUM('validasi','tahan') NOT NULL DEFAULT 'validasi',
  `alasan_tahan`   VARCHAR(160) DEFAULT NULL,
  `aktif`          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_vr_set` (`rule_set_id`,`urut`),
  KEY `idx_vr_test` (`test_id`),
  CONSTRAINT `fk_vr_set` FOREIGN KEY (`rule_set_id`)
    REFERENCES `verification_rule_sets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vr_test` FOREIGN KEY (`test_id`)
    REFERENCES `tests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vr_instrument` FOREIGN KEY (`instrument_id`)
    REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak keputusan autovalidasi — asesor menanyakan "mengapa hasil ini
-- lolos tanpa mata manusia?", dan jawabannya harus tersimpan.
CREATE TABLE IF NOT EXISTS `verification_decisions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `result_id`  BIGINT UNSIGNED NOT NULL,
  `rule_set_id` INT UNSIGNED DEFAULT NULL,
  `rule_id`    INT UNSIGNED DEFAULT NULL,
  `keputusan`  ENUM('validasi','tahan') NOT NULL,
  `alasan`     VARCHAR(255) DEFAULT NULL,
  `rincian`    TEXT DEFAULT NULL COMMENT 'JSON: syarat yang diperiksa dan nilainya',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vd_result` (`result_id`),
  CONSTRAINT `fk_vd_result` FOREIGN KEY (`result_id`)
    REFERENCES `results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PENAMBAHAN KOLOM PADA TABEL YANG SUDAH ADA
--
--    MariaDB 10.4+ mendukung ADD COLUMN IF NOT EXISTS, sehingga skrip
--    ini boleh dijalankan berulang kali tanpa galat.
-- ---------------------------------------------------------------------

-- 5a. results: identitas run, lot, waktu pemeriksaan alat, satuan UCUM,
--     dan alasan bila nilai memang tidak ada (padanan dataAbsentReason
--     pada FHIR — "tidak diperiksa" berbeda dari "hasilnya nol").
ALTER TABLE `results`
  ADD COLUMN IF NOT EXISTS `run_id` BIGINT UNSIGNED DEFAULT NULL
    COMMENT 'analytical_runs.id — run yang menghasilkan nilai ini' AFTER `instrument_id`,
  ADD COLUMN IF NOT EXISTS `reagent_lot_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Lot reagen utama saat nilai dihasilkan' AFTER `run_id`,
  ADD COLUMN IF NOT EXISTS `analisis_at` DATETIME DEFAULT NULL
    COMMENT 'Waktu pemeriksaan menurut alat (OBX-14 / OBR-7), bukan waktu terima LIS' AFTER `reagent_lot_id`,
  ADD COLUMN IF NOT EXISTS `satuan_ucum` VARCHAR(30) DEFAULT NULL
    COMMENT 'Satuan dalam notasi UCUM untuk pertukaran data (SATUSEHAT/FHIR)' AFTER `satuan`,
  ADD COLUMN IF NOT EXISTS `alasan_tidak_ada` ENUM(
      '','tidak_diperiksa','spesimen_tidak_memadai','spesimen_ditolak',
      'alat_gagal','di_luar_rentang_ukur','sedang_diulang','dibatalkan'
    ) NOT NULL DEFAULT '' COMMENT 'Mengapa nilai kosong — padanan dataAbsentReason' AFTER `nilai_num`,
  ADD COLUMN IF NOT EXISTS `qc_status` ENUM('belum','lolos','peringatan','gagal','tidak_berlaku')
    NOT NULL DEFAULT 'tidak_berlaku' COMMENT 'Status QC yang berlaku saat nilai dihasilkan' AFTER `delta_persen`,
  ADD COLUMN IF NOT EXISTS `alasan_koreksi` VARCHAR(255) DEFAULT NULL
    COMMENT 'Wajib diisi bila status berubah menjadi corrected — ISO 15189 7.8.7' AFTER `catatan`;

ALTER TABLE `results`
  ADD KEY IF NOT EXISTS `idx_results_run` (`run_id`),
  ADD KEY IF NOT EXISTS `idx_results_lot` (`reagent_lot_id`),
  ADD KEY IF NOT EXISTS `idx_results_qc` (`qc_status`);

-- 5b. tests: satuan UCUM dan sistem pengkodean, agar hasil dapat
--     dikirim ke SATUSEHAT tanpa penebakan di sisi penerima.
ALTER TABLE `tests`
  ADD COLUMN IF NOT EXISTS `satuan_ucum` VARCHAR(30) DEFAULT NULL
    COMMENT 'Satuan UCUM, mis. g/dL, 10*9/L, mmol/L' AFTER `satuan`,
  ADD COLUMN IF NOT EXISTS `loinc_versi` VARCHAR(10) DEFAULT NULL
    COMMENT 'Versi tabel LOINC saat kode dipetakan' AFTER `loinc`,
  ADD COLUMN IF NOT EXISTS `kritis_batas_menit` INT NOT NULL DEFAULT 30
    COMMENT 'Target waktu lapor nilai kritis (CLSI GP47)' AFTER `is_kritis`;

-- 5c. qc_results: hubungkan ke run dan lot reagen, dan catat siapa/apa
--     yang mengirim. Tanpa instrument_id di sini, QC dari dua alat
--     dengan lot yang sama tidak dapat dibedakan.
ALTER TABLE `qc_results`
  ADD COLUMN IF NOT EXISTS `run_id` BIGINT UNSIGNED DEFAULT NULL AFTER `qc_lot_id`,
  ADD COLUMN IF NOT EXISTS `instrument_id` INT UNSIGNED DEFAULT NULL AFTER `run_id`,
  ADD COLUMN IF NOT EXISTS `reagent_lot_id` INT UNSIGNED DEFAULT NULL AFTER `instrument_id`,
  ADD COLUMN IF NOT EXISTS `sumber` ENUM('manual','alat') NOT NULL DEFAULT 'manual' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `message_id` BIGINT UNSIGNED DEFAULT NULL
    COMMENT 'instrument_messages.id bila QC datang dari alat' AFTER `sumber`;

ALTER TABLE `qc_results`
  ADD KEY IF NOT EXISTS `idx_qcres_run` (`run_id`),
  ADD KEY IF NOT EXISTS `idx_qcres_instrument` (`instrument_id`,`tgl_uji`);

-- 5d. qc_lots: aturan Westgard yang berlaku dan apakah kegagalannya
--     menahan rilis hasil pasien. Tidak semua parameter diperlakukan
--     sama — kegagalan QC pada parameter penyaring dapat ditoleransi
--     sementara parameter kritis tidak.
ALTER TABLE `qc_lots`
  ADD COLUMN IF NOT EXISTS `menahan_rilis` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = QC gagal menahan rilis hasil pasien untuk parameter ini' AFTER `aktif`,
  ADD COLUMN IF NOT EXISTS `berlaku_jam` INT NOT NULL DEFAULT 24
    COMMENT 'Masa berlaku satu titik QC (jam) sebelum dianggap kedaluwarsa' AFTER `menahan_rilis`,
  ADD COLUMN IF NOT EXISTS `aturan_westgard` VARCHAR(120) NOT NULL DEFAULT '1-3s,2-2s,R-4s,4-1s,10x'
    COMMENT 'Aturan yang dievaluasi, dipisah koma' AFTER `berlaku_jam`;

-- 5e. specimens: garis keturunan spesimen (induk → aliquot) dan waktu
--     yang dibutuhkan untuk menghitung TAT per tahap, bukan hanya
--     order→selesai.
ALTER TABLE `specimens`
  ADD COLUMN IF NOT EXISTS `induk_specimen_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'NULL = tabung primer; terisi = aliquot dari spesimen lain' AFTER `specimen_type_id`,
  ADD COLUMN IF NOT EXISTS `aliquot_ke` TINYINT NOT NULL DEFAULT 0
    COMMENT '0 = primer, 1..n = urutan aliquot' AFTER `induk_specimen_id`,
  ADD COLUMN IF NOT EXISTS `disiapkan_at` DATETIME DEFAULT NULL
    COMMENT 'Selesai sentrifugasi/preparasi — batas pra-analitik' AFTER `received_at`;

ALTER TABLE `specimens`
  ADD KEY IF NOT EXISTS `idx_specimens_induk` (`induk_specimen_id`);

-- 5f. instruments: himpunan aturan autovalidasi yang dipakai alat ini.
ALTER TABLE `instruments`
  ADD COLUMN IF NOT EXISTS `rule_set_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'verification_rule_sets.id; NULL = pakai himpunan aktif bawaan' AFTER `auto_verify_max_flag`,
  ADD COLUMN IF NOT EXISTS `qc_menahan_rilis` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = hasil dari alat ini tidak boleh dirilis saat QC gagal' AFTER `rule_set_id`;

-- ---------------------------------------------------------------------
-- 6. PENGATURAN BARU
-- ---------------------------------------------------------------------

INSERT INTO `settings` (`key`,`value`,`grup`,`keterangan`) VALUES
 ('mutu.qc_menahan_rilis','1','mutu',
  '1 = hasil pasien tidak dapat dirilis bila QC parameter tersebut gagal atau belum dijalankan. Mematikannya harus dicatat sebagai penyimpangan.'),
 ('mutu.qc_belum_menahan','0','mutu',
  '1 = QC yang BELUM dijalankan hari itu juga menahan rilis (lebih ketat). 0 = hanya QC gagal yang menahan.'),
 ('mutu.qc_berlaku_jam','24','mutu',
  'Berapa jam satu titik QC dianggap masih mewakili keadaan alat.'),
 ('mutu.lot_kadaluarsa_menahan','1','mutu',
  '1 = hasil tidak boleh divalidasi otomatis bila lot reagen sudah kedaluwarsa atau ditarik.'),
 ('kritis.batas_menit','30','kritis',
  'Target waktu pelaporan nilai kritis sejak terdeteksi (menit), CLSI GP47.'),
 ('kritis.wajib_baca_ulang','1','kritis',
  '1 = pelaporan nilai kritis baru sah bila penerima mengulang nilainya dan cocok.'),
 ('koreksi.wajib_alasan','1','mutu',
  '1 = perubahan hasil yang sudah diverifikasi wajib disertai alasan tertulis (ISO 15189 7.8.7).')
ON DUPLICATE KEY UPDATE `keterangan` = VALUES(`keterangan`);

-- ---------------------------------------------------------------------
-- 7. HIMPUNAN ATURAN AUTOVALIDASI BAWAAN
--
--    Sengaja berstatus 'draft'. Autovalidasi tidak boleh menyala hanya
--    karena basis data selesai dipasang — laboratorium harus meninjau,
--    menyetujui, lalu mengaktifkannya. Itulah maksud AUTO15.
-- ---------------------------------------------------------------------

INSERT INTO `verification_rule_sets` (`id`,`nama`,`versi`,`status`,`keterangan`) VALUES
 (1,'Autovalidasi Dasar','1.0','draft',
  'Himpunan bawaan: hanya hasil normal tanpa penanda apa pun yang lolos. Wajib ditinjau dan disetujui penanggung jawab lab sebelum diaktifkan.')
ON DUPLICATE KEY UPDATE `keterangan` = VALUES(`keterangan`);

INSERT INTO `verification_rules`
 (`rule_set_id`,`urut`,`nama`,`flag_maks`,`delta_maks_persen`,
  `tolak_jika_kritis`,`tolak_jika_qc_gagal`,`tolak_jika_qc_belum`,
  `tolak_jika_satuan_bentrok`,`tolak_jika_lot_kadaluarsa`,
  `tolak_jika_flag_alat`,`aksi`,`alasan_tahan`) VALUES
 (1,10,'Tahan bila alat menandai hasil meragukan',NULL,NULL,0,0,0,0,0,
  'R,*,+++,?,ABN,ERR','tahan','Alat menandai hasil sebagai meragukan'),
 (1,20,'Validasi hasil normal tanpa penanda','N',20.0,1,1,1,1,1,
  NULL,'validasi',NULL)
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`);

-- ---------------------------------------------------------------------
-- 8. SATUAN UCUM UNTUK PEMERIKSAAN YANG SUDAH ADA
--
--    Pemetaan satuan tampilan → notasi UCUM. Satuan tampilan tetap
--    dipakai pada lembar hasil karena itulah yang dikenali klinisi;
--    UCUM dipakai saat data dipertukarkan.
-- ---------------------------------------------------------------------

UPDATE `tests` SET `satuan_ucum` = CASE TRIM(`satuan`)
    WHEN 'g/dL'      THEN 'g/dL'
    WHEN 'g/dl'      THEN 'g/dL'
    WHEN 'mg/dL'     THEN 'mg/dL'
    WHEN 'mg/dl'     THEN 'mg/dL'
    WHEN 'g/L'       THEN 'g/L'
    WHEN 'mmol/L'    THEN 'mmol/L'
    WHEN 'umol/L'    THEN 'umol/L'
    WHEN 'µmol/L'    THEN 'umol/L'
    WHEN '%'         THEN '%'
    WHEN 'fL'        THEN 'fL'
    WHEN 'pg'        THEN 'pg'
    WHEN 'U/L'       THEN 'U/L'
    WHEN 'IU/L'      THEN '[IU]/L'
    WHEN 'mEq/L'     THEN 'meq/L'
    WHEN 'ng/mL'     THEN 'ng/mL'
    WHEN 'ng/dL'     THEN 'ng/dL'
    WHEN 'ug/mL'     THEN 'ug/mL'
    WHEN 'mg/L'      THEN 'mg/L'
    WHEN 'mIU/mL'    THEN 'm[IU]/mL'
    WHEN 'uIU/mL'    THEN 'u[IU]/mL'
    -- D-dimer dilaporkan sebagai Fibrinogen Equivalent Unit; UCUM tidak
    -- memiliki simbol untuk FEU, sehingga dipakai satuan massanya dan
    -- keterangan FEU tetap pada nama pemeriksaan.
    WHEN 'ug/mL FEU' THEN 'ug/mL'
    WHEN 'mL/min/1.73m2' THEN 'mL/min/{1.73_m2}'
    WHEN 'mm/jam'    THEN 'mm/h'
    WHEN 'detik'     THEN 's'
    WHEN 'ribu/uL'   THEN '10*3/uL'
    WHEN '10^3/uL'   THEN '10*3/uL'
    WHEN '10^6/uL'   THEN '10*6/uL'
    WHEN 'juta/uL'   THEN '10*6/uL'
    WHEN '/uL'       THEN '/uL'
    WHEN '/LPB'      THEN '/[HPF]'
    WHEN '/LPK'      THEN '/[LPF]'
    ELSE NULL
  END
WHERE `satuan_ucum` IS NULL AND `satuan` IS NOT NULL AND TRIM(`satuan`) <> '';

-- ---------------------------------------------------------------------
-- 9. PANDANGAN BANTU
-- ---------------------------------------------------------------------

-- Nilai kritis yang belum dilaporkan dan sudah/hampir lewat tenggat.
CREATE OR REPLACE VIEW `v_kritis_tertunggak` AS
SELECT cn.`id`, cn.`result_id`, cn.`order_id`,
       o.`no_order`, o.`no_lab`, o.`prioritas`, o.`nama_ruang`, o.`dokter_perujuk`,
       p.`no_rm`, p.`nama` AS `nama_pasien`,
       t.`kode` AS `kode_test`, t.`nama` AS `nama_test`,
       cn.`nilai`, cn.`satuan`, cn.`flag`,
       cn.`terdeteksi_at`, cn.`jatuh_tempo_at`,
       TIMESTAMPDIFF(MINUTE, cn.`terdeteksi_at`, NOW()) AS `usia_menit`,
       cn.`batas_menit`,
       (NOW() > cn.`jatuh_tempo_at`) AS `lewat_tenggat`
FROM `critical_notifications` cn
JOIN `orders`   o ON o.`id` = cn.`order_id`
JOIN `patients` p ON p.`id` = cn.`patient_id`
JOIN `tests`    t ON t.`id` = cn.`test_id`
WHERE cn.`status` IN ('menunggu','terlambat');

-- Penelusuran penarikan lot: hasil pasien mana yang keluar dari lot ini.
CREATE OR REPLACE VIEW `v_telusur_lot` AS
SELECT rl.`id` AS `reagent_lot_id`, rl.`nama` AS `nama_reagen`, rl.`lot`,
       rl.`status` AS `status_lot`,
       r.`id` AS `result_id`, r.`status` AS `status_hasil`,
       o.`no_order`, o.`tgl_order`,
       p.`no_rm`, p.`nama` AS `nama_pasien`,
       t.`kode` AS `kode_test`, t.`nama` AS `nama_test`,
       r.`nilai`, r.`satuan`, r.`analisis_at`
FROM `reagent_lots` rl
JOIN `results`  r ON r.`reagent_lot_id` = rl.`id`
JOIN `orders`   o ON o.`id` = r.`order_id`
JOIN `patients` p ON p.`id` = o.`patient_id`
JOIN `tests`    t ON t.`id` = r.`test_id`;

-- =====================================================================
--  Selesai. Jalankan bin/periksa-mutu.php untuk memastikan setiap
--  objek di atas benar-benar terbentuk.
-- =====================================================================
