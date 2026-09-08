-- =====================================================================
--  LIS — Data master awal
--  Jalankan setelah 01_schema.sql
--  Berisi: kategori, jenis spesimen, ±60 pemeriksaan lazim RS Indonesia,
--          nilai rujukan (termasuk nilai kritis), paket, pengguna awal.
-- =====================================================================

USE `db_lis`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Kategori pemeriksaan
-- ---------------------------------------------------------------------
INSERT INTO `test_categories` (`id`,`kode`,`nama`,`urut`) VALUES
  (1,'HEMA','Hematologi',10),
  (2,'KIMIA','Kimia Klinik',20),
  (3,'URIN','Urinalisa',30),
  (4,'IMUNO','Imunoserologi',40),
  (5,'MIKRO','Mikrobiologi',50),
  (6,'FESES','Feses',60)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- ---------------------------------------------------------------------
-- Jenis spesimen
-- ---------------------------------------------------------------------
INSERT INTO `specimen_types` (`id`,`kode`,`nama`,`container`,`warna`,`volume_ml`) VALUES
  (1,'EDTA','Darah EDTA','Tabung EDTA K3','Ungu',3.00),
  (2,'SERUM','Serum','Tabung plain / clot activator','Merah',5.00),
  (3,'PLASMA','Plasma Heparin','Tabung Lithium Heparin','Hijau',4.00),
  (4,'CITRAT','Darah Sitrat','Tabung Na-Citrate 3.2%','Biru',2.70),
  (5,'NAF','Plasma NaF','Tabung Natrium Fluorida','Abu-abu',2.00),
  (6,'URINE','Urine','Pot urine steril','Bening',20.00),
  (7,'FESES','Feses','Pot feses','Bening',NULL),
  (8,'SPUTUM','Sputum','Pot sputum steril','Bening',NULL),
  (9,'SWAB','Swab','Tabung VTM','Bening',NULL)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- ---------------------------------------------------------------------
-- Pemeriksaan (tests)
--   tipe_hasil: numerik | teks | pilihan | narasi
-- ---------------------------------------------------------------------
INSERT INTO `tests`
 (`id`,`kode`,`nama`,`nama_singkat`,`category_id`,`specimen_type_id`,`loinc`,`satuan`,`metode`,`tipe_hasil`,`pilihan`,`desimal`,`harga`,`tat_menit`,`urut`,`is_kritis`) VALUES
-- ============ HEMATOLOGI ============
 (1,'HB','Hemoglobin','Hemoglobin',1,1,'718-7','g/dL','Cyanmethemoglobin / SLS','numerik',NULL,1,25000,60,10,1),
 (2,'HT','Hematokrit','Hematokrit',1,1,'4544-3','%','Kalkulasi','numerik',NULL,1,20000,60,20,1),
 (3,'RBC','Eritrosit','Eritrosit',1,1,'789-8','10^6/uL','Impedansi','numerik',NULL,2,20000,60,30,0),
 (4,'WBC','Leukosit','Leukosit',1,1,'6690-2','10^3/uL','Impedansi','numerik',NULL,2,20000,60,40,1),
 (5,'PLT','Trombosit','Trombosit',1,1,'777-3','10^3/uL','Impedansi','numerik',NULL,0,20000,60,50,1),
 (6,'MCV','MCV','MCV',1,1,'787-2','fL','Kalkulasi','numerik',NULL,1,10000,60,60,0),
 (7,'MCH','MCH','MCH',1,1,'785-6','pg','Kalkulasi','numerik',NULL,1,10000,60,70,0),
 (8,'MCHC','MCHC','MCHC',1,1,'786-4','g/dL','Kalkulasi','numerik',NULL,1,10000,60,80,0),
 (9,'RDW','RDW-CV','RDW-CV',1,1,'788-0','%','Kalkulasi','numerik',NULL,1,10000,60,90,0),
 (10,'MPV','MPV','MPV',1,1,'32623-1','fL','Kalkulasi','numerik',NULL,1,10000,60,100,0),
 (11,'NEUT','Neutrofil','Neutrofil',1,1,'770-8','%','Flow cytometry','numerik',NULL,1,12000,60,110,0),
 (12,'LYMPH','Limfosit','Limfosit',1,1,'736-9','%','Flow cytometry','numerik',NULL,1,12000,60,120,0),
 (13,'MONO','Monosit','Monosit',1,1,'5905-5','%','Flow cytometry','numerik',NULL,1,12000,60,130,0),
 (14,'EO','Eosinofil','Eosinofil',1,1,'713-8','%','Flow cytometry','numerik',NULL,1,12000,60,140,0),
 (15,'BASO','Basofil','Basofil',1,1,'706-2','%','Flow cytometry','numerik',NULL,1,12000,60,150,0),
 (16,'NLR','Rasio Neutrofil-Limfosit','NLR',1,1,NULL,'','Kalkulasi','numerik',NULL,2,0,60,155,0),
 (17,'LED','Laju Endap Darah','LED 1 jam',1,1,'4537-7','mm/jam','Westergren','numerik',NULL,0,25000,90,160,0),
 (18,'PT','Protrombin Time','PT',1,4,'5902-2','detik','Koagulometri','numerik',NULL,1,85000,120,170,1),
 (19,'APTT','APTT','APTT',1,4,'14979-9','detik','Koagulometri','numerik',NULL,1,90000,120,180,1),
 (20,'INR','INR','INR',1,4,'6301-6','','Kalkulasi','numerik',NULL,2,0,120,190,1),
 (21,'RETIC','Retikulosit','Retikulosit',1,1,'17849-1','%','Mikroskopis','numerik',NULL,2,45000,120,200,0),
