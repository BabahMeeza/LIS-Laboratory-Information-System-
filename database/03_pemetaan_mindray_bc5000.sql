-- =====================================================================
--  Pemetaan kode parameter — Mindray BC-5000 / BC-5150 (HL7 v2.3.1)
--
--  Sumber: "BC-5000 HL7 Communication Protocol", Z-110-002561-00-1.0,
--  bagian 4.7 "OBX-3 parameter type code".
--
--  MENGAPA BERKAS INI PENTING
--
--  BC-5000 mengirimkan identitas parameter pada OBX-3 dengan bentuk
--  "ID^Nama^EncodeSys", contoh:
--
--      OBX|11|NM|718-7^HGB^LN||64|g/L|130-175|L|||F
--                 ^^^^^
--
--  Yang dipakai untuk MENGENALI parameter adalah ID-nya (718-7, sebuah
--  kode LOINC), bukan namanya. Manual menyatakannya tegas:
--
--      "ID and EncodeSys are used to identify different analysis
--       parameters, while Name is for description purpose rather than
--       identification."
--
--  Karena itu kolom kode_alat di sini diisi ID, bukan singkatan seperti
--  "HGB". Memetakan berdasarkan nama akan gagal mencocokkan seluruh
--  parameter, dan setiap hasil berakhir di "Hasil Belum Terpetakan".
--
--  CARA PAKAI
--
--    1. Daftarkan alat BC-5000 Anda di menu Alat Laboratorium.
--    2. Catat id-nya, lalu jalankan:
--         mysql -u root -p db_lis -e "SET @alat := <id>;" \
--           && mysql -u root -p db_lis < 03_pemetaan_mindray_bc5000.sql
--       atau ubah nilai @alat pada baris di bawah.
--    3. Periksa hasilnya di Alat → Pemetaan Kode.
--
--  CATATAN SATUAN
--
--  BC-5000 memakai satuan SI: HGB dalam g/L, RBC dalam 10*12/L, PLT
--  dalam 10*9/L. Master LIS memakai satuan konvensional. LIS menyelaraskan
--  keduanya secara otomatis (lihat app/Services/UnitConverter.php), jadi
--  kolom faktor DIBIARKAN 1 — jangan diisi konversi manual, nanti terkonversi
--  dua kali.
-- =====================================================================

-- Ganti angka ini dengan id alat BC-5000 Anda.
SET @alat := 1;

-- ---------------------------------------------------------------------
-- Parameter hasil yang dipetakan ke pemeriksaan LIS
-- ---------------------------------------------------------------------

INSERT INTO `instrument_test_map`
  (`instrument_id`, `kode_alat`, `test_id`, `satuan_alat`, `faktor`, `offset_nilai`, `abaikan`)
SELECT @alat, m.kode_alat, t.id, m.satuan, 1, 0, 0
FROM (
  -- kode_alat  satuan SI yang dikirim BC-5000   pemeriksaan LIS   (nama alat)
  SELECT '6690-2'  AS kode_alat, '10*9/L'  AS satuan, 'WBC'   AS kode_lis UNION ALL  -- WBC
  SELECT '789-8',   '10*12/L', 'RBC'   UNION ALL  -- RBC
  SELECT '718-7',   'g/L',     'HB'    UNION ALL  -- HGB
  SELECT '4544-3',  '%',       'HT'    UNION ALL  -- HCT
  SELECT '787-2',   'fL',      'MCV'   UNION ALL  -- MCV
  SELECT '785-6',   'pg',      'MCH'   UNION ALL  -- MCH
  SELECT '786-4',   'g/L',     'MCHC'  UNION ALL  -- MCHC
  SELECT '788-0',   '%',       'RDW'   UNION ALL  -- RDW-CV
  SELECT '777-3',   '10*9/L',  'PLT'   UNION ALL  -- PLT
  SELECT '32623-1', 'fL',      'MPV'   UNION ALL  -- MPV
  SELECT '770-8',   '%',       'NEUT'  UNION ALL  -- NEU%
  SELECT '736-9',   '%',       'LYMPH' UNION ALL  -- LYM%
  SELECT '5905-5',  '%',       'MONO'  UNION ALL  -- MON%
  SELECT '713-8',   '%',       'EO'    UNION ALL  -- EOS%
  SELECT '706-2',   '%',       'BASO'             -- BAS%
) AS m
JOIN `tests` t ON t.kode = m.kode_lis
ON DUPLICATE KEY UPDATE
  `test_id`     = VALUES(`test_id`),
  `satuan_alat` = VALUES(`satuan_alat`),
  `abaikan`     = 0;

