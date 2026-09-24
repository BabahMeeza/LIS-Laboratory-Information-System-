-- =====================================================================
--  Duplikat pemetaan kode parameter dari satu alat ke alat lain
--
--  UNTUK APA
--
--  Dua alat dengan merk dan model yang sama mengirim kode parameter yang
--  sama persis. Mengetik ulang pemetaannya bukan hanya melelahkan — itu
--  cara paling mudah membuat kedua alat diam-diam berbeda. Satu kode
--  terlewat pada alat kedua, dan hasil parameter itu berakhir di "Hasil
--  Belum Terpetakan" hanya bila sampelnya kebetulan dikerjakan di alat
--  itu. Gejalanya berpindah-pindah dan sangat sulit dilacak.
--
--  Berkas ini menyalin SELURUH baris pemetaan alat sumber ke alat tujuan,
--  apa adanya: kode alat, pemeriksaan LIS, satuan, faktor, offset, dan
--  penanda abaikan.
--
--  CARA PAKAI
--
--    1. Ubah dua baris @sumber_kode dan @tujuan_kode di bawah.
--    2. mysql -u root -p db_lis < 07_duplikat_pemetaan_alat.sql
--    3. Periksa ringkasan yang tercetak di akhir.
--
--  Aman dijalankan berulang kali. Baris yang sudah ada diperbarui, bukan
--  digandakan (kunci unik uq_itm pada instrument_id + kode_alat).
--
--  Bila alat tujuan belum terdaftar, berkas ini mendaftarkannya dengan
--  meniru alat sumber — TETAPI dengan host, port, dan serial_port
--  dikosongkan, dan aktif = 0. Dua alat tidak boleh berbagi alamat yang
--  sama, jadi alamat harus diisi sadar lewat menu Alat Laboratorium,
--  bukan diwariskan diam-diam dari alat sumber.
-- =====================================================================

SET @sumber_kode := 'HEMA-03';                 -- alat yang pemetaannya sudah benar
SET @tujuan_kode := 'HEMA-02';                 -- alat yang akan disamakan
SET @tujuan_nama := 'Hematology Analyzer 2';   -- dipakai hanya bila alat tujuan belum ada

-- Salin juga lot QC milik alat sumber? Bawaannya TIDAK, dan itu disengaja.
-- Mean dan SD adalah sifat SATU alat, bukan sifat model. Dua analyzer
-- sejenis punya bias yang berbeda, dan menyalin angka milik alat lain
-- membuat kartu Levey-Jennings alat kedua terlihat menyimpang sejak hari
-- pertama, atau — lebih berbahaya — terlihat baik padahal tidak.
-- ISO 15189 menuntut tiap alat menetapkan sendiri mean/SD-nya dari
-- minimal 20 titik pengukuran.
--
-- Ubah ke 1 hanya bila Anda memang ingin angka sementara sebagai titik
-- awal, dan sudah berniat menggantinya setelah 20 run pertama.
SET @salin_qc := 0;

-- ---------------------------------------------------------------------
-- 1. Kenali kedua alat
-- ---------------------------------------------------------------------

SET @sumber := (SELECT id FROM `instruments` WHERE `kode` = @sumber_kode);

SELECT
  CASE
    WHEN @sumber IS NULL THEN CONCAT('BERHENTI: alat sumber "', @sumber_kode, '" tidak ada. Periksa ejaan kodenya di menu Alat Laboratorium.')
    ELSE CONCAT('Alat sumber ditemukan: ', @sumber_kode, ' (id ', @sumber, ')')
  END AS `Langkah 1`;

-- ---------------------------------------------------------------------
-- 2. Daftarkan alat tujuan bila belum ada
--
--    MENGAPA PERINTAHNYA DIRAKIT, BUKAN DITULIS LANGSUNG
--
--    Tabel instruments tumbuh bertahap: rule_set_id dan qc_menahan_rilis
--    baru ditambahkan oleh 04_standar_mutu.sql. Database yang belum
--    menjalankan berkas itu tidak punya kolomnya, dan SQL statis yang
--    menyebut nama kolom tersebut gagal seketika:
--
--        #1054 - Unknown column 's.rule_set_id' in 'field list'
--
--    MySQL memeriksa nama kolom saat mengurai, bukan saat menjalankan,
--    jadi tidak ada syarat IF yang bisa menyelamatkannya. Satu-satunya
--    jalan adalah tidak menyebut kolom itu sama sekali kecuali memang ada.
--
--    Karena itu daftar kolom dibaca dulu dari information_schema, lalu
--    perintahnya dirakit dari kolom yang benar-benar ada. Berkas ini jadi
--    berjalan di database versi mana pun, dan kolom yang belum ada cukup
--    memakai nilai bawaannya.
--
--    NOT EXISTS menjaga agar alat yang sudah terdaftar tidak tertimpa —
--    alamat yang sudah diisi operator tetap utuh.
-- ---------------------------------------------------------------------

SET SESSION group_concat_max_len = 8192;