-- ============ KIMIA KLINIK ============
 (30,'GDS','Glukosa Darah Sewaktu','GDS',2,5,'2345-7','mg/dL','GOD-PAP','numerik',NULL,0,25000,60,10,1),
 (31,'GDP','Glukosa Darah Puasa','GDP',2,5,'1558-6','mg/dL','GOD-PAP','numerik',NULL,0,25000,60,20,1),
 (32,'GD2PP','Glukosa 2 Jam PP','GD 2 Jam PP',2,5,'1521-4','mg/dL','GOD-PAP','numerik',NULL,0,25000,60,30,1),
 (33,'HBA1C','HbA1c','HbA1c',2,1,'4548-4','%','HPLC / Imunoturbidimetri','numerik',NULL,1,180000,240,40,0),
 (34,'UREUM','Ureum','Ureum',2,2,'22664-7','mg/dL','Urease-GLDH','numerik',NULL,1,35000,90,50,1),
 (35,'KREAT','Kreatinin','Kreatinin',2,2,'2160-0','mg/dL','Jaffe kinetik','numerik',NULL,2,35000,90,60,1),
 (36,'EGFR','eGFR (CKD-EPI)','eGFR',2,2,'62238-1','mL/min/1.73m2','Kalkulasi','numerik',NULL,1,0,90,65,0),
 (37,'UA','Asam Urat','Asam Urat',2,2,'3084-1','mg/dL','Uricase-PAP','numerik',NULL,1,35000,90,70,0),
 (38,'SGOT','SGOT (AST)','SGOT',2,2,'1920-8','U/L','IFCC tanpa P5P','numerik',NULL,0,40000,90,80,0),
 (39,'SGPT','SGPT (ALT)','SGPT',2,2,'1742-6','U/L','IFCC tanpa P5P','numerik',NULL,0,40000,90,90,0),
 (40,'ALP','Alkali Fosfatase','ALP',2,2,'6768-6','U/L','IFCC','numerik',NULL,0,45000,90,100,0),
 (41,'GGT','Gamma GT','Gamma GT',2,2,'2324-2','U/L','IFCC','numerik',NULL,0,45000,90,110,0),
 (42,'BILT','Bilirubin Total','Bilirubin Total',2,2,'1975-2','mg/dL','Diazo','numerik',NULL,2,35000,90,120,1),
 (43,'BILD','Bilirubin Direk','Bilirubin Direk',2,2,'1968-7','mg/dL','Diazo','numerik',NULL,2,35000,90,130,0),
 (44,'BILI','Bilirubin Indirek','Bilirubin Indirek',2,2,'1971-1','mg/dL','Kalkulasi','numerik',NULL,2,0,90,140,0),
 (45,'ALB','Albumin','Albumin',2,2,'1751-7','g/dL','Bromcresol Green','numerik',NULL,2,40000,90,150,1),
 (46,'TP','Protein Total','Protein Total',2,2,'2885-2','g/dL','Biuret','numerik',NULL,2,35000,90,160,0),
 (47,'GLOB','Globulin','Globulin',2,2,'2336-6','g/dL','Kalkulasi','numerik',NULL,2,0,90,170,0),
 (48,'CHOL','Kolesterol Total','Kolesterol Total',2,2,'2093-3','mg/dL','CHOD-PAP','numerik',NULL,0,45000,90,180,0),
 (49,'HDL','HDL Kolesterol','HDL',2,2,'2085-9','mg/dL','Direct','numerik',NULL,0,50000,90,190,0),
 (50,'LDL','LDL Kolesterol','LDL',2,2,'2089-1','mg/dL','Direct / Friedewald','numerik',NULL,0,50000,90,200,0),
 (51,'TG','Trigliserida','Trigliserida',2,2,'2571-8','mg/dL','GPO-PAP','numerik',NULL,0,45000,90,210,0),
 (52,'NA','Natrium','Natrium',2,3,'2951-2','mmol/L','ISE','numerik',NULL,0,60000,60,220,1),
 (53,'K','Kalium','Kalium',2,3,'2823-3','mmol/L','ISE','numerik',NULL,1,60000,60,230,1),
 (54,'CL','Klorida','Klorida',2,3,'2075-0','mmol/L','ISE','numerik',NULL,0,60000,60,240,1),
 (55,'CA','Kalsium','Kalsium',2,2,'17861-6','mg/dL','Arsenazo III','numerik',NULL,2,55000,90,250,1),
 (56,'MG','Magnesium','Magnesium',2,2,'2601-3','mg/dL','Xylidyl Blue','numerik',NULL,2,60000,90,260,0),
 (57,'CKMB','CK-MB','CK-MB',2,2,'13969-1','U/L','Imunoinhibisi','numerik',NULL,0,120000,60,270,0),
 (58,'TROPI','Troponin I','Troponin I',2,2,'10839-9','ng/mL','Imunofluoresens','numerik',NULL,3,250000,45,280,1),
 (59,'CRP','CRP Kuantitatif','CRP',2,2,'1988-5','mg/L','Imunoturbidimetri','numerik',NULL,1,110000,90,290,0),
 (60,'PROCAL','Procalcitonin','PCT',2,2,'33959-8','ng/mL','Imunofluoresens','numerik',NULL,2,450000,90,300,1),
 (61,'DDIMER','D-Dimer','D-Dimer',2,4,'48065-7','ug/mL FEU','Imunoturbidimetri','numerik',NULL,2,350000,90,310,0),
