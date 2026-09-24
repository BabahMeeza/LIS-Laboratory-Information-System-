-- =====================================================================
--  BioSystems A15 — transport BERKAS
--
--  Sumber: manual pabrikan "A15 — 3.4.1 LIMS Communications", halaman 1-5.
--
--  A15 tidak berbicara ASTM maupun HL7, dan tidak membuka soket sama
--  sekali. Ia menyalin berkas teks datar ke folder pada PC-nya:
--
--    Import\import.txt           worklist masuk  (dibaca saat operator
--                                menekan "Import session")
--    Export\online(...)_n.txt    hasil keluar, BERTAMBAH tiap hasil baru
--    Import\Errors.txt           alasan baris worklist ditolak
--
--  Karena itu kolom host dan port tidak dipakai untuk alat ini; yang
--  diperlukan adalah dua jalur folder.
--
--  PRASYARAT: middleware harus sudah memuat
--    middleware/src/transports/file.js
--    middleware/src/protocols/a15.js
--    middleware/src/gateway.js      (versi yang mengenali 'file' dan 'a15')
--    app/Api/V1/InstrumentApi.php   (versi yang mengirimkan jalur folder)
--
--  Menjalankan berkas ini tanpa itu membuat KIMIA-02 dilewati dengan
--  pesan "Transport tidak dikenali" — tidak merusak apa pun, tetapi juga
--  tidak bekerja.
--
--  Aman dijalankan berulang kali.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Nilai baru pada enum transport dan protokol
-- ---------------------------------------------------------------------

ALTER TABLE `instruments`
  MODIFY COLUMN `transport` ENUM('tcp_server','tcp_client','serial','file')
    NOT NULL DEFAULT 'tcp_server',
  MODIFY COLUMN `protokol` ENUM('astm','hl7','raw','a15')
    NOT NULL DEFAULT 'astm';

-- ---------------------------------------------------------------------
-- 2. Jalur folder
--
--    Ditulis sebagai UNC karena middleware berjalan di mesin lain
--    (192.168.20.152) sedangkan folder ada di PC A15. Mesin middleware
--    harus punya hak baca pada Export dan hak tulis pada Import.
-- ---------------------------------------------------------------------

ALTER TABLE `instruments`
  ADD COLUMN IF NOT EXISTS `folder_keluar` VARCHAR(255) DEFAULT NULL
    COMMENT 'Folder tempat alat menulis hasil, mis. \\\\192.168.20.x\\A15\\Export'
    AFTER `serial_port`,
  ADD COLUMN IF NOT EXISTS `folder_masuk` VARCHAR(255) DEFAULT NULL
    COMMENT 'Folder tempat LIS menulis import.txt'
    AFTER `folder_keluar`,
  ADD COLUMN IF NOT EXISTS `folder_jeda_detik` INT NOT NULL DEFAULT 5
    COMMENT 'Jeda penyisiran folder; minimal 2 detik'
    AFTER `folder_masuk`;

-- ---------------------------------------------------------------------
-- 3. Setel KIMIA-02
--
--    aktif = 0 sampai jalur foldernya diisi dengan alamat yang benar.
--    Mengaktifkannya dengan folder kosong hanya menghasilkan peringatan
--    berulang di log.
-- ---------------------------------------------------------------------

UPDATE `instruments` SET
  `protokol`          = 'a15',
  `transport`         = 'file',
  `mode`              = 'bidirectional',
  `host`              = NULL,
  `port`              = NULL,
  `serial_port`       = NULL,
  `encoding`          = 'latin1',
  `folder_keluar`     = NULL,
  `folder_masuk`      = NULL,
  `folder_jeda_detik` = 5,
  `aktif`             = 0
WHERE `kode` = 'KIMIA-02';

-- ---------------------------------------------------------------------
-- 4. Ringkasan
-- ---------------------------------------------------------------------

SELECT `kode`, `nama`, `merk`, `model`, `protokol`, `transport`,
       `folder_keluar`, `folder_masuk`, `folder_jeda_detik`, `aktif`
FROM `instruments` WHERE `kode` = 'KIMIA-02';

-- ---------------------------------------------------------------------
--  LANGKAH BERIKUTNYA, DIKERJAKAN MANUSIA
--
--  1. Di PC A15: bagikan folder C:\Program files\A15 agar terbaca dari
--     jaringan, beri hak baca pada Export dan hak tulis pada Import untuk
--     pengguna yang dipakai mesin middleware.
--
--  2. Di A15: buka layar konfigurasi sesi, nyalakan ekspor ONLINE dan
--     pilih frekuensi "whenever a result is obtained". Dua mode lain
--     (EXPAuto saat reset, dan Exp manual) menuntut operator menekan
--     tombol, sehingga hasil tidak mengalir sendiri.
--
--  3. Di LIS, menu Alat -> KIMIA-02 -> Ubah, isi:
--       Folder Keluar : \\<ip-pc-a15>\A15\Export
--       Folder Masuk  : \\<ip-pc-a15>\A15\Import
--     lalu aktifkan.
--
--  4. Jalankan satu sampel, lalu:
--       php bin/petakan-alat.php --alat=KIMIA-02
--     untuk memungut kode teknik yang sebenarnya dipakai alat ini.
--     Kode itu ditentukan saat pemrograman pemeriksaan di A15, jadi tidak
--     dapat ditebak dari manual.
-- ---------------------------------------------------------------------
