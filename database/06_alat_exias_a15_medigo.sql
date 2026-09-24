-- =====================================================================
--  06_alat_exias_a15_medigo.sql
--
--  Mendaftarkan tiga alat baru beserta pemeriksaan yang belum ada di
--  master:
--
--    ELYTE-01  EXIAS eLyte        elektrolit & pH darah
--    KIMIA-02  BioSystems A15     kimia klinik
--    URIN-02   MediGo URO         urinalisa
--
--  KETIGANYA SENGAJA DIDAFTARKAN NONAKTIF (aktif = 0).
--
--  YANG SUDAH TERVERIFIKASI DARI DOKUMEN PABRIKAN
--
--   EXIAS e|1
--     "Data exchange with LIS according to LIS2-A2 protocol (ASTM)",
--     lewat Ethernet RJ45. Jadi protokolnya PASTI astm, bukan hl7.
--     Parameter terukur: Na+, K+, Cl-, Ca2+, pH, Hct; ditambah dua
--     parameter TERHITUNG: nCa2+ dan tHb(c).
--     Sumber: brosur EXIAS e|1 dan halaman produk EXIAS Medical.
--     Arah koneksi dan nomor port TIDAK disebutkan di dokumen publik.
--
--   BioSystems A15
--     Service manual menyatakan alat ini "controlled on-line in real time
--     from an external dedicated PC" lewat COM1, memakai kabel USB ATAU
--     RS-232 (hanya salah satu pada satu waktu). COM2 memakai 38400 8N1.
--
--     PENTING: sambungan itu adalah analyzer -> PC, BUKAN sambungan ke
--     LIS. Jadi A15 tidak akan pernah menyambung ke middleware secara
--     langsung. Yang harus dihubungkan ke LIS adalah PERANGKAT LUNAK A15
--     di PC itu — lihat menu "Konfigurasi" pada aplikasinya.
--     Sumber: BioSystems A-15 Analyzer Service Manual.
--
--   MediGo URO  (= keluarga URIT US-500)
--     Dokumen protokol pabrikan sudah ada: "Appendix B Communication
--     Protocol Description", untuk "URIT Group Automatic Urine Sediment
--     Analyzer". Yang terverifikasi darinya:
--
--       protokol   HL7 v2.3.1 di atas MLLP
--                  SB=0x0B (VT), EB=0x1C (FS), CR=0x0D — kerangka MLLP
--                  baku, sama dengan yang sudah ditangani hl7.js.
--       pesan      ORU^R01, "unsolicited": ALAT yang aktif mengirim ke
--                  LIS. Ini petunjuk kuat bahwa alat berperan sebagai
--                  penelepon, jadi transport di sisi LIS = tcp_server.
--       encoding   UNICODE (MSH-18) — bukan latin1. Lihat catatan di
--                  bawah.
--       OBX-3      berbentuk "NNN^KODE", contoh "001^WBC". Komponen
--                  PERTAMA yang menjadi identitas, yaitu nomor 001 —
--                  bukan singkatan WBC. hl7.js memang mengambil komponen
--                  pertama, jadi sudah cocok.
--       OBX-5      nilai hasil; OBX-6 satuan; OBX-7 nilai rujukan;
--                  OBX-8 flag H/N/L.
--       OBX tipe ED  memuat gambar base64. hl7.js sudah melewatinya.
--       ACK        alat mengharapkan satu byte 0x06, dan disebut
--                  "compatible with non-return data" — jadi ia tetap
--                  jalan walau tidak dibalas sama sekali.
--
--     NOMOR ITEM-nya sendiri tidak tercantum di lampiran ini; yang ada
--     hanya contoh 001=WBC dan 002=RBC. Jadi kode parameter tetap harus
--     dipungut dari kiriman alat yang sebenarnya:
--       node simulator/temukanAlat.js --dengar --kode URIN-02
--
--  Yang MASIH belum diketahui untuk ketiganya: arah koneksi dan nomor
--  port. Itu tidak ditebak di sini, karena menebaknya hanya mengulang
--  persoalan HEMA-03 — berhari-hari mengejar ETIMEDOUT yang sebenarnya
--  berasal dari arah koneksi yang salah.
--
--  Tentukan lebih dulu dengan bukti, satu alat pada satu waktu:
--
--      cd middleware
--      node simulator/temukanAlat.js --dengar
--      # lalu tekan "kirim ke LIS" pada alat
--
--  Bila ada yang masuk, keluarannya menyebutkan port dan protokolnya
--  sekaligus, dan alat itu berarti TCP Server di sisi LIS. Bila sunyi,
--  pindai alamat alat:
--
--      node simulator/temukanAlat.js --pindai <ip-alat>
--
--  Baru setelah itu isi Transport, Host, Port lewat menu Alat
--  Laboratorium, lalu centang "Alat aktif".
--
--  Berkas ini aditif dan dapat dijalankan berulang.
--
--      mysql -u root -p lis < database/06_alat_exias_a15_medigo.sql
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Pemeriksaan baru
--
--    Hanya yang benar-benar belum ada. Na, K, Cl, Ca, Hematokrit, dan
--    seluruh parameter urinalisa sudah ada di 02_seed_master.sql dan
--    TIDAK diduplikasi — satu besaran cukup satu baris di master, walau
--    diukur oleh beberapa alat.
--
--    Yang belum ada hanya pH DARAH. Master baru memuat U_PH, dan itu pH
--    urine: besaran, rentang rujukan, dan spesimennya berbeda. Memakai
--    ulang U_PH untuk EXIAS akan menaruh hasil gas darah di bawah nama
--    pemeriksaan urine.
-- ---------------------------------------------------------------------