-- ============ URINALISA ============
 (70,'U_WARNA','Urine - Warna','Warna',3,6,'5778-6',NULL,'Makroskopis','pilihan','Kuning muda,Kuning,Kuning tua,Jernih,Keruh,Merah,Coklat',0,0,45,10,0),
 (71,'U_KEJERNIHAN','Urine - Kejernihan','Kejernihan',3,6,NULL,NULL,'Makroskopis','pilihan','Jernih,Agak keruh,Keruh',0,0,45,20,0),
 (72,'U_BJ','Urine - Berat Jenis','BJ',3,6,'5811-5',NULL,'Carik celup','numerik',NULL,3,0,45,30,0),
 (73,'U_PH','Urine - pH','pH',3,6,'5803-2',NULL,'Carik celup','numerik',NULL,1,0,45,40,0),
 (74,'U_PROT','Urine - Protein','Protein',3,6,'5804-0',NULL,'Carik celup','pilihan','Negatif,Trace,1+,2+,3+,4+',0,0,45,50,0),
 (75,'U_GLU','Urine - Glukosa','Glukosa',3,6,'5792-7',NULL,'Carik celup','pilihan','Negatif,Trace,1+,2+,3+,4+',0,0,45,60,0),
 (76,'U_KET','Urine - Keton','Keton',3,6,'5797-6',NULL,'Carik celup','pilihan','Negatif,Trace,1+,2+,3+',0,0,45,70,0),
 (77,'U_BLD','Urine - Darah Samar','Blood',3,6,'5794-3',NULL,'Carik celup','pilihan','Negatif,Trace,1+,2+,3+',0,0,45,80,0),
 (78,'U_BIL','Urine - Bilirubin','Bilirubin',3,6,'5770-3',NULL,'Carik celup','pilihan','Negatif,1+,2+,3+',0,0,45,90,0),
 (79,'U_URO','Urine - Urobilinogen','Urobilinogen',3,6,'19161-9','mg/dL','Carik celup','pilihan','Normal,1+,2+,3+,4+',0,0,45,100,0),
 (80,'U_NIT','Urine - Nitrit','Nitrit',3,6,'5802-4',NULL,'Carik celup','pilihan','Negatif,Positif',0,0,45,110,0),
 (81,'U_LEU','Urine - Leukosit Esterase','Leukosit Esterase',3,6,'5799-2',NULL,'Carik celup','pilihan','Negatif,Trace,1+,2+,3+',0,0,45,120,0),
 (82,'U_SED_ERI','Sedimen - Eritrosit','Eritrosit sedimen',3,6,'13945-1','/LPB','Mikroskopis','teks',NULL,0,0,45,130,0),
 (83,'U_SED_LEU','Sedimen - Leukosit','Leukosit sedimen',3,6,'5821-4','/LPB','Mikroskopis','teks',NULL,0,0,45,140,0),
 (84,'U_SED_EPI','Sedimen - Epitel','Epitel',3,6,NULL,'/LPK','Mikroskopis','teks',NULL,0,0,45,150,0),
 (85,'U_SED_KRIS','Sedimen - Kristal','Kristal',3,6,NULL,NULL,'Mikroskopis','teks',NULL,0,0,45,160,0),
 (86,'U_SED_SIL','Sedimen - Silinder','Silinder',3,6,NULL,'/LPK','Mikroskopis','teks',NULL,0,0,45,170,0),
 (87,'U_SED_BAKT','Sedimen - Bakteri','Bakteri',3,6,NULL,NULL,'Mikroskopis','pilihan','Negatif,+,++,+++',0,0,45,180,0),
