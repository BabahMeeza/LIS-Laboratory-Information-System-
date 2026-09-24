-- =====================================================================
--  URIN-02 (MediGo URO) — kode protokol V2.0
--
--  Pada protokol V2.0 alat mengirim barcode tabung di OBR-2:
--
--    OBR|1|PK202609210031C|187222|...
--          └ barcode        └ No. RM
--
--  Middleware sudah membaca OBR-2 sebagai sample ID, jadi hasil langsung
--  menempel ke order yang benar. Yang berbeda hanya NAMA KODE-nya:
--  V2.0 memakai awalan UC_ (UC_WBC, UC_KET, ...), bukan WBC/KET seperti
--  SA_V1.0. Berkas ini menambahkan pemetaan kode-kode itu.
--
--  Baris SA_V1.0 yang lama TIDAK diubah atau dihapus. Keduanya dapat
--  hidup berdampingan karena kodenya tidak bertabrakan.
--
--  UC_WBC di V2.0 adalah leukosit esterase dari CARIK CELUP (dipstik),
--  jadi dipetakan ke U_LEU, bukan ke sedimen U_SED_LEU.
--
--  Tiap kode ditulis dua kali: dengan akhiran tipe (UC_WBC^NM), karena
--  URIN-02 memakai bedakan_tipe_nilai = 1, dan tanpa akhiran (UC_WBC),
--  untuk berjaga bila setelan itu kelak dimatikan.
--
--  Aman dijalankan berulang kali.
-- =====================================================================

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, CONCAT(x.`kode_alat`, s.`akhiran`), t.`id`, NULL, 1, 0, 0
FROM `instruments` i
JOIN (
  SELECT 'UC_WBC' AS kode_alat, 'U_LEU' AS kode_lis UNION ALL
  SELECT 'UC_KET', 'U_KET'  UNION ALL
  SELECT 'UC_NIT', 'U_NIT'  UNION ALL
  SELECT 'UC_URO', 'U_URO'  UNION ALL
  SELECT 'UC_BIL', 'U_BIL'  UNION ALL
  SELECT 'UC_PRO', 'U_PROT' UNION ALL
  SELECT 'UC_GLU', 'U_GLU'  UNION ALL
  SELECT 'UC_SG',  'U_BJ'   UNION ALL
  SELECT 'UC_BLD', 'U_BLD'  UNION ALL
  SELECT 'UC_PH',  'U_PH'
) x
JOIN (SELECT '^NM' AS akhiran UNION ALL SELECT '^ST' UNION ALL SELECT '') s
JOIN `tests` t ON t.`kode` = x.`kode_lis`
WHERE i.`kode` = 'URIN-02'
ON DUPLICATE KEY UPDATE `test_id` = VALUES(`test_id`), `abaikan` = 0;

-- Diabaikan: judul blok dan vitamin C (pengganggu carik celup, bukan hasil).
INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`,`faktor`,`offset_nilai`,`abaikan`)
SELECT i.`id`, CONCAT(x.`kode_alat`, s.`akhiran`), NULL, NULL, 1, 0, 1
FROM `instruments` i
JOIN (SELECT 'UC_Title' AS kode_alat UNION ALL SELECT 'UC_VC') x
JOIN (SELECT '^NM' AS akhiran UNION ALL SELECT '^ST' UNION ALL SELECT '') s
WHERE i.`kode` = 'URIN-02'
ON DUPLICATE KEY UPDATE `test_id` = NULL, `abaikan` = 1;

-- Ringkasan
SELECT m.`kode_alat`, t.`kode` AS kode_lis, t.`nama`, m.`abaikan`
FROM `instrument_test_map` m
JOIN `instruments` i ON i.`id` = m.`instrument_id`
LEFT JOIN `tests` t ON t.`id` = m.`test_id`
WHERE i.`kode` = 'URIN-02' AND m.`kode_alat` LIKE 'UC\_%^NM'
ORDER BY m.`abaikan`, m.`kode_alat`;