INSERT INTO `tests`
 (`kode`,`nama`,`nama_singkat`,`category_id`,`specimen_type_id`,`loinc`,`satuan`,`metode`,`tipe_hasil`,`desimal`,`harga`,`tat_menit`,`urut`,`is_kritis`) VALUES
 ('PH_DARAH','pH Darah','pH',2,3,'11558-4',NULL,'Elektroda selektif ion','numerik',2,0,30,56,1)
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);


-- ---------------------------------------------------------------------
-- 2. Alat
--
--    host/port/protokol dibiarkan sebagaimana bawaan skema dan harus
--    diisi setelah ditemukan. aktif = 0 menjaga middleware tidak
--    mencoba menyambung ke alamat yang belum diketahui.
-- ---------------------------------------------------------------------

INSERT INTO `instruments`
 (`kode`,`nama`,`merk`,`model`,`category_id`,`protokol`,`transport`,`mode`,`host`,`port`,`aktif`,`simpan_raw`,`encoding`) VALUES
 -- protokol astm sudah terverifikasi dari dokumen pabrikan (LIS2-A2).
 ('ELYTE-01','Elektrolit & pH Darah','EXIAS','e|1',        2,'astm','tcp_server','unidirectional','0.0.0.0',NULL,0,1,'latin1'),

 -- A15 tersambung ke PC-nya lewat USB/RS-232, bukan ke jaringan. Transport
 -- di bawah hanyalah nilai bawaan skema dan HAMPIR PASTI perlu diganti
 -- setelah menu Konfigurasi pada aplikasi A15 diperiksa.
 ('KIMIA-02','Kimia Klinik A15',     'BioSystems','A15',   2,'astm','serial',    'unidirectional',NULL,     NULL,0,1,'latin1'),

 -- protokol hl7 dan arah tcp_server terverifikasi dari dokumen pabrikan.
 -- encoding utf8: MSH-18 alat ini berbunyi UNICODE, bukan latin1 seperti
 -- bawaan skema. Nama pasien berhuruf non-ASCII akan rusak bila salah.
 ('URIN-02', 'Urinalisa',            'MediGo','URO (URIT US-500)', 3,'hl7','tcp_server','unidirectional','0.0.0.0',NULL,0,1,'utf8')
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`), `merk`=VALUES(`merk`), `model`=VALUES(`model`),
                        `protokol`=VALUES(`protokol`), `encoding`=VALUES(`encoding`);


-- ---------------------------------------------------------------------
-- 3. Pemetaan kode parameter
--
--    Kolom kode_alat diisi singkatan yang LAZIM dipakai alat-alat ini.
--    Ini tebakan yang terdidik, bukan hasil pembacaan manual — dan
--    tebakan pada pemetaan tidak berbahaya, karena kode yang meleset
--    berakhir di layar "Hasil Belum Terpetakan" lengkap dengan kode
--    aslinya, bukan tersimpan di bawah pemeriksaan yang salah.
--
--    Jalankan satu sampel uji per alat, buka layar itu, lalu perbaiki
--    kode yang belum cocok dari menu Pemetaan Kode.
--
--    Pencocokan kode TIDAK membedakan huruf besar-kecil (kolomnya
--    ber-collation utf8mb4_unicode_ci), jadi satu baris 'Hct' sudah
--    melayani 'HCT' maupun 'hct'. Varian besar-kecil tidak perlu
--    didaftarkan dua kali — dan memang tidak bisa, karena kunci uniknya
--    menganggapnya sama.
-- ---------------------------------------------------------------------

-- EXIAS eLyte -----------------------------------------------------------
SET @elyte := (SELECT id FROM instruments WHERE kode = 'ELYTE-01');

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`) VALUES
 (@elyte,'Na',  (SELECT id FROM tests WHERE kode='NA'),      'mmol/L'),
 (@elyte,'Na+', (SELECT id FROM tests WHERE kode='NA'),      'mmol/L'),
 (@elyte,'K',   (SELECT id FROM tests WHERE kode='K'),       'mmol/L'),
 (@elyte,'K+',  (SELECT id FROM tests WHERE kode='K'),       'mmol/L'),
 (@elyte,'Cl',  (SELECT id FROM tests WHERE kode='CL'),      'mmol/L'),
 (@elyte,'Cl-', (SELECT id FROM tests WHERE kode='CL'),      'mmol/L'),
 (@elyte,'Ca',  (SELECT id FROM tests WHERE kode='CA'),      'mmol/L'),
 (@elyte,'Ca2+',(SELECT id FROM tests WHERE kode='CA'),      'mmol/L'),
 (@elyte,'iCa', (SELECT id FROM tests WHERE kode='CA'),      'mmol/L'),
 (@elyte,'pH',  (SELECT id FROM tests WHERE kode='PH_DARAH'),NULL),
 (@elyte,'Hct', (SELECT id FROM tests WHERE kode='HT'),      '%')