-- ============ IMUNOSEROLOGI ============
 (100,'GOLDA','Golongan Darah ABO','Gol. Darah',4,1,'883-9',NULL,'Slide / Gel','pilihan','A,B,AB,O',0,30000,45,10,0),
 (101,'RHESUS','Rhesus','Rhesus',4,1,'10331-7',NULL,'Slide / Gel','pilihan','Positif,Negatif',0,15000,45,20,0),
 (102,'HBSAG','HBsAg','HBsAg',4,2,'5196-1',NULL,'Rapid / ELISA','pilihan','Non Reaktif,Reaktif',0,90000,120,30,0),
 (103,'ANTIHBS','Anti-HBs','Anti-HBs',4,2,'16935-9','mIU/mL','ELISA','numerik',NULL,1,120000,180,40,0),
 (104,'ANTIHCV','Anti-HCV','Anti-HCV',4,2,'16128-1',NULL,'Rapid / ELISA','pilihan','Non Reaktif,Reaktif',0,140000,180,50,0),
 (105,'ANTIHIV','Anti-HIV (3 metode)','Anti-HIV',4,2,'75622-1',NULL,'Rapid 3 reagen','pilihan','Non Reaktif,Reaktif,Indeterminate',0,150000,180,60,0),
 (106,'SIFILIS','TPHA / Sifilis','TPHA',4,2,'22462-6',NULL,'Rapid','pilihan','Non Reaktif,Reaktif',0,90000,120,70,0),
 (107,'WIDAL_TO','Widal S. typhi O','Widal S.typhi O',4,2,NULL,NULL,'Aglutinasi slide','pilihan','Negatif,1/80,1/160,1/320,1/640',0,0,120,80,0),
 (108,'WIDAL_TH','Widal S. typhi H','Widal S.typhi H',4,2,NULL,NULL,'Aglutinasi slide','pilihan','Negatif,1/80,1/160,1/320,1/640',0,0,120,90,0),
 (109,'DENGUE_NS1','Dengue NS1','NS1',4,2,'75695-7',NULL,'Rapid','pilihan','Negatif,Positif',0,150000,60,100,0),
 (110,'DENGUE_IGG','Dengue IgG','Dengue IgG',4,2,'25400-3',NULL,'Rapid','pilihan','Negatif,Positif',0,0,60,110,0),
 (111,'DENGUE_IGM','Dengue IgM','Dengue IgM',4,2,'25338-5',NULL,'Rapid','pilihan','Negatif,Positif',0,0,60,120,0),
 (112,'HCG','Tes Kehamilan (HCG)','Plano Test',4,6,'2106-3',NULL,'Rapid urine','pilihan','Negatif,Positif',0,35000,30,130,0),
 (113,'TSH','TSH','TSH',4,2,'3016-3','uIU/mL','CLIA','numerik',NULL,3,180000,240,140,0),
 (114,'FT4','Free T4','FT4',4,2,'3024-7','ng/dL','CLIA','numerik',NULL,2,200000,240,150,0),
-- ============ MIKROBIOLOGI & FESES ============
 (130,'BTA','BTA Sputum (Ziehl-Neelsen)','BTA',5,8,'11545-1',NULL,'Ziehl-Neelsen','pilihan','Negatif,Scanty,1+,2+,3+',0,50000,240,10,0),
 (131,'GRAM','Pewarnaan Gram','Gram',5,9,'664-3',NULL,'Mikroskopis','narasi',NULL,0,60000,240,20,0),
 (132,'KULTUR','Kultur & Resistensi','Kultur',5,9,'600-7',NULL,'Kultur agar','narasi',NULL,0,350000,4320,30,0),
 (140,'F_MAKRO','Feses - Makroskopis','Makroskopis',6,7,NULL,NULL,'Makroskopis','narasi',NULL,0,0,60,10,0),
 (141,'F_DARAH','Feses - Darah Samar','Darah Samar',6,7,'2335-8',NULL,'Rapid FOB','pilihan','Negatif,Positif',0,60000,60,20,0),
 (142,'F_TELUR','Feses - Telur Cacing','Telur Cacing',6,7,NULL,NULL,'Mikroskopis','teks',NULL,0,0,60,30,0)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- ---------------------------------------------------------------------
-- Nilai rujukan
--   jk: L / P / A(semua).  Umur dalam HARI (1 th = 365).
--   critical_low / critical_high = ambang nilai kritis (wajib lapor).
-- ---------------------------------------------------------------------
INSERT INTO `reference_ranges`
 (`test_id`,`jk`,`umur_min_hari`,`umur_max_hari`,`low`,`high`,`critical_low`,`critical_high`,`teks_rujukan`) VALUES
