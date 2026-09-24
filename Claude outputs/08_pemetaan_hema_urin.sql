-- =====================================================================
--  Pemetaan parameter — analyzer hematologi (BC-5000) dan urinalisa
--  (MediGo URO), disusun dari pesan yang SUNGGUH dikirim alat.
--
--  Sumber: 157 pesan HL7 pada instrument_messages di database produksi
--  RSUD Hanau (HEMA-03 133 pesan, HEMA-02 21, URIN-02 3).
--
--  ---------------------------------------------------------------
--  TEMUAN YANG HARUS DIBACA SEBELUM MENJALANKAN BERKAS INI
--  ---------------------------------------------------------------
--
--  MediGo URO memakai kode "WBC" untuk DUA pemeriksaan yang berbeda,
--  di dalam SATU pesan yang sama:
--
--      OBX|2|NM|WBC||20|/[HPF]^/HPF^UCUM     hitung leukosit SEDIMEN
--      OBX|25|CE|WBC||+2|                    leukosit ESTERASE carik celup
--
--  Kunci unik instrument_test_map adalah (instrument_id, kode_alat),
--  jadi satu kode hanya bisa punya satu tujuan. Selama ini "WBC"
--  dipetakan ke Sedimen - Leukosit, sehingga nilai esterase "+2" yang
--  datang belakangan MENIMPA hitung sedimen 20. Tidak ada galat, tidak
--  ada peringatan; yang tersimpan hanyalah nilai yang salah.
--
--  Pemisahnya hanya OBX-2 (tipe nilai). Karena itu middleware diberi
--  saklar per-alat: bila bedakan_tipe_nilai = 1, kode alat menjadi
--  "WBC^NM" dan "WBC^CE" sehingga keduanya bisa dipetakan sendiri-sendiri.
--
--  KONSEKUENSINYA: seluruh kode URIN-02 berubah bentuk. Karena itu
--  pemetaan lama alat ini dihapus dan ditulis ulang di bawah. Untuk alat
--  lain tidak ada yang berubah.
--
--  Berkas ini memerlukan middleware versi baru (hl7.js + gateway.js +
--  app/Api/V1/InstrumentApi.php). Menjalankannya tanpa itu membuat
--  URIN-02 berhenti mengenali kodenya sama sekali.
--
--  Aman dijalankan berulang kali.
--
--    mysql -u root -p db_lis < database/08_pemetaan_hema_urin.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Saklar pembeda tipe nilai
-- ---------------------------------------------------------------------

ALTER TABLE `instruments`
  ADD COLUMN IF NOT EXISTS `bedakan_tipe_nilai` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = kode alat disertai tipe OBX-2 (mis. WBC^NM / WBC^CE)' AFTER `encoding`;

UPDATE `instruments` SET `bedakan_tipe_nilai` = 1 WHERE `kode` = 'URIN-02';

-- ---------------------------------------------------------------------
-- 2. Pemeriksaan yang belum ada di master
--
--    Nama-nama ini bukan karangan: semuanya sudah dipakai lab Anda,
--    terbaca dari khanza_templates (mis. "epitel transisional",
--    "Silinder Hialin", "Ca.Oxalat", "Triple Fosfat", "Jamur").
--    Alat mengirimnya per jenis, jadi masternya harus per jenis juga —
--    kalau tidak, empat jenis kristal berebut satu baris dan tiga di
--    antaranya hilang.
-- ---------------------------------------------------------------------