-- Kolom yang layak ditiru. host/port/serial_port SENGAJA tidak ada di
-- sini: dua alat tidak boleh berbagi alamat, jadi biarkan kosong.
SET @kolom := (
  SELECT GROUP_CONCAT(CONCAT('`', `COLUMN_NAME`, '`') ORDER BY `ORDINAL_POSITION`)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME`   = 'instruments'
    AND `COLUMN_NAME` IN ('merk','model','category_id','protokol','transport','mode',
                          'baud_rate','data_bits','stop_bits','parity','flow_control',
                          'encoding','field_delim','auto_verify','auto_verify_max_flag',
                          'rule_set_id','qc_menahan_rilis','simpan_raw')
);

SET @kolom_s := (
  SELECT GROUP_CONCAT(CONCAT('s.`', `COLUMN_NAME`, '`') ORDER BY `ORDINAL_POSITION`)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME`   = 'instruments'
    AND `COLUMN_NAME` IN ('merk','model','category_id','protokol','transport','mode',
                          'baud_rate','data_bits','stop_bits','parity','flow_control',
                          'encoding','field_delim','auto_verify','auto_verify_max_flag',
                          'rule_set_id','qc_menahan_rilis','simpan_raw')
);

SET @sql := CONCAT(
  'INSERT INTO `instruments` (`kode`, `nama`, `aktif`, ', @kolom, ') ',
  'SELECT @tujuan_kode, @tujuan_nama, 0, ', @kolom_s, ' ',
  'FROM `instruments` s ',
  'WHERE s.`id` = @sumber ',
  '  AND NOT EXISTS (SELECT 1 FROM `instruments` t WHERE t.`kode` = @tujuan_kode)'
);

PREPARE buat_alat FROM @sql;
EXECUTE buat_alat;

-- ROW_COUNT() hanya sahih untuk perintah TEPAT SEBELUMNYA — termasuk
-- DEALLOCATE. Karena itu hasilnya dipungut di sini, sebelum apa pun yang
-- lain dijalankan. Bila dibaca setelah SET atau DEALLOCATE, laporannya
-- selalu berbunyi "sudah ada" walaupun alatnya baru saja dibuat.
SET @dibuat := ROW_COUNT();

DEALLOCATE PREPARE buat_alat;
SET @tujuan := (SELECT id FROM `instruments` WHERE `kode` = @tujuan_kode);

SELECT
  CASE
    WHEN @tujuan IS NULL THEN 'BERHENTI: alat tujuan gagal dibuat.'
    WHEN @dibuat > 0 THEN CONCAT('Alat tujuan BARU dibuat: ', @tujuan_kode, ' (id ', @tujuan, ') — host/port masih kosong, aktif = 0')
    ELSE CONCAT('Alat tujuan sudah ada: ', @tujuan_kode, ' (id ', @tujuan, ') — pengaturannya tidak diubah')
  END AS `Langkah 2`;

-- ---------------------------------------------------------------------
-- 3. Salin pemetaan
--
--    ON DUPLICATE KEY UPDATE membuat perintah ini dapat dijalankan
--    berulang: menjalankannya lagi setelah pemetaan sumber diperbaiki
--    akan ikut memperbaiki alat tujuan, bukan menggandakan barisnya.
--
--    Syarat @sumber/@tujuan IS NOT NULL mencegah kesalahan ejaan kode
--    berubah menjadi galat foreign key yang membingungkan.
-- ---------------------------------------------------------------------

INSERT INTO `instrument_test_map`
  (`instrument_id`, `kode_alat`, `test_id`, `satuan_alat`, `faktor`, `offset_nilai`, `abaikan`)
SELECT
  @tujuan, m.`kode_alat`, m.`test_id`, m.`satuan_alat`, m.`faktor`, m.`offset_nilai`, m.`abaikan`
FROM `instrument_test_map` m
WHERE m.`instrument_id` = @sumber
  AND @sumber IS NOT NULL
  AND @tujuan IS NOT NULL
  AND @tujuan <> @sumber
ON DUPLICATE KEY UPDATE
  `test_id`      = VALUES(`test_id`),
  `satuan_alat`  = VALUES(`satuan_alat`),
  `faktor`       = VALUES(`faktor`),
  `offset_nilai` = VALUES(`offset_nilai`),
  `abaikan`      = VALUES(`abaikan`);

-- ---------------------------------------------------------------------
-- 4. Lot QC — hanya bila @salin_qc = 1
--
--    qc_lots tidak punya kunci unik, jadi pengulangan dijaga dengan
--    NOT EXISTS atas kombinasi yang secara praktis mengidentifikasi satu
--    lot: test_id + lot + level.
--
--    Dirakit dari information_schema dengan alasan yang sama seperti
--    langkah 2: menahan_rilis, berlaku_jam, dan aturan_westgard baru
--    ditambahkan oleh 04_standar_mutu.sql, dan menyebut namanya pada
--    database yang belum punya kolom itu menggagalkan seluruh berkas —
--    bahkan ketika @salin_qc = 0 dan perintahnya sebenarnya tidak
--    menyalin apa pun, karena nama kolom diperiksa saat diurai.
-- ---------------------------------------------------------------------