-- Hemoglobin
 (1,'L',5475,43800,13.2,17.3,7.0,20.0,'13.2 - 17.3'),
 (1,'P',5475,43800,11.7,15.5,7.0,20.0,'11.7 - 15.5'),
 (1,'A',0,28,14.9,23.7,8.0,24.0,'14.9 - 23.7'),
 (1,'A',29,5474,11.0,14.5,7.0,20.0,'11.0 - 14.5'),
-- Hematokrit
 (2,'L',5475,43800,40.0,52.0,21.0,60.0,'40 - 52'),
 (2,'P',5475,43800,35.0,47.0,21.0,60.0,'35 - 47'),
 (2,'A',0,5474,33.0,45.0,21.0,60.0,'33 - 45'),
-- Eritrosit
 (3,'L',5475,43800,4.40,5.90,NULL,NULL,'4.40 - 5.90'),
 (3,'P',5475,43800,3.80,5.20,NULL,NULL,'3.80 - 5.20'),
 (3,'A',0,5474,3.80,5.50,NULL,NULL,'3.80 - 5.50'),
-- Leukosit
 (4,'A',5475,43800,4.00,10.00,2.00,30.00,'4.0 - 10.0'),
 (4,'A',0,5474,5.00,17.00,2.00,30.00,'5.0 - 17.0'),
-- Trombosit
 (5,'A',0,43800,150,450,50,1000,'150 - 450'),
-- Indeks eritrosit
 (6,'A',0,43800,80.0,100.0,NULL,NULL,'80 - 100'),
 (7,'A',0,43800,26.0,34.0,NULL,NULL,'26 - 34'),
 (8,'A',0,43800,32.0,36.0,NULL,NULL,'32 - 36'),
 (9,'A',0,43800,11.5,14.5,NULL,NULL,'11.5 - 14.5'),
 (10,'A',0,43800,6.5,11.0,NULL,NULL,'6.5 - 11.0'),
-- Hitung jenis
 (11,'A',0,43800,50.0,70.0,NULL,NULL,'50 - 70'),
 (12,'A',0,43800,20.0,40.0,NULL,NULL,'20 - 40'),
 (13,'A',0,43800,2.0,8.0,NULL,NULL,'2 - 8'),
 (14,'A',0,43800,1.0,3.0,NULL,NULL,'1 - 3'),
 (15,'A',0,43800,0.0,1.0,NULL,NULL,'0 - 1'),
 (16,'A',0,43800,0.78,3.53,NULL,NULL,'< 3.53'),
-- LED
 (17,'L',0,43800,0,15,NULL,NULL,'0 - 15'),
 (17,'P',0,43800,0,20,NULL,NULL,'0 - 20'),
-- Koagulasi
 (18,'A',0,43800,10.0,14.0,NULL,30.0,'10.0 - 14.0'),
 (19,'A',0,43800,25.0,35.0,NULL,80.0,'25.0 - 35.0'),
 (20,'A',0,43800,0.80,1.20,NULL,5.00,'0.8 - 1.2'),
 (21,'A',0,43800,0.50,2.50,NULL,NULL,'0.5 - 2.5'),
-- Glukosa
 (30,'A',0,43800,70,140,45,500,'70 - 140'),
 (31,'A',0,43800,70,100,45,500,'70 - 100'),
 (32,'A',0,43800,70,140,45,500,'< 140'),
 (33,'A',0,43800,4.0,5.7,NULL,NULL,'< 5.7'),
-- Ginjal
 (34,'A',0,43800,10.0,50.0,NULL,214.0,'10 - 50'),
 (35,'L',5475,43800,0.70,1.20,NULL,7.40,'0.70 - 1.20'),
 (35,'P',5475,43800,0.50,0.90,NULL,7.40,'0.50 - 0.90'),
 (35,'A',0,5474,0.30,0.70,NULL,7.40,'0.30 - 0.70'),
 (36,'A',0,43800,90.0,NULL,NULL,NULL,'>= 90'),
 (37,'L',0,43800,3.4,7.0,NULL,NULL,'3.4 - 7.0'),
 (37,'P',0,43800,2.4,5.7,NULL,NULL,'2.4 - 5.7'),