ON DUPLICATE KEY UPDATE `test_id`=VALUES(`test_id`), `satuan_alat`=VALUES(`satuan_alat`);

-- Dua parameter TERHITUNG milik EXIAS didaftarkan sebagai DIABAIKAN.
--
--   nCa2+   kalsium terionisasi yang dinormalkan ke pH 7.4 — besaran yang
--           BERBEDA dari kalsium total, jadi tidak boleh dipetakan ke CA.
--   tHb(c)  hemoglobin hasil perhitungan dari Hct, bukan pengukuran.
--           Hemoglobin yang sesungguhnya datang dari alat hematologi.
--
-- Mendaftarkannya dengan abaikan = 1 membuat keduanya dibuang dengan
-- sengaja, bukan menumpuk di layar "Hasil Belum Terpetakan" sebagai
-- gangguan yang tiap hari harus dilihat lalu diabaikan lagi.
INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`abaikan`) VALUES
 (@elyte,'nCa',    NULL, 1),
 (@elyte,'nCa2+',  NULL, 1),
 (@elyte,'tHb',    NULL, 1),
 (@elyte,'tHb(c)', NULL, 1)
ON DUPLICATE KEY UPDATE `abaikan`=VALUES(`abaikan`);


-- CATATAN SATUAN KALSIUM. Master memakai mg/dL untuk CA, sementara alat
-- elektrolit lazimnya melaporkan kalsium terionisasi dalam mmol/L. Bila
-- hasil uji nanti terlihat kecil (mis. 1.2 sementara rujukan 8.5-10.5),
-- isi kolom Faktor pada Pemetaan Kode dengan 4.008 — JANGAN mengubah
-- satuan di master, karena pemeriksaan yang sama juga diisi alat lain.
-- Periksa dulu satuan yang benar-benar dikirim alat sebelum mengisinya.


