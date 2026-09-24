-- =====================================================================
--  URIN-02 (MediGo URO) — kode SEDIMEN protokol V2.0
--
--  Pada V2.0 alat mengirim DUA pesan per sampel:
--    - carik celup, kode berawalan UC_  (dipetakan oleh berkas 11)
--    - sedimen,     kode berawalan UD_  (berkas ini)
--
--  Tujuan pemetaan sama dengan kode SA_V1.0 yang sudah ada (WBC^NM ->
--  U_SED_LEU, dst.), sehingga hasil kedua protokol masuk ke pemeriksaan
--  yang sama. Baris SA_V1.0 tidak diubah.
--
--  Tiap kode ditulis dengan akhiran ^NM / ^ST (URIN-02 memakai
--  bedakan_tipe_nilai = 1) dan tanpa akhiran, sama seperti berkas 11.
--
--  Aman dijalankan berulang kali.
-- =====================================================================

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, CONCAT(x.`kode_alat`, s.`akhiran`), t.`id`, NULL, 1, 0, 0
FROM `instruments` i
JOIN (
  SELECT 'UD_RBC'          AS kode_alat, 'U_SED_ERI'         AS kode_lis UNION ALL
  SELECT 'UD_WBC',          'U_SED_LEU'          UNION ALL  -- hitung leukosit SEDIMEN, bukan esterase
  SELECT 'UD_WBCC',         'U_SED_LEU_KLP'      UNION ALL
  SELECT 'UD_SQEP',         'U_SED_EPI'          UNION ALL
  SELECT 'UD_TREP',         'U_SED_EPI_TRANS'    UNION ALL
  SELECT 'UD_REP',          'U_SED_EPI_TUB'      UNION ALL
  SELECT 'UD_NSE',          'U_SED_SEL_LAIN'     UNION ALL
  SELECT 'UD_HYA',          'U_SED_SIL_HIALIN'   UNION ALL
  SELECT 'UD_GRAN',         'U_SED_SIL_GRAN'     UNION ALL
  SELECT 'UD_WAXY',         'U_SED_SIL_LILIN'    UNION ALL
  SELECT 'UD_CAOX',         'U_SED_KRIS_CAOX'    UNION ALL
  SELECT 'UD_URIC',         'U_SED_KRIS_URAT'    UNION ALL
  SELECT 'UD_STRUVITE',     'U_SED_KRIS_TRIPEL'  UNION ALL
  SELECT 'UD_AMOR',         'U_SED_KRIS_AMORF'   UNION ALL
  SELECT 'UD_UNCX',         'U_SED_KRIS'         UNION ALL  -- kristal tak terklasifikasi
  SELECT 'UD_BACT',         'U_SED_BAKT'         UNION ALL
  SELECT 'UD_FUNGUS',       'U_SED_JAMUR'        UNION ALL
  SELECT 'UD_MUCS',         'U_SED_LENDIR'       UNION ALL
  SELECT 'UD_Color',        'U_WARNA'            UNION ALL
  SELECT 'UD_Transparency', 'U_KEJERNIHAN'
) x
JOIN (SELECT '^NM' AS akhiran UNION ALL SELECT '^ST' UNION ALL SELECT '') s
JOIN `tests` t ON t.`kode` = x.`kode_lis`
WHERE i.`kode` = 'URIN-02'
ON DUPLICATE KEY UPDATE `test_id` = VALUES(`test_id`), `abaikan` = 0;

-- Diabaikan: judul blok, parameter fisik/teknis yang bukan hasil pelaporan,
-- dan medan kosong. Sama dengan perlakuan kode setaranya di SA_V1.0.
INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, CONCAT(x.`kode_alat`, s.`akhiran`), NULL, NULL, 1, 0, 1
FROM `instruments` i
JOIN (
  SELECT 'UD_Title'       AS kode_alat UNION ALL
  SELECT 'Physical_Title' UNION ALL
  SELECT 'RBC_Title'      UNION ALL
  SELECT 'UD_CELL'        UNION ALL  -- jumlah sel total (hitungan alat)
  SELECT 'UD_IMPURITY'    UNION ALL  -- partikel pengotor
  SELECT 'UD_Cond'        UNION ALL  -- konduktivitas
  SELECT 'UD_OSM'         UNION ALL  -- osmolalitas hitungan
  SELECT 'R_TATE'         UNION ALL
  SELECT 'MCV'            UNION ALL  -- MCV eritrosit URINE, bukan darah
  SELECT 'MCV_CV'
) x
JOIN (SELECT '^NM' AS akhiran UNION ALL SELECT '^ST' UNION ALL SELECT '') s
WHERE i.`kode` = 'URIN-02'
ON DUPLICATE KEY UPDATE `test_id` = NULL, `abaikan` = 1;

-- Ringkasan
SELECT m.`kode_alat`, t.`kode` AS kode_lis, t.`nama`, m.`abaikan`
FROM `instrument_test_map` m
JOIN `instruments` i ON i.`id` = m.`instrument_id`
LEFT JOIN `tests` t ON t.`id` = m.`test_id`
WHERE i.`kode` = 'URIN-02'
  AND (m.`kode_alat` LIKE 'UD\_%^NM' OR m.`kode_alat` LIKE 'UD\_%^ST'
       OR m.`kode_alat` LIKE '%\_Title^ST')
ORDER BY m.`abaikan`, m.`kode_alat`;