-- ---------------------------------------------------------------------
-- Parameter yang dikirim alat tetapi belum ada di master LIS
--
-- Ditandai "abaikan" agar tidak menumpuk sebagai hasil menggantung.
-- Bila kelak Anda menambahkan pemeriksaannya ke master, hapus tanda
-- abaikan lalu petakan lewat layar Pemetaan Kode.
-- ---------------------------------------------------------------------

INSERT INTO `instrument_test_map`
  (`instrument_id`, `kode_alat`, `test_id`, `faktor`, `offset_nilai`, `abaikan`)
VALUES
  (@alat, '751-8', NULL, 1, 0, 1),                   -- NEU# (absolut)
  (@alat, '731-0', NULL, 1, 0, 1),                   -- LYM# (absolut)
  (@alat, '742-7', NULL, 1, 0, 1),                   -- MON# (absolut)
  (@alat, '711-2', NULL, 1, 0, 1),                   -- EOS# (absolut)
  (@alat, '704-7', NULL, 1, 0, 1),                   -- BAS# (absolut)
  (@alat, '21000-5', NULL, 1, 0, 1),                 -- RDW-SD
  (@alat, '32207-3', NULL, 1, 0, 1),                 -- PDW
  (@alat, '10002', NULL, 1, 0, 1),                   -- PCT (plateletcrit)
  (@alat, '10013', NULL, 1, 0, 1),                   -- PLCC
  (@alat, '10014', NULL, 1, 0, 1),                   -- PLCR
  (@alat, '10027', NULL, 1, 0, 1),                   -- MID#
  (@alat, '10029', NULL, 1, 0, 1),                   -- MID%
  (@alat, '10028', NULL, 1, 0, 1),                   -- GRAN#
  (@alat, '10030', NULL, 1, 0, 1)                   -- GRAN%
ON DUPLICATE KEY UPDATE `abaikan` = 1;

-- ---------------------------------------------------------------------
-- Butir NON-PARAMETER
--
-- BC-5000 mengirimkan ini sebagai segmen OBX juga, padahal isinya bukan
-- hasil pemeriksaan: mode pengambilan, mode darah, catatan, umur, dan
-- data biner histogram/scattergram. Tanpa ditandai abaikan, semuanya
-- masuk ke "Hasil Belum Terpetakan" setiap kali satu sampel dijalankan —
-- layar itu penuh derau lalu berhenti diperiksa orang.
-- ---------------------------------------------------------------------

INSERT INTO `instrument_test_map`
  (`instrument_id`, `kode_alat`, `test_id`, `faktor`, `offset_nilai`, `abaikan`)