-- BioSystems A15 --------------------------------------------------------
SET @a15 := (SELECT id FROM instruments WHERE kode = 'KIMIA-02');

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`) VALUES
 (@a15,'GLUCOSE', (SELECT id FROM tests WHERE kode='GDS'),   'mg/dL'),
 (@a15,'GLU',     (SELECT id FROM tests WHERE kode='GDS'),   'mg/dL'),
 (@a15,'UREA',    (SELECT id FROM tests WHERE kode='UREUM'), 'mg/dL'),
 (@a15,'BUN',     (SELECT id FROM tests WHERE kode='UREUM'), 'mg/dL'),
 (@a15,'CREA',    (SELECT id FROM tests WHERE kode='KREAT'), 'mg/dL'),
 (@a15,'CREATININE',(SELECT id FROM tests WHERE kode='KREAT'),'mg/dL'),
 (@a15,'URIC',    (SELECT id FROM tests WHERE kode='UA'),    'mg/dL'),
 (@a15,'AST',     (SELECT id FROM tests WHERE kode='SGOT'),  'U/L'),
 (@a15,'GOT',     (SELECT id FROM tests WHERE kode='SGOT'),  'U/L'),
 (@a15,'ALT',     (SELECT id FROM tests WHERE kode='SGPT'),  'U/L'),
 (@a15,'GPT',     (SELECT id FROM tests WHERE kode='SGPT'),  'U/L'),
 (@a15,'CHOL',    (SELECT id FROM tests WHERE kode='CHOL'),  'mg/dL'),
 (@a15,'HDL',     (SELECT id FROM tests WHERE kode='HDL'),   'mg/dL'),
 (@a15,'LDL',     (SELECT id FROM tests WHERE kode='LDL'),   'mg/dL'),
 (@a15,'TRIG',    (SELECT id FROM tests WHERE kode='TG'),    'mg/dL'),
 (@a15,'ALB',     (SELECT id FROM tests WHERE kode='ALB'),   'g/dL'),
 (@a15,'TP',      (SELECT id FROM tests WHERE kode='TP'),    'g/dL'),
 (@a15,'TBIL',    (SELECT id FROM tests WHERE kode='BILT'),  'mg/dL'),
 (@a15,'DBIL',    (SELECT id FROM tests WHERE kode='BILD'),  'mg/dL')
ON DUPLICATE KEY UPDATE `test_id`=VALUES(`test_id`), `satuan_alat`=VALUES(`satuan_alat`);


-- MediGo URO ------------------------------------------------------------
SET @uro := (SELECT id FROM instruments WHERE kode = 'URIN-02');

INSERT INTO `instrument_test_map` (`instrument_id`,`kode_alat`,`test_id`,`satuan_alat`) VALUES
 (@uro,'COL', (SELECT id FROM tests WHERE kode='U_WARNA'), NULL),
 (@uro,'SG',  (SELECT id FROM tests WHERE kode='U_BJ'),    NULL),
 (@uro,'PH',  (SELECT id FROM tests WHERE kode='U_PH'),    NULL),
 (@uro,'PRO', (SELECT id FROM tests WHERE kode='U_PROT'),  NULL),
 (@uro,'GLU', (SELECT id FROM tests WHERE kode='U_GLU'),   NULL),
 (@uro,'KET', (SELECT id FROM tests WHERE kode='U_KET'),   NULL),
 (@uro,'BLD', (SELECT id FROM tests WHERE kode='U_BLD'),   NULL),
 (@uro,'BIL', (SELECT id FROM tests WHERE kode='U_BIL'),   NULL),
 (@uro,'URO', (SELECT id FROM tests WHERE kode='U_URO'),   NULL),
 (@uro,'NIT', (SELECT id FROM tests WHERE kode='U_NIT'),   NULL),
 (@uro,'LEU', (SELECT id FROM tests WHERE kode='U_LEU'),   NULL),
 (@uro,'RBC', (SELECT id FROM tests WHERE kode='U_SED_ERI'),'/LPB'),
 (@uro,'WBC', (SELECT id FROM tests WHERE kode='U_SED_LEU'),'/LPB')
ON DUPLICATE KEY UPDATE `test_id`=VALUES(`test_id`), `satuan_alat`=VALUES(`satuan_alat`);

-- PERHATIAN pada 'PH' dan 'GLU'. Dua kode ini juga dipakai alat lain
-- untuk besaran yang berbeda: pH pada EXIAS adalah pH darah, GLU pada
-- A15 adalah glukosa darah. Pemetaan disimpan per alat (kunci unik
-- instrument_id + kode_alat), jadi tidak akan tertukar — asalkan setiap
-- alat terdaftar dengan kode alatnya sendiri dan hasil dikirim dengan
-- identitas alat yang benar.


-- ---------------------------------------------------------------------
-- 4. Periksa hasilnya
-- ---------------------------------------------------------------------
SELECT
    i.kode,
    i.nama,
    i.merk,
    i.model,
    i.transport,
    i.protokol,
    IFNULL(i.port, '(belum diisi)') AS port,
    IF(i.aktif, 'aktif', 'NONAKTIF — isi Transport/Host/Port dulu') AS status,
    (SELECT COUNT(*) FROM instrument_test_map m WHERE m.instrument_id = i.id) AS kode_terpetakan
FROM instruments i
WHERE i.kode IN ('ELYTE-01','KIMIA-02','URIN-02')
ORDER BY i.kode;