SET @qkolom := (
  SELECT GROUP_CONCAT(CONCAT('`', `COLUMN_NAME`, '`') ORDER BY `ORDINAL_POSITION`)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME`   = 'qc_lots'
    AND `COLUMN_NAME` IN ('test_id','nama_bahan','lot','level','mean','sd','cv_target',
                          'tgl_mulai','tgl_kadaluarsa','aktif',
                          'menahan_rilis','berlaku_jam','aturan_westgard')
);

SET @qkolom_q := (
  SELECT GROUP_CONCAT(CONCAT('q.`', `COLUMN_NAME`, '`') ORDER BY `ORDINAL_POSITION`)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME`   = 'qc_lots'
    AND `COLUMN_NAME` IN ('test_id','nama_bahan','lot','level','mean','sd','cv_target',
                          'tgl_mulai','tgl_kadaluarsa','aktif',
                          'menahan_rilis','berlaku_jam','aturan_westgard')
);

-- Bila tabel qc_lots sendiri belum ada, @qkolom bernilai NULL. Perintah
-- pengganti dipakai supaya berkas tetap selesai, bukan berhenti di tengah.
SET @sql := IF(@qkolom IS NULL,
  'SELECT ''Tabel qc_lots belum ada — bagian QC dilewati.'' AS `Langkah 4`',
  CONCAT(
    'INSERT INTO `qc_lots` (`instrument_id`, ', @qkolom, ') ',
    'SELECT @tujuan, ', @qkolom_q, ' ',
    'FROM `qc_lots` q ',
    'WHERE q.`instrument_id` = @sumber ',
    '  AND @salin_qc = 1 ',
    '  AND @sumber IS NOT NULL ',
    '  AND @tujuan IS NOT NULL ',
    '  AND @tujuan <> @sumber ',
    '  AND NOT EXISTS (SELECT 1 FROM `qc_lots` x ',
    '                  WHERE x.`instrument_id` = @tujuan ',
    '                    AND x.`test_id` = q.`test_id` ',
    '                    AND x.`lot`     = q.`lot` ',
    '                    AND x.`level`   = q.`level`)'
  ));

PREPARE salin_qc FROM @sql;
EXECUTE salin_qc;
DEALLOCATE PREPARE salin_qc;

-- ---------------------------------------------------------------------
-- 5. Ringkasan — dan pembuktian bahwa keduanya benar-benar sama
--
--    Angka "beda" harus 0. Bila tidak, ada baris yang gagal disalin dan
--    itu harus terlihat di sini, bukan ditemukan berminggu-minggu
--    kemudian lewat hasil yang hilang.
-- ---------------------------------------------------------------------

SELECT
  (SELECT COUNT(*) FROM `instrument_test_map` WHERE `instrument_id` = @sumber) AS `baris_sumber`,
  (SELECT COUNT(*) FROM `instrument_test_map` WHERE `instrument_id` = @tujuan) AS `baris_tujuan`,
  (SELECT COUNT(*) FROM `instrument_test_map` WHERE `instrument_id` = @tujuan AND `test_id` IS NOT NULL) AS `dipetakan`,
  (SELECT COUNT(*) FROM `instrument_test_map` WHERE `instrument_id` = @tujuan AND `test_id` IS NULL AND `abaikan` = 1) AS `diabaikan`,
  (SELECT COUNT(*) FROM `instrument_test_map` WHERE `instrument_id` = @tujuan AND `test_id` IS NULL AND `abaikan` = 0) AS `perlu_ditinjau`,
  (SELECT COUNT(*) FROM `qc_lots` WHERE `instrument_id` = @tujuan) AS `lot_qc_tujuan`;

-- Daftar selisih: kosong berarti pemetaan kedua alat identik.
SELECT
  s.`kode_alat`,
  s.`test_id`     AS `test_sumber`,
  t.`test_id`     AS `test_tujuan`,
  s.`satuan_alat` AS `satuan_sumber`,
  t.`satuan_alat` AS `satuan_tujuan`,
  CASE WHEN t.`id` IS NULL THEN 'tidak tersalin' ELSE 'nilainya berbeda' END AS `masalah`
FROM `instrument_test_map` s
LEFT JOIN `instrument_test_map` t
  ON t.`instrument_id` = @tujuan AND t.`kode_alat` = s.`kode_alat`
WHERE s.`instrument_id` = @sumber
  AND (t.`id` IS NULL
       OR NOT (t.`test_id` <=> s.`test_id`)
       OR NOT (t.`satuan_alat` <=> s.`satuan_alat`)
       OR t.`faktor` <> s.`faktor`
       OR t.`offset_nilai` <> s.`offset_nilai`
       OR t.`abaikan` <> s.`abaikan`);
