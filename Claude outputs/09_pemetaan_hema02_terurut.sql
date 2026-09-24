-- Pemetaan HEMA-02 (instrument_id 8) — Mindray BC-5000
-- Diurutkan: parameter yang menghasilkan hasil dulu, sesuai urutan cetak
-- pada master (tests.urut), lalu kode yang sengaja diabaikan per kelompok.
--
-- Kolom `id` sengaja TIDAK disertakan. Angka id yang dipaku membuat berkas
-- ini bentrok bila dijalankan di database yang barisnya sudah ada; kunci
-- uniknya (instrument_id, kode_alat) sudah cukup menjaga keunikan.

INSERT INTO `instrument_test_map`
  (`instrument_id`,`kode_alat`,`test_id`,`faktor`,`offset_nilai`,`satuan_alat`,`abaikan`) VALUES
-- ===== Parameter hasil, urut sesuai lembar hasil =====
(8, '718-7',     1,    1.000000, 0.000000, 'g/dL',     0),   -- Hemoglobin
(8, '2345-7',    30,   1.000000, 0.000000, NULL,       0),   -- Glukosa Darah Sewaktu
(8, '4544-3',    2,    1.000000, 0.000000, '%',        0),   -- Hematokrit
(8, '789-8',     3,    1.000000, 0.000000, '10*12/L',  0),   -- Eritrosit
(8, '6690-2',    4,    1.000000, 0.000000, '10*9/L',   0),   -- Leukosit
(8, '777-3',     5,    1.000000, 0.000000, '10*9/L',   0),   -- Trombosit
(8, '787-2',     6,    1.000000, 0.000000, 'fL',       0),   -- MCV
(8, '785-6',     7,    1.000000, 0.000000, 'pg',       0),   -- MCH
(8, '786-4',     8,    1.000000, 0.000000, 'g/L',      0),   -- MCHC
(8, '788-0',     9,    1.000000, 0.000000, '%',        0),   -- RDW-CV
(8, '32623-1',   10,   1.000000, 0.000000, 'fL',       0),   -- MPV
(8, '770-8',     11,   1.000000, 0.000000, '%',        0),   -- Neutrofil
(8, '736-9',     12,   1.000000, 0.000000, '%',        0),   -- Limfosit
(8, '5905-5',    13,   1.000000, 0.000000, '%',        0),   -- Monosit
(8, '713-8',     14,   1.000000, 0.000000, '%',        0),   -- Eosinofil
(8, '706-2',     15,   1.000000, 0.000000, '%',        0),   -- Basofil

-- ===== Hitung jenis ABSOLUT — belum ada di master, lihat 08_pemetaan_hema_urin.sql =====
(8, '704-7',     NULL,  1.000000, 0.000000, NULL,        1),   -- BAS#
(8, '711-2',     NULL,  1.000000, 0.000000, NULL,        1),   -- EOS#
(8, '731-0',     NULL,  1.000000, 0.000000, NULL,        1),   -- LYM#
(8, '742-7',     NULL,  1.000000, 0.000000, NULL,        1),   -- MON#
(8, '751-8',     NULL,  1.000000, 0.000000, NULL,        1),   -- NEUT#

-- ===== Indeks tambahan — belum ada di master =====
(8, '10002',     NULL,  1.000000, 0.000000, NULL,        1),   -- PCT
(8, '21000-5',   NULL,  1.000000, 0.000000, NULL,        1),   -- RDW-SD
(8, '32207-3',   NULL,  1.000000, 0.000000, NULL,        1),   -- PDW

-- ===== Parameter riset / RUO — bukan hasil yang dilaporkan =====
(8, '10000',     NULL,  1.000000, 0.000000, NULL,        1),   -- *LIC#
(8, '10001',     NULL,  1.000000, 0.000000, NULL,        1),   -- *LIC%
(8, '10003',     NULL,  1.000000, 0.000000, NULL,        1),   -- GRAN-X
(8, '10004',     NULL,  1.000000, 0.000000, NULL,        1),   -- GRAN-Y
(8, '10005',     NULL,  1.000000, 0.000000, NULL,        1),   -- GRAN-Y(W)
(8, '10006',     NULL,  1.000000, 0.000000, NULL,        1),   -- WBC-MCV
(8, '10013',     NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '10014',     NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '10027',     NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '10028',     NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '10029',     NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '10030',     NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '13046-8',   NULL,  1.000000, 0.000000, NULL,        1),   -- *ALY%
(8, '15192-8',   NULL,  1.000000, 0.000000, NULL,        1),   -- RUO
(8, '26477-0',   NULL,  1.000000, 0.000000, NULL,        1),   -- *ALY#
(8, '34165-1',   NULL,  1.000000, 0.000000, NULL,        1),   -- Imm Granulocytes?

-- ===== Mode & informasi sampel — bukan hasil pemeriksaan =====
(8, '01001',     NULL,  1.000000, 0.000000, NULL,        1),   -- Info sampel
(8, '01002',     NULL,  1.000000, 0.000000, NULL,        1),   -- Ref Group
(8, '01006',     NULL,  1.000000, 0.000000, NULL,        1),   -- Info sampel
(8, '05001',     NULL,  1.000000, 0.000000, NULL,        1),   -- Info sampel
(8, '08001',     NULL,  1.000000, 0.000000, NULL,        1),   -- Take Mode
(8, '08002',     NULL,  1.000000, 0.000000, NULL,        1),   -- Blood Mode
(8, '08003',     NULL,  1.000000, 0.000000, NULL,        1),   -- Test Mode
(8, '30525-0',   NULL,  1.000000, 0.000000, NULL,        1),   -- Age

-- ===== Pesan interpretasi alat — teks, bukan nilai terukur =====
(8, '12002',     NULL,  1.000000, 0.000000, NULL,        1),   -- Leucocytosis
(8, '12011',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12013',     NULL,  1.000000, 0.000000, NULL,        1),   -- RBC Abnormal distribution
(8, '12014',     NULL,  1.000000, 0.000000, NULL,        1),   -- Anemia
(8, '12015',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12016',     NULL,  1.000000, 0.000000, NULL,        1),   -- PLT Abnormal Distribution
(8, '12045',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12046',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12048',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12049',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12050',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12051',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag
(8, '12052',     NULL,  1.000000, 0.000000, NULL,        1),   -- flag

-- ===== Histogram & scattergram — data biner =====
(8, '15000',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15001',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15002',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15003',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15004',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15005',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15006',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15007',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15008',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15009',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15010',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15011',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15012',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15013',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15050',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15051',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15052',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15053',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15054',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15055',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15057',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15100',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15111',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15112',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15113',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15114',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15115',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15117',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15200',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15201',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15202',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15203',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15204',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15205',     NULL,  1.000000, 0.000000, NULL,        1),
(8, '15206',     NULL,  1.000000, 0.000000, NULL,        1)
ON DUPLICATE KEY UPDATE
  `test_id`     = VALUES(`test_id`),
  `satuan_alat` = VALUES(`satuan_alat`),
  `abaikan`     = VALUES(`abaikan`);