-- Hati
 (38,'L',0,43800,0,40,NULL,NULL,'< 40'),
 (38,'P',0,43800,0,32,NULL,NULL,'< 32'),
 (39,'L',0,43800,0,41,NULL,NULL,'< 41'),
 (39,'P',0,43800,0,33,NULL,NULL,'< 33'),
 (40,'A',0,43800,40,129,NULL,NULL,'40 - 129'),
 (41,'L',0,43800,0,55,NULL,NULL,'< 55'),
 (41,'P',0,43800,0,38,NULL,NULL,'< 38'),
 (42,'A',29,43800,0.20,1.20,NULL,15.00,'0.20 - 1.20'),
 (42,'A',0,28,1.00,12.00,NULL,15.00,'1.0 - 12.0'),
 (43,'A',0,43800,0.00,0.30,NULL,NULL,'< 0.30'),
 (44,'A',0,43800,0.00,0.90,NULL,NULL,'< 0.90'),
 (45,'A',0,43800,3.50,5.20,1.50,NULL,'3.5 - 5.2'),
 (46,'A',0,43800,6.60,8.70,NULL,NULL,'6.6 - 8.7'),
 (47,'A',0,43800,2.00,3.50,NULL,NULL,'2.0 - 3.5'),
-- Lipid
 (48,'A',0,43800,0,200,NULL,NULL,'< 200'),
 (49,'L',0,43800,40,NULL,NULL,NULL,'> 40'),
 (49,'P',0,43800,50,NULL,NULL,NULL,'> 50'),
 (50,'A',0,43800,0,100,NULL,NULL,'< 100'),
 (51,'A',0,43800,0,150,NULL,1000,'< 150'),
-- Elektrolit
 (52,'A',0,43800,136,145,120,160,'136 - 145'),
 (53,'A',0,43800,3.5,5.1,2.5,6.5,'3.5 - 5.1'),
 (54,'A',0,43800,98,107,80,120,'98 - 107'),
 (55,'A',0,43800,8.60,10.30,6.00,13.00,'8.6 - 10.3'),
 (56,'A',0,43800,1.70,2.55,1.00,4.90,'1.7 - 2.55'),
-- Jantung & inflamasi
 (57,'A',0,43800,0,25,NULL,NULL,'< 25'),
 (58,'A',0,43800,0.000,0.040,NULL,0.500,'< 0.04'),
 (59,'A',0,43800,0.0,5.0,NULL,NULL,'< 5.0'),
 (60,'A',0,43800,0.00,0.05,NULL,2.00,'< 0.05'),
 (61,'A',0,43800,0.00,0.50,NULL,NULL,'< 0.50'),
-- Urinalisa numerik
 (72,'A',0,43800,1.005,1.030,NULL,NULL,'1.005 - 1.030'),
 (73,'A',0,43800,4.5,8.0,NULL,NULL,'4.5 - 8.0'),
-- Tiroid
 (113,'A',0,43800,0.270,4.200,NULL,NULL,'0.27 - 4.20'),
 (114,'A',0,43800,0.93,1.70,NULL,NULL,'0.93 - 1.70'),
 (103,'A',0,43800,10.0,NULL,NULL,NULL,'> 10 (protektif)');

-- Nilai normal untuk pemeriksaan kualitatif (dipakai untuk menandai abnormal)
INSERT INTO `reference_ranges` (`test_id`,`jk`,`umur_min_hari`,`umur_max_hari`,`nilai_normal_teks`,`teks_rujukan`) VALUES
 (74,'A',0,43800,'Negatif','Negatif'),
 (75,'A',0,43800,'Negatif','Negatif'),
 (76,'A',0,43800,'Negatif','Negatif'),
 (77,'A',0,43800,'Negatif','Negatif'),
 (78,'A',0,43800,'Negatif','Negatif'),
 (79,'A',0,43800,'Normal','Normal'),
 (80,'A',0,43800,'Negatif','Negatif'),
 (81,'A',0,43800,'Negatif','Negatif'),
 (87,'A',0,43800,'Negatif','Negatif'),
 (102,'A',0,43800,'Non Reaktif','Non Reaktif'),
 (104,'A',0,43800,'Non Reaktif','Non Reaktif'),
 (105,'A',0,43800,'Non Reaktif','Non Reaktif'),
 (106,'A',0,43800,'Non Reaktif','Non Reaktif'),
 (107,'A',0,43800,'Negatif','Negatif'),
 (108,'A',0,43800,'Negatif','Negatif'),
 (109,'A',0,43800,'Negatif','Negatif'),
 (110,'A',0,43800,'Negatif','Negatif'),
 (111,'A',0,43800,'Negatif','Negatif'),
 (112,'A',0,43800,'Negatif','Negatif'),
 (130,'A',0,43800,'Negatif','Negatif'),
 (141,'A',0,43800,'Negatif','Negatif');