INSERT INTO `tests` (`kode`,`nama`,`nama_singkat`,`category_id`,`specimen_type_id`,`satuan`,`tipe_hasil`,`desimal`,`aktif`)
VALUES
 -- Sedimen urine, dihitung per lapangan pandang
 ('U_SED_LEU_KLP','Sedimen - Leukosit Bergerombol','Leukosit bergerombol',3,6,'/LPB','numerik',0,1),
 ('U_SED_EPI_TRANS','Sedimen - Epitel Transisional','Epitel transisional',3,6,'/LPB','numerik',0,1),
 ('U_SED_EPI_TUB','Sedimen - Epitel Tubulus Ginjal','Epitel tubulus',3,6,'/LPB','numerik',0,1),
 ('U_SED_SEL_LAIN','Sedimen - Sel Lain','Sel lain',3,6,'/LPB','numerik',0,1),
 ('U_SED_SIL_HIALIN','Sedimen - Silinder Hialin','Silinder hialin',3,6,'/LPK','numerik',0,1),
 ('U_SED_SIL_GRAN','Sedimen - Silinder Granula','Silinder granula',3,6,'/LPK','numerik',0,1),
 ('U_SED_SIL_LILIN','Sedimen - Silinder Lilin','Silinder lilin',3,6,'/LPK','numerik',0,1),
 ('U_SED_KRIS_CAOX','Sedimen - Kristal Kalsium Oksalat','Ca oksalat',3,6,'/LPB','numerik',0,1),
 ('U_SED_KRIS_URAT','Sedimen - Kristal Asam Urat','Kristal asam urat',3,6,'/LPB','numerik',0,1),
 ('U_SED_KRIS_TRIPEL','Sedimen - Kristal Tripel Fosfat','Tripel fosfat',3,6,'/LPB','numerik',0,1),
 ('U_SED_KRIS_AMORF','Sedimen - Kristal Amorf','Kristal amorf',3,6,'/LPB','numerik',0,1),
 ('U_SED_JAMUR','Sedimen - Jamur / Ragi','Jamur',3,6,'/LPB','numerik',0,1),
 ('U_SED_LENDIR','Sedimen - Lendir','Lendir',3,6,'/LPB','numerik',0,1),

 -- Hitung jenis leukosit ABSOLUT. Master hanya punya versi persen,
 -- padahal BC-5000 mengirim keduanya dan yang absolut lebih dipakai
 -- klinisi (mis. neutropenia dinilai dari NEUT#, bukan NEUT%).
 ('NEUT_ABS','Neutrofil Absolut','NEUT#',1,1,'10^3/uL','numerik',2,1),
 ('LYMPH_ABS','Limfosit Absolut','LYMPH#',1,1,'10^3/uL','numerik',2,1),
 ('MONO_ABS','Monosit Absolut','MONO#',1,1,'10^3/uL','numerik',2,1),
 ('EO_ABS','Eosinofil Absolut','EO#',1,1,'10^3/uL','numerik',2,1),
 ('BASO_ABS','Basofil Absolut','BASO#',1,1,'10^3/uL','numerik',2,1),
 ('RDW_SD','RDW-SD','RDW-SD',1,1,'fL','numerik',1,1),
 ('PDW','PDW','PDW',1,1,'','numerik',1,1),
 ('PCT','PCT','PCT',1,1,'%','numerik',3,1),
 ('PLR','Rasio Trombosit-Limfosit','PLR',1,1,'','numerik',2,1)
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`);

-- ---------------------------------------------------------------------
-- 3. HEMATOLOGI — HEMA-02 dan HEMA-03 sekaligus
--
--    Keduanya BC-5000 dan mengirim kode LOINC yang sama, jadi
--    pemetaannya identik. Ditulis satu kali lalu diterapkan ke setiap
--    alat BC-5000 yang aktif.
--
--    Kode-kode ini sebelumnya bertanda abaikan = 1, sehingga nilainya
--    dibuang diam-diam. NLR yang paling mencolok: pemeriksaannya sudah
--    ada di master (kode NLR) tetapi kode alatnya 10057 diabaikan.
-- ---------------------------------------------------------------------

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, x.`kode_alat`, t.`id`, x.`satuan`, 1, 0, 0
FROM `instruments` i
JOIN (
  SELECT '751-8'   AS kode_alat, 'NEUT_ABS'  AS kode_lis, '10*3/uL' AS satuan UNION ALL
  SELECT '731-0',  'LYMPH_ABS', '10*3/uL' UNION ALL
  SELECT '742-7',  'MONO_ABS',  '10*3/uL' UNION ALL
  SELECT '711-2',  'EO_ABS',    '10*3/uL' UNION ALL
  SELECT '704-7',  'BASO_ABS',  '10*3/uL' UNION ALL
  SELECT '21000-5','RDW_SD',    'fL'      UNION ALL
  SELECT '32207-3','PDW',       ''        UNION ALL
  SELECT '10002',  'PCT',       '%'       UNION ALL
  SELECT '10057',  'NLR',       ''        UNION ALL
  SELECT '10058',  'PLR',       ''
) x ON 1 = 1
JOIN `tests` t ON t.`kode` = x.`kode_lis`
WHERE i.`model` = 'BC-5000'
ON DUPLICATE KEY UPDATE
  `test_id`     = VALUES(`test_id`),
  `satuan_alat` = VALUES(`satuan_alat`),
  `abaikan`     = 0;

-- ---------------------------------------------------------------------
-- 4. URINALISA — URIN-02
--
--    Pemetaan lama dihapus karena bentuk kodenya berubah (lihat bagian 1).
-- ---------------------------------------------------------------------

DELETE FROM `instrument_test_map`
WHERE `instrument_id` = (SELECT `id` FROM `instruments` WHERE `kode` = 'URIN-02');

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, x.`kode_alat`, t.`id`, x.`satuan`, 1, 0, 0
FROM `instruments` i
JOIN (
  -- Sedimen mikroskopik (OBX-2 = NM)
  SELECT 'WBC^NM'       AS kode_alat, 'U_SED_LEU'         AS kode_lis, '/LPB' AS satuan UNION ALL
  SELECT 'RBC^NM',       'U_SED_ERI',         '/LPB' UNION ALL
  SELECT 'WBCC^NM',      'U_SED_LEU_KLP',     '/LPB' UNION ALL
  SELECT 'SQEP^NM',      'U_SED_EPI',         '/LPB' UNION ALL
  SELECT 'TREP^NM',      'U_SED_EPI_TRANS',   '/LPB' UNION ALL
  SELECT 'REP^NM',       'U_SED_EPI_TUB',     '/LPB' UNION ALL
  SELECT 'NSE-Other^NM', 'U_SED_SEL_LAIN',    '/LPB' UNION ALL
  SELECT 'HYA^NM',       'U_SED_SIL_HIALIN',  '/LPK' UNION ALL
  SELECT 'GRAN^NM',      'U_SED_SIL_GRAN',    '/LPK' UNION ALL
  SELECT 'WAXY^NM',      'U_SED_SIL_LILIN',   '/LPK' UNION ALL
  SELECT 'CAOX^NM',      'U_SED_KRIS_CAOX',   '/LPB' UNION ALL
  SELECT 'URIC^NM',      'U_SED_KRIS_URAT',   '/LPB' UNION ALL
  SELECT 'STRUVITE^NM',  'U_SED_KRIS_TRIPEL', '/LPB' UNION ALL
  SELECT 'AMOR^NM',      'U_SED_KRIS_AMORF',  '/LPB' UNION ALL
  SELECT 'OTCRY^NM',     'U_SED_KRIS',        '/LPB' UNION ALL
  SELECT 'BACT^NM',      'U_SED_BAKT',        '/LPB' UNION ALL
  SELECT 'FUNGUS^NM',    'U_SED_JAMUR',       '/LPB' UNION ALL
  SELECT 'MUCS^NM',      'U_SED_LENDIR',      '/LPB' UNION ALL

  -- Fisik
  SELECT 'Color^ST',     'U_WARNA',       '' UNION ALL
  SELECT 'TUR^ST',       'U_KEJERNIHAN',  '' UNION ALL
  SELECT 'SG^NM',        'U_BJ',          '' UNION ALL
  SELECT 'PH^NM',        'U_PH',          '' UNION ALL

  -- Carik celup (OBX-2 = CE). Perhatikan WBC^CE: inilah leukosit
  -- ESTERASE, yang selama ini menimpa hitung sedimen.
  SELECT 'WBC^CE',       'U_LEU',   '' UNION ALL
  SELECT 'NIT^CE',       'U_NIT',   '' UNION ALL
  SELECT 'PRO^CE',       'U_PROT',  '' UNION ALL
  SELECT 'GLU^CE',       'U_GLU',   '' UNION ALL
  SELECT 'KET^CE',       'U_KET',   '' UNION ALL
  SELECT 'BIL^CE',       'U_BIL',   '' UNION ALL
  SELECT 'BLD^CE',       'U_BLD',   '' UNION ALL

  -- Urobilinogen dikirim kadang bertipe CE, kadang ST. Keduanya
  -- didaftarkan; baris berlebih tidak merugikan, baris yang hilang
  -- membuat hasil menguap.
  SELECT 'URO^CE',       'U_URO',   '' UNION ALL
  SELECT 'URO^ST',       'U_URO',   ''
) x ON 1 = 1
JOIN `tests` t ON t.`kode` = x.`kode_lis`
WHERE i.`kode` = 'URIN-02';

-- ---------------------------------------------------------------------
-- 5. Yang memang BUKAN hasil pemeriksaan — ditandai abaikan
--
--    Ditandai, bukan dibiarkan kosong, supaya tidak memenuhi layar
--    "Hasil Belum Terpetakan" setiap kali sampel dikerjakan.
--
--    MCV pada alat ini adalah volume rata-rata ERITROSIT URINE, bukan
--    MCV darah. Kalau sampai dipetakan ke MCV hematologi, hasil urine
--    akan muncul sebagai indeks eritrosit pasien.
-- ---------------------------------------------------------------------

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, x.`kode_alat`, NULL, NULL, 1, 0, 1
FROM `instruments` i
JOIN (
  SELECT 'VC^CE'      AS kode_alat UNION ALL  -- vitamin C: pengganggu carik celup
  SELECT 'Cond^NM'    UNION ALL               -- konduktivitas
  SELECT 'OSM^NM'     UNION ALL               -- osmolalitas hitungan
  SELECT 'MCV^NM'     UNION ALL               -- MCV eritrosit URINE, bukan darah
  SELECT 'MCV_CV^NM'  UNION ALL
  SELECT 'R_TATE^NM'  UNION ALL
  SELECT 'CELL^NM'    UNION ALL               -- tidak spesifik
  SELECT 'OTHER^NM'                           -- tidak spesifik
) x ON 1 = 1
WHERE i.`kode` = 'URIN-02'
ON DUPLICATE KEY UPDATE `abaikan` = 1;

-- ---------------------------------------------------------------------
-- 6. Ringkasan
-- ---------------------------------------------------------------------

SELECT
  i.`kode`                                   AS alat,
  COUNT(m.`id`)                              AS baris,
  SUM(m.`test_id` IS NOT NULL)               AS dipetakan,
  SUM(m.`test_id` IS NULL AND m.`abaikan`=1) AS diabaikan,
  SUM(m.`test_id` IS NULL AND m.`abaikan`=0) AS perlu_ditinjau,
  i.`bedakan_tipe_nilai`                     AS bedakan_tipe
FROM `instruments` i
LEFT JOIN `instrument_test_map` m ON m.`instrument_id` = i.`id`
WHERE i.`kode` IN ('HEMA-02','HEMA-03','URIN-02')
GROUP BY i.`kode`, i.`bedakan_tipe_nilai`;