VALUES
  (@alat, '08001', NULL, 1, 0, 1),                   -- Take Mode
  (@alat, '08002', NULL, 1, 0, 1),                   -- Blood Mode
  (@alat, '08003', NULL, 1, 0, 1),                   -- Test Mode
  (@alat, '01001', NULL, 1, 0, 1),                   -- Remark
  (@alat, '01002', NULL, 1, 0, 1),                   -- Ref Group
  (@alat, '01006', NULL, 1, 0, 1),                   -- Recheck flag
  (@alat, '05001', NULL, 1, 0, 1),                   -- QC Level
  (@alat, '30525-0', NULL, 1, 0, 1),                 -- Age

  -- Penanda morfologi / kelainan
  (@alat, '12011', NULL, 1, 0, 1),                   -- WBC Abnormal
  (@alat, '12013', NULL, 1, 0, 1),                   -- RBC Abnormal distribution
  (@alat, '12014', NULL, 1, 0, 1),                   -- Anemia
  (@alat, '12015', NULL, 1, 0, 1),                   -- HGB Interfere
  (@alat, '12016', NULL, 1, 0, 1),                   -- PLT Abnormal Distribution
  (@alat, '12045', NULL, 1, 0, 1),                   -- Multiple alerts
  (@alat, '12046', NULL, 1, 0, 1),                   -- Lym left region alert
  (@alat, '12048', NULL, 1, 0, 1),                   -- Mid gran region alert
  (@alat, '12049', NULL, 1, 0, 1),                   -- Gran right region alert
  (@alat, '12050', NULL, 1, 0, 1),                   -- Plt rbc boundary blur
  (@alat, '12051', NULL, 1, 0, 1),                   -- Micro plt over aboundce
  (@alat, '12052', NULL, 1, 0, 1),                   -- Macro plt over aboundce
  (@alat, '34165-1', NULL, 1, 0, 1),                 -- Imm Granulocytes?
  (@alat, '15192-8', NULL, 1, 0, 1),                 -- Atypical Lymph?

  -- Histogram WBC
  (@alat, '15000', NULL, 1, 0, 1),                   -- WBC Histogram Binary
  (@alat, '15001', NULL, 1, 0, 1),                   -- WBC Hist Left Line
  (@alat, '15002', NULL, 1, 0, 1),                   -- WBC Hist Right Line
  (@alat, '15003', NULL, 1, 0, 1),                   -- WBC Hist Middle Line
  (@alat, '15004', NULL, 1, 0, 1),                   -- WBC Hist Meta Length
  (@alat, '15005', NULL, 1, 0, 1),                   -- WBC Hist Left Adj
  (@alat, '15006', NULL, 1, 0, 1),                   -- WBC Hist Right Adj
  (@alat, '15007', NULL, 1, 0, 1),                   -- WBC Hist Middle Adj
  (@alat, '15008', NULL, 1, 0, 1),                   -- WBC Histogram BMP
  (@alat, '15009', NULL, 1, 0, 1),                   -- WBC Histogram Total
  (@alat, '15010', NULL, 1, 0, 1),                   -- WBC Lym left line
  (@alat, '15011', NULL, 1, 0, 1),                   -- WBC Lym Mid line
  (@alat, '15012', NULL, 1, 0, 1),                   -- WBC Mid Gran line
  (@alat, '15013', NULL, 1, 0, 1),                   -- WBC Gran right line

  -- Histogram RBC
  (@alat, '15050', NULL, 1, 0, 1),                   -- RBC Histogram Binary
  (@alat, '15051', NULL, 1, 0, 1),                   -- RBC Hist Left Line
  (@alat, '15052', NULL, 1, 0, 1),                   -- RBC Hist Right Line
  (@alat, '15053', NULL, 1, 0, 1),                   -- RBC Hist Meta Length
  (@alat, '15054', NULL, 1, 0, 1),                   -- RBC Hist Left Adj
  (@alat, '15055', NULL, 1, 0, 1),                   -- RBC Hist Right Adj

  -- Histogram PLT
  (@alat, '15100', NULL, 1, 0, 1),                   -- PLT Histogram Binary
  (@alat, '15111', NULL, 1, 0, 1),                   -- PLT Hist Left Line
  (@alat, '15112', NULL, 1, 0, 1),                   -- PLT Hist Right Line
  (@alat, '15113', NULL, 1, 0, 1),                   -- PLT Hist Meta Length
  (@alat, '15114', NULL, 1, 0, 1),                   -- PLT Hist Left Adj
  (@alat, '15115', NULL, 1, 0, 1),                   -- PLT Hist Right Adj

  -- Scattergram DIFF
  (@alat, '15200', NULL, 1, 0, 1),                   -- DIFF Scattergram BMP
  (@alat, '15201', NULL, 1, 0, 1),                   -- DIFF Scattergram BIN
  (@alat, '15202', NULL, 1, 0, 1),                   -- DIFF Scattergram type
  (@alat, '15203', NULL, 1, 0, 1),                   -- DIFF Scattergram meta len
  (@alat, '15204', NULL, 1, 0, 1),                   -- DIFF Scattergram meta count

  -- Parameter khusus QC dan RUO
  (@alat, '10003', NULL, 1, 0, 1),                   -- GRAN-X
  (@alat, '10004', NULL, 1, 0, 1),                   -- GRAN-Y
  (@alat, '10005', NULL, 1, 0, 1),                   -- GRAN-Y(W)
  (@alat, '10006', NULL, 1, 0, 1),                   -- WBC-MCV
  (@alat, '26477-0', NULL, 1, 0, 1),                 -- *ALY# (RUO)
  (@alat, '13046-8', NULL, 1, 0, 1),                 -- *ALY% (RUO)
  (@alat, '10000', NULL, 1, 0, 1),                   -- *LIC# (RUO)
  (@alat, '10001', NULL, 1, 0, 1)                   -- *LIC% (RUO)
ON DUPLICATE KEY UPDATE `abaikan` = 1;

-- ---------------------------------------------------------------------
-- Ringkasan
-- ---------------------------------------------------------------------

SELECT
  SUM(test_id IS NOT NULL)                    AS dipetakan,
  SUM(test_id IS NULL AND abaikan = 1)        AS diabaikan,
  SUM(test_id IS NULL AND abaikan = 0)        AS perlu_ditinjau
FROM `instrument_test_map`
WHERE `instrument_id` = @alat;