-- ---------------------------------------------------------------------
-- Paket pemeriksaan
-- ---------------------------------------------------------------------
INSERT INTO `test_panels` (`id`,`kode`,`nama`,`category_id`,`harga`) VALUES
 (1,'DL','Darah Lengkap (CBC)',1,0),
 (2,'DR','Darah Rutin',1,0),
 (3,'HITJEN','Hitung Jenis Leukosit',1,0),
 (4,'KOAG','Panel Koagulasi',1,0),
 (5,'LIPID','Profil Lipid',2,0),
 (6,'FAAL_HATI','Faal Hati',2,0),
 (7,'FAAL_GINJAL','Faal Ginjal',2,0),
 (8,'ELEKTROLIT','Elektrolit',2,0),
 (9,'UL','Urine Lengkap',3,0),
 (10,'HEPATITIS','Panel Hepatitis',4,0),
 (11,'DENGUE','Panel Dengue',4,0),
 (12,'PRAOP','Panel Pra-Operasi',2,0)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

INSERT INTO `test_panel_items` (`panel_id`,`test_id`,`urut`) VALUES
 (1,1,1),(1,2,2),(1,3,3),(1,4,4),(1,5,5),(1,6,6),(1,7,7),(1,8,8),(1,9,9),(1,10,10),
 (1,11,11),(1,12,12),(1,13,13),(1,14,14),(1,15,15),
 (2,1,1),(2,2,2),(2,4,3),(2,5,4),
 (3,11,1),(3,12,2),(3,13,3),(3,14,4),(3,15,5),(3,16,6),
 (4,18,1),(4,19,2),(4,20,3),
 (5,48,1),(5,49,2),(5,50,3),(5,51,4),
 (6,38,1),(6,39,2),(6,40,3),(6,41,4),(6,42,5),(6,43,6),(6,44,7),(6,45,8),(6,46,9),(6,47,10),
 (7,34,1),(7,35,2),(7,36,3),(7,37,4),
 (8,52,1),(8,53,2),(8,54,3),
 (9,70,1),(9,71,2),(9,72,3),(9,73,4),(9,74,5),(9,75,6),(9,76,7),(9,77,8),(9,78,9),(9,79,10),
 (9,80,11),(9,81,12),(9,82,13),(9,83,14),(9,84,15),(9,85,16),(9,86,17),(9,87,18),
 (10,102,1),(10,103,2),(10,104,3),
 (11,109,1),(11,110,2),(11,111,3),(11,1,4),(11,5,5),(11,2,6),
 (12,1,1),(12,4,2),(12,5,3),(12,30,4),(12,34,5),(12,35,6),(12,18,7),(12,19,8),(12,102,9)
ON DUPLICATE KEY UPDATE `urut`=VALUES(`urut`);

-- ---------------------------------------------------------------------
-- Pengguna awal
--   Password default semua akun: Lis#2026  — WAJIB diganti setelah login.
--   Hash di bawah dihasilkan dengan password_hash('Lis#2026', PASSWORD_BCRYPT).
-- ---------------------------------------------------------------------
INSERT INTO `users` (`id`,`username`,`password_hash`,`nama`,`nip`,`role`,`gelar`,`aktif`) VALUES
 (1,'admin','$2y$12$cxBhPI1/XPYfejulEUtTR..W0NzBnDD1kioSA14.xpXXeI/54RcxW','Administrator Sistem','ADM001','admin',NULL,1),
 (2,'dokter','$2y$12$cxBhPI1/XPYfejulEUtTR..W0NzBnDD1kioSA14.xpXXeI/54RcxW','Dokter Penanggung Jawab','DPJ001','verifikator','dr., Sp.PK',1),
 (3,'analis','$2y$12$cxBhPI1/XPYfejulEUtTR..W0NzBnDD1kioSA14.xpXXeI/54RcxW','Analis Laboratorium','ANL001','analis','A.Md.AK',1),
 (4,'sampling','$2y$12$cxBhPI1/XPYfejulEUtTR..W0NzBnDD1kioSA14.xpXXeI/54RcxW','Petugas Sampling','SMP001','sampling',NULL,1)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- ---------------------------------------------------------------------
-- Pengaturan sistem
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`key`,`value`,`grup`,`keterangan`) VALUES
 ('app.nama_lab','Laboratorium Klinik','identitas','Nama unit laboratorium pada kop hasil'),
 ('app.nama_faskes','RSUD Contoh','identitas','Nama rumah sakit / faskes'),
 ('app.alamat','Jl. Kesehatan No. 1, Pontianak','identitas','Alamat pada kop hasil'),
 ('app.telepon','(0561) 000000','identitas','Telepon pada kop hasil'),
 ('app.logo','','identitas','Path logo relatif terhadap public/'),
 ('app.timezone','Asia/Pontianak','umum','Zona waktu aplikasi'),
 ('barcode.prefix','','barcode','Awalan barcode spesimen (opsional)'),
 ('barcode.format','YMD-SEQ','barcode','YMD-SEQ | SEQ | ORDER'),
 ('barcode.panjang_seq','4','barcode','Jumlah digit urutan harian'),
 ('order.prefix','LIS','penomoran','Awalan nomor order'),
 ('nolab.reset','harian','penomoran','harian | bulanan | tahunan'),
 ('hasil.auto_verify','0','validasi','1 = izinkan autovalidasi dari alat'),
 ('hasil.auto_verify_user','','validasi','ID pengguna (verifikator/admin) yang bertanggung jawab atas hasil autovalidasi. Wajib diisi bila autovalidasi diaktifkan.'),
 ('hasil.delta_check_hari','7','validasi','Rentang hari pencarian hasil sebelumnya untuk delta check'),
 ('hasil.delta_ambang_persen','30','validasi','Ambang perubahan (%) yang memicu peringatan delta check'),
 ('khanza.aktif','0','khanza','1 = integrasi Khanza aktif'),
 ('khanza.base_url','http://localhost/khanza-connector','khanza','URL dasar konektor di server Khanza'),
 ('khanza.api_key','','khanza','API key konektor Khanza'),
 ('khanza.api_secret','','khanza','Secret HMAC konektor Khanza'),
 ('khanza.mode_order','push','khanza','push = Khanza kirim ke LIS; pull = LIS tarik berkala'),
 ('khanza.kirim_hasil_saat','verifikasi','khanza','verifikasi | rilis — kapan hasil dikirim balik ke Khanza'),
 ('khanza.auto_buat_pasien','1','khanza','Buat pasien baru otomatis dari data order Khanza'),
 ('middleware.heartbeat_detik','30','alat','Interval heartbeat middleware alat'),
 ('middleware.simpan_raw_hari','90','alat','Retensi log mentah komunikasi alat (hari)')
ON DUPLICATE KEY UPDATE `keterangan`=VALUES(`keterangan`);

-- ---------------------------------------------------------------------
-- Kredensial API awal
--   API key & secret di bawah HANYA untuk instalasi awal.
--   Ganti lewat menu Pengaturan > Kredensial API sebelum dipakai produksi.
--
--   PENTING: kredensial bawaan ini tidak memiliki secret_enc, sehingga
--   verifikasi tanda tangan HMAC (header X-Signature) belum dapat dipakai.
--   Autentikasi X-API-Key tetap berjalan. Untuk mengaktifkan HMAC, isi
--   security.app_key di config/config.php lalu buat kredensial baru dari
--   menu Pengaturan > Kredensial API.
--   secret plaintext middleware  : lis_secret_ubah_saya_middleware
--   secret plaintext khanza      : lis_secret_ubah_saya_khanza
-- ---------------------------------------------------------------------
INSERT INTO `api_clients` (`id`,`nama`,`api_key`,`secret_hash`,`scopes`,`aktif`) VALUES
 (1,'Middleware Alat Laboratorium','lis_mw_0000000000000000000000000000',
    '$2y$12$iJTGCzL1sR3jq9Foi9Mik.sG2sCUN9QsyHLYpYdni8T3SrlStRWqm','instrument',1),
 (2,'Konektor SIMRS Khanza','lis_kz_0000000000000000000000000000',
    '$2y$12$Y1TLvW7cpl8oUV59ZJoGq.pUF3FhlLOTYqdq5G9ownNvMRYsyLmt.','khanza',1)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- ---------------------------------------------------------------------
-- Contoh alat (nonaktif) — silakan sesuaikan lalu aktifkan
-- ---------------------------------------------------------------------
INSERT INTO `instruments`
 (`id`,`kode`,`nama`,`merk`,`model`,`category_id`,`protokol`,`transport`,`mode`,`host`,`port`,`aktif`) VALUES
 (1,'HEMA-01','Hematology Analyzer 1','Mindray','BC-5150',1,'astm','tcp_server','bidirectional','0.0.0.0',5100,0),
 (2,'KIMIA-01','Chemistry Analyzer 1','Mindray','BS-240',2,'hl7','tcp_server','unidirectional','0.0.0.0',5200,0),
 (3,'URIN-01','Urine Analyzer 1','Dirui','H-500',3,'astm','serial','unidirectional',NULL,NULL,0)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

UPDATE `instruments` SET `serial_port`='/dev/tty.usbserial-1410', `baud_rate`=9600, `parity`='none' WHERE `id`=3;

-- Contoh pemetaan kode alat → pemeriksaan LIS (hematology analyzer)
INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`) VALUES
 (1,'WBC',4),(1,'RBC',3),(1,'HGB',1),(1,'HCT',2),(1,'MCV',6),(1,'MCH',7),
 (1,'MCHC',8),(1,'RDW-CV',9),(1,'PLT',5),(1,'MPV',10),
 (1,'NEU%',11),(1,'LYM%',12),(1,'MON%',13),(1,'EOS%',14),(1,'BAS%',15)
ON DUPLICATE KEY UPDATE `test_id`=VALUES(`test_id`);
