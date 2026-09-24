'use strict';

/**
 * HL7 v2.x di atas MLLP (Minimal Lower Layer Protocol).
 *
 * Kerangka MLLP:
 *   <VT> pesan HL7 <FS><CR>
 *   VT = 0x0B, FS = 0x1C, CR = 0x0D
 *
 * Segmen dipisahkan CR. Pembatas field diambil dari MSH-1 dan MSH-2
 * sehingga varian analyzer yang memakai pembatas tidak baku tetap terbaca.
 *
 * Pesan yang ditangani:
 *   ORU^R01 / OUL^R22  — hasil pemeriksaan  → dijawab ACK
 *   QRY^Q02 / QBP^Q11  — permintaan worklist → dijawab ORM^O01 berisi order
 *   lainnya            — dijawab ACK
 */

const { EventEmitter } = require('events');

const VT = 0x0b;
const FS = 0x1c;
const CR = 0x0d;

/** Denyut hidup analyzer Mindray pada port satu arah (BC-5800 D.2.1). */
const HEARTBEAT = 0x02;

/** Batas wajar satu pesan HL7, termasuk histogram base64. */
const MAKS_PESAN = 4 * 1024 * 1024;

/** Pisahkan pesan menjadi segmen dan baca pembatasnya. */
function urai(pesan) {
  const segmen = pesan
    .split(/[\r\n]+/)
    .map((s) => s.trim())
    .filter((s) => s !== '');

  const msh = segmen.find((s) => s.startsWith('MSH')) || 'MSH|^~\\&';

  const pembatas = {
    field: msh[3] || '|',
    component: msh[4] || '^',
    repeat: msh[5] || '~',
    escape: msh[6] || '\\',
    subcomponent: msh[7] || '&',
  };

  return { segmen, pembatas };
}

/** Ambil field ke-n (1-based sesuai penomoran HL7) dari sebuah segmen. */
function field(segmen, n, pembatas) {
  const bagian = segmen.split(pembatas.field);

  // MSH istimewa: MSH-1 adalah pembatas field itu sendiri, sehingga
  // MSH-2 berada pada indeks 1 dan seterusnya bergeser satu.
  if (segmen.startsWith('MSH')) {
    if (n === 1) {
      return pembatas.field;
    }

    return bagian[n - 1] ?? '';
  }

  return bagian[n] ?? '';
}

/**
 * Pulihkan urutan escape HL7 menjadi karakter aslinya.
 *
 * Kedua manual Mindray memuat tabel yang sama (BC-5000 bagian 3,
 * BC-5800 "String transferring principles"):
 *
 *     \F\ → pembatas field         \R\ → pembatas pengulangan
 *     \S\ → pembatas komponen      \E\ → karakter escape
 *     \T\ → pembatas subkomponen   \.br\ → CR (akhir segmen)
 *
 * Tanpa pemulihan ini, nama pasien atau catatan yang memuat "&" atau "^"
 * akan tampil sebagai "\T\" dan "\S\" di layar hasil — terbaca sebagai
 * data rusak padahal alat mengirimkannya dengan benar.
 */
function bukaEscape(teks, pembatas) {
  if (typeof teks !== 'string' || teks.indexOf(pembatas.escape) === -1) {
    return teks;
  }

  const e = pembatas.escape;
  const peta = {
    F: pembatas.field,
    S: pembatas.component,
    T: pembatas.subcomponent,
    R: pembatas.repeat,
    E: e,
  };

  let hasil = '';
  let i = 0;

  while (i < teks.length) {
    if (teks[i] !== e) {
      hasil += teks[i];
      i += 1;
      continue;
    }

    const tutup = teks.indexOf(e, i + 1);
    if (tutup === -1) {
      // Escape tidak berpasangan: biarkan apa adanya, jangan menebak.
      hasil += teks.slice(i);
      break;
    }

    const kode = teks.slice(i + 1, tutup);

    if (kode === '.br') {
      hasil += '\r';
    } else if (Object.prototype.hasOwnProperty.call(peta, kode)) {
      hasil += peta[kode];
    } else {
      // Urutan yang tidak dikenali (mis. \H\ penanda tampilan) dibuang
      // isinya saja, bukan seluruh teksnya.
      hasil += '';
    }

    i = tutup + 1;
  }

  return hasil;
}

/**
 * Ubah karakter pembatas menjadi urutan escape sebelum dikirim.
 *
 * Sebelumnya pembatas hanya diganti spasi. Itu aman tetapi merusak data:
 * nama "Siti & Rekan" berubah menjadi "Siti   Rekan". Dengan escape yang
 * benar, alat menerimanya utuh.
 */
function pasangEscape(teks, pembatas) {
  const t = String(teks ?? '');
  if (t === '') {
    return '';
  }

  const e = pembatas.escape;

  return t
    // Escape harus lebih dulu, agar tidak ikut ter-escape dua kali.
    .split(e).join(e + 'E' + e)
    .split(pembatas.field).join(e + 'F' + e)
    .split(pembatas.component).join(e + 'S' + e)
    .split(pembatas.subcomponent).join(e + 'T' + e)
    .split(pembatas.repeat).join(e + 'R' + e)
    .replace(/\r\n|\r|\n/g, e + '.br' + e);
}

/** Ambil komponen ke-n dari sebuah field. */
function komponen(nilai, n, pembatas) {
  if (typeof nilai !== 'string') {
    return '';
  }

  return (nilai.split(pembatas.component)[n - 1] ?? '').trim();
}

/** Ubah timestamp HL7 (YYYYMMDDHHMMSS) menjadi 'YYYY-MM-DD HH:MM:SS'. */
function bacaWaktu(nilai) {
  if (typeof nilai !== 'string') {
    return null;
  }
  const t = nilai.replace(/\D/g, '');
  if (t.length < 8) {
    return null;
  }

  return `${t.slice(0, 4)}-${t.slice(4, 6)}-${t.slice(6, 8)} ` +
         `${t.slice(8, 10) || '00'}:${t.slice(10, 12) || '00'}:${t.slice(12, 14) || '00'}`;
}

function capWaktuSekarang() {
  const d = new Date();

  return [
    d.getFullYear(),
    String(d.getMonth() + 1).padStart(2, '0'),
    String(d.getDate()).padStart(2, '0'),
    String(d.getHours()).padStart(2, '0'),
    String(d.getMinutes()).padStart(2, '0'),
    String(d.getSeconds()).padStart(2, '0'),
  ].join('');
}

/**
 * Terjemahkan pesan hasil (ORU/OUL) menjadi payload ternormalisasi.
 *
 * @param {string} pesan
 * @param {string} kodeAlat
 */
function keNormal(pesan, kodeAlat) {
  const { segmen, pembatas } = urai(pesan);

  const samples = [];
  let pasienAktif = null;
  let sampelAktif = null;

  const dorong = () => {
    if (sampelAktif !== null && sampelAktif.results.length > 0) {
      samples.push(sampelAktif);
    }
    sampelAktif = null;
  };

  for (const s of segmen) {
    const tipe = s.slice(0, 3).toUpperCase();

    if (tipe === 'PID') {
      // PID-5 berbentuk "LastName^FirstName"; komponennya digabung dengan
      // spasi, dan tiap bagian dipulihkan dari urutan escape.
      const nama = field(s, 5, pembatas)
        .split(pembatas.component)
        .map((x) => bukaEscape(x, pembatas))
        .filter((x) => x !== '')
        .join(' ')
        .trim();

      pasienAktif = {
        id: komponen(field(s, 3, pembatas), 1, pembatas) || field(s, 2, pembatas),
        name: nama,
        birthdate: bacaWaktu(field(s, 7, pembatas)),
        sex: field(s, 8, pembatas).trim().toUpperCase(),
      };
      continue;
    }

    if (tipe === 'OBR') {
      dorong();

      // Sample ID: OBR-3 (filler order number) lebih dipercaya daripada
      // OBR-2 (placer). Sebagian analyzer justru mengisi OBR-2.
      const filler = komponen(field(s, 3, pembatas), 1, pembatas);
      const placer = komponen(field(s, 2, pembatas), 1, pembatas);

      sampelAktif = {
        sample_id: filler !== '' ? filler : placer,
        instrument_sample_id: placer,
        requested_at: bacaWaktu(field(s, 6, pembatas)),
        collected_at: bacaWaktu(field(s, 7, pembatas)),
        priority: field(s, 5, pembatas).trim(),
        patient: pasienAktif,
        results: [],
      };
      continue;
    }

    if (tipe === 'SPM' && sampelAktif !== null && sampelAktif.sample_id === '') {
      // SPM-2 memuat identitas spesimen pada varian OUL^R22.
      sampelAktif.sample_id = komponen(field(s, 2, pembatas), 1, pembatas);
      continue;
    }

    if (tipe === 'OBX') {
      if (sampelAktif === null) {
        sampelAktif = {
          sample_id: '',
          instrument_sample_id: '',
          patient: pasienAktif,
          results: [],
        };
      }

      const identifier = field(s, 3, pembatas);
      const kode = komponen(identifier, 1, pembatas) || identifier.trim();
      const tipeNilai = field(s, 2, pembatas).trim().toUpperCase();

      // OBX bertipe ED memuat data biner base64 — histogram dan
      // scattergram. Itu bukan hasil pemeriksaan, ukurannya besar, dan
      // memasukkannya ke aliran hasil hanya membebani database serta
      // memenuhi layar Hasil Belum Terpetakan.
      if (tipeNilai === 'ED') {
        continue;
      }

      let nilai = field(s, 5, pembatas).trim();
      // Nilai bertipe CE/CWE datang sebagai kode^teks; ambil komponen pertama.
      if (['CE', 'CWE', 'CNE'].includes(tipeNilai)) {
        nilai = komponen(nilai, 1, pembatas) || nilai;
      }

      sampelAktif.results.push({
        code: kode,
        raw_code: identifier.trim(),
        name: bukaEscape(komponen(identifier, 2, pembatas), pembatas),
        value: bukaEscape(nilai, pembatas),
        unit: komponen(field(s, 6, pembatas), 1, pembatas) || field(s, 6, pembatas).trim(),
        ref_range: field(s, 7, pembatas).trim(),
        flags: field(s, 8, pembatas).trim(),
        status: field(s, 11, pembatas).trim(),
        completed_at: bacaWaktu(field(s, 14, pembatas)) || bacaWaktu(field(s, 19, pembatas)),
      });
      continue;
    }

    if (tipe === 'NTE' && sampelAktif !== null && sampelAktif.results.length > 0) {
      const catatan = bukaEscape(field(s, 3, pembatas), pembatas).trim();
      if (catatan !== '') {
        const terakhir = sampelAktif.results[sampelAktif.results.length - 1];
        terakhir.comment = (terakhir.comment ? terakhir.comment + '; ' : '') + catatan;
      }
    }
  }

  dorong();

  return {
    instrument_code: kodeAlat,
    protocol: 'hl7',
    raw: pesan,
    samples,
  };
}

/** Jenis pesan, mis. 'ORU^R01'. */
function jenisPesan(pesan) {
  const { segmen, pembatas } = urai(pesan);
  const msh = segmen.find((s) => s.startsWith('MSH'));
  if (msh === undefined) {
    return '';
  }

  const t = field(msh, 9, pembatas);

  return `${komponen(t, 1, pembatas)}^${komponen(t, 2, pembatas)}`.replace(/\^$/, '');
}

/**
 * Processing ID (MSH-11) — penentu JENIS DATA, bukan sekadar penanda.
 *
 * Kedua manual Mindray memakai medan ini untuk membedakan data pasien
 * dari data kontrol mutu:
 *
 *   BC-5000 : "P" hasil sampel & pencarian worklist,  "Q" hasil QC
 *   BC-5800 : "P" hasil sampel & pencarian worklist,
 *             "D" pengaturan QC,  "T" hasil QC
 *
 * Seluruhnya dikirim sebagai ORU^R01, jadi jenis pesan saja tidak cukup
 * untuk membedakannya.
 */
function processingId(pesan) {
  const { segmen, pembatas } = urai(pesan);
  const msh = segmen.find((s) => s.startsWith('MSH'));

  return msh === undefined ? '' : komponen(field(msh, 11, pembatas), 1, pembatas).toUpperCase();
}

/**
 * Apakah pesan ini membawa data kontrol mutu, bukan hasil pasien?
 *
 * MENGAPA INI PENTING
 *
 * Lari QC adalah kegiatan harian. Bahan kontrol bukan pasien: PID-3 pada
 * pesan QC berisi NOMOR LOT kontrol, bukan nomor rekam medis. Bila pesan
 * QC diproses sebagai hasil pasien, LIS akan mencocokkan nomor lot itu
 * dengan barcode spesimen — dan bila kebetulan cocok, nilai kontrol masuk
 * ke hasil pasien. Itu kekeliruan yang tidak boleh terjadi sekali pun.
 */
function adalahQc(pesan) {
  return ['Q', 'D', 'T'].includes(processingId(pesan));
}

/** Message control id (MSH-10) — dipakai pada balasan ACK. */
function controlId(pesan) {
  const { segmen, pembatas } = urai(pesan);
  const msh = segmen.find((s) => s.startsWith('MSH'));

  return msh === undefined ? '' : field(msh, 10, pembatas).trim();
}

/**
 * Sample ID yang diminta pada pesan query.
 *
 * Tiga letak, bergantung dialek analyzer:
 *   QRD-8  — QRY^Q02 klasik
 *   QPD-3  — QBP^Q11
 *   ORC-3  — ORM^O01 Mindray ("Filler Order Number", berisi sample ID
 *            pada pesan permintaan; lihat BC-5800 LIS Protocol Manual
 *            Tabel 12-15)
 */
function sampleIdQuery(pesan) {
  const { segmen, pembatas } = urai(pesan);

  const orc = segmen.find((s) => s.startsWith('ORC'));
  if (orc !== undefined) {
    // ORC-3 pada permintaan; ORC-2 dipakai pada balasan.
    const nilai = field(orc, 3, pembatas) || field(orc, 2, pembatas);
    const id = komponen(nilai, 1, pembatas) || nilai.trim();
    if (id !== '') return id;
  }

  const qrd = segmen.find((s) => s.startsWith('QRD'));
  if (qrd !== undefined) {
    const nilai = field(qrd, 8, pembatas);

    return komponen(nilai, 1, pembatas) || nilai.trim();
  }

  const qpd = segmen.find((s) => s.startsWith('QPD'));
  if (qpd !== undefined) {
    const nilai = field(qpd, 3, pembatas);

    return komponen(nilai, 1, pembatas) || nilai.trim();
  }

  return '';
}

/**
 * Versi HL7 lawan bicara, dibaca dari MSH-12.
 *
 * Membalas dengan versi lain adalah kesalahan interoperabilitas yang nyata.
 * Parser analyzer yang ketat dapat menolak pesannya, dan sebagian firmware
 * menanggapi penolakan itu dengan galat fatal yang menuntut restart alat —
 * bukan sekadar pesan kesalahan. Karena itu versi lawan bicara selalu
 * dicerminkan, tidak pernah dipatok.
 *
 * Bawaan 2.3.1 dipilih karena itulah versi yang dipakai sebagian besar
 * analyzer hematologi dan kimia klinik yang beredar.
 */
function versiHl7(pesanAsli, bawaan = '2.3.1') {
  const { segmen, pembatas } = urai(pesanAsli || '');
  const msh = segmen.find((s) => s.startsWith('MSH'));
  if (msh === undefined) return bawaan;

  const v = field(msh, 12, pembatas).trim();

  // Terima hanya bentuk versi yang masuk akal: 2.1 … 2.9, boleh 2.3.1.
  return /^2\.\d(\.\d)?$/.test(v) ? v : bawaan;
}

/** Query tag untuk QAK-1: QPD-2 pada QBP, atau control ID sebagai cadangan. */
function queryTag(pesanAsli) {
  const { segmen, pembatas } = urai(pesanAsli || '');

  const qpd = segmen.find((s) => s.startsWith('QPD'));
  if (qpd !== undefined) {
    const t = field(qpd, 2, pembatas).trim();
    if (t !== '') return t;
  }

  return controlId(pesanAsli || '');
}

/** Susun pesan ACK (MSA|AA). */
function bangunAck(pesanAsli, namaHost = 'LIS', kode = 'AA', teks = '') {
  const { segmen, pembatas } = urai(pesanAsli);
  const msh = segmen.find((s) => s.startsWith('MSH'));

  const pengirimAsli = msh === undefined ? '' : field(msh, 3, pembatas);
  const fasilitasAsli = msh === undefined ? '' : field(msh, 4, pembatas);
  const id = controlId(pesanAsli);
  const cap = capWaktuSekarang();
  const versi = versiHl7(pesanAsli);

  // Jenis pesan balasan HARUS mencerminkan trigger event pesan yang dijawab.
  //
  // Spesifikasi Mindray menyebutnya tegas: "the MSH-9 field should be
  // ACK^R01". Mengirim "ACK" polos membuat analyzer tidak mengenali
  // balasannya, dan dengan "ACK Synchronous Transmission" aktif, alat
  // menunggu sampai batas waktunya habis lalu menganggap pengiriman gagal.
  const t = msh === undefined ? '' : field(msh, 9, pembatas);
  const trigger = komponen(t, 2, pembatas);
  const jenisBalas = trigger !== '' ? `ACK^${trigger}` : 'ACK';

  // Processing ID dan character set digemakan dari pesan asal: "P" untuk
  // hasil sampel, "Q" untuk QC — analyzer memakainya untuk mencocokkan
  // balasan dengan antrian kirimnya. MSH-18 "UNICODE" menandakan UTF-8.
  const prosesId = (msh === undefined ? '' : field(msh, 11, pembatas).trim()) || 'P';
  const charset = msh === undefined ? '' : field(msh, 18, pembatas).trim();

  const baris = [
    `MSH|^~\\&|${namaHost}|LAB|${pengirimAsli}|${fasilitasAsli}|${cap}||${jenisBalas}`
      + `|${id !== '' ? id : cap}|${prosesId}|${versi}|||||`
      + `|${charset}|||`,
    `MSA|${kode}|${id}${teks !== '' ? '|' + teks : ''}`,
  ];

  return baris.join('\r') + '\r';
}

/**
 * Pilih jenis pesan jawaban worklist berdasarkan query yang diterima.
 *
 * Ini bukan detail kosmetik. Analyzer menunggu jenis pesan tertentu; yang
 * lain diperlakukan sebagai pesan cacat, dan sebagian firmware menanggapinya
 * dengan galat fatal yang menuntut restart alat — bukan sekadar pesan
 * kesalahan di layar.
 *
 * Pasangan baku HL7:
 *   QBP^Q11  → RSP^K11   (query berbasis parameter, HL7 2.4 ke atas)
 *   QRY^Q02  → ORM^O01   (kebiasaan luas pabrikan analyzer)
 *
 * Dapat dipaksa lewat HL7_WORKLIST_REPLY pada middleware/.env:
 *   auto (bawaan) | orm | rsp | off
 * Nilai "off" mematikan pengiriman worklist sama sekali dan hanya membalas
 * ACK — pengaman untuk dipakai saat menelusuri alat yang bermasalah.
 */
function jenisBalasanWorklist(pesanAsli, paksa = 'auto') {
  const pilih = String(paksa || 'auto').toLowerCase();
  if (['orm', 'rsp', 'off', 'mindray'].includes(pilih)) return pilih;

  const jenis = jenisPesan(pesanAsli || '');

  return jenis.startsWith('QBP') ? 'rsp' : 'orm';
}

/**
 * Dialek Mindray: ORM^O01 dijawab ORR^O02.
 *
 * Sumber: BC-5800 LIS Protocol Manual (P/N 046-000243-00), bagian D.4.2.
 *
 *   Analyzer → LIS : ORM^O01   "the main unit requests LIS to re-fill
 *                               the order message"
 *                    ORC|RF||<SampleID>||IP
 *
 *   LIS → Analyzer : ORR^O02   "Affirming of the ORM^O01 message. Here,
 *                               returning the completed information of
 *                               order (i.e. worklist)."
 *                    MSH, MSA, [PID [PV1]], { ORC [ OBR {[OBX]} ] }
 *                    ORC|AF|<SampleID>
 *
 * Kode kendali order: "RF" = re-fill the order request (permintaan),
 * "AF" = affirm the re-filled order (balasan).
 *
 * CATATAN PENTING TENTANG ISI WORKLIST
 *
 * Pada analyzer hematologi, yang dipesan bukan parameter satu per satu
 * melainkan MODE PEMERIKSAAN: "CBC" atau "CBC+5DIFF". Itulah yang dibawa
 * OBX 08003^Test Mode. Mengirim daftar 15 kode parameter — seperti yang
 * dilakukan dialek lain — tidak sesuai untuk alat ini.
 *
 * Batas waktu: manual menyebut LIS harus membalas dalam 2 detik.
 *
 * @returns {string} satu pesan ORR^O02
 */
function bangunMindrayWorklist(worklist, pesanAsli, namaHost = 'LIS') {
  const { segmen, pembatas } = urai(pesanAsli);
  const msh = segmen.find((s) => s.startsWith('MSH'));

  const aplikasiAlat = msh === undefined ? '' : field(msh, 3, pembatas);
  const fasilitasAlat = msh === undefined ? '' : field(msh, 4, pembatas);
  const id = controlId(pesanAsli) || '1';
  const cap = capWaktuSekarang();
  const versi = versiHl7(pesanAsli);
  const charset = (msh === undefined ? '' : field(msh, 18, pembatas).trim()) || 'UNICODE';
  const prosesId = (msh === undefined ? '' : field(msh, 11, pembatas).trim()) || 'P';

  const sampleId = sampleIdQuery(pesanAsli);
  const bersih = (t) => String(t ?? '').replace(/[|^~\\&\r\n]/g, ' ');

  const kepala = `MSH|^~\\&|${namaHost}|LAB|${aplikasiAlat}|${fasilitasAlat}|${cap}`
    + `||ORR^O02|${cap}|${prosesId}|${versi}||||||${charset}`;

  const ada = worklist !== null && worklist !== undefined
    && Array.isArray(worklist.tests) && worklist.tests.length > 0;

  if (!ada) {
    // Order tidak ditemukan: tetap ORR^O02 yang sah, tetapi ditolak lembut
    // lewat MSA|AR dan tanpa segmen ORC. Alat menampilkannya sebagai
    // "tidak ada data", bukan sebagai pesan cacat.
    return [kepala, `MSA|AR|${id}|Order tidak ditemukan`].join('\r') + '\r';
  }

  const p = worklist.patient || {};
  const jk = p.sex === 'L' ? 'Male' : (p.sex === 'P' ? 'Female' : '');
  const lahir = (p.birthdate || '').replace(/\D/g, '').slice(0, 8);

  // PID-5 berbentuk "LastName^FirstName". Nama Indonesia umumnya tidak
  // terbagi; seluruhnya ditaruh pada komponen pertama agar tampil utuh.
  const nama = bersih(p.name);

  const baris = [
    kepala,
    `MSA|AA|${id}`,
    `PID|1||${bersih(p.id)}^^^^MR||${nama}||${lahir}${lahir !== '' ? '000000' : ''}|${jk}`,
  ];

  const dept = bersih(worklist.department);
  const bed = bersih(worklist.bed);
  if (dept !== '' || bed !== '') {
    // PV1-3 berbentuk "Department^^Bed No."
    baris.push(`PV1|1||${dept}^^${bed}`);
  }

  // ORC-1 "AF" = affirm the re-filled order; ORC-2 memuat sample ID.
  baris.push(`ORC|AF|${bersih(sampleId || worklist.sample_id)}`);

  baris.push(
    `OBR|1|${bersih(worklist.sample_id)}||00001^Automated Count^99MRC`
    + `|${worklist.priority === 'S' ? 'S' : 'R'}|${cap}`
  );

  // Mode pemeriksaan: CBC bila order hanya memuat parameter dasar,
  // CBC+5DIFF bila hitung jenis leukosit ikut diminta.
  baris.push(`OBX|1|IS|08003^Test Mode^99MRC||${modeUji(worklist.tests)}||||||F`);

  return baris.join('\r') + '\r';
}

/**
 * Tentukan mode pemeriksaan dari daftar kode yang diminta.
 *
 * Hitung jenis leukosit (NEU/LYM/MON/EOS/BAS) hanya tersedia pada mode
 * 5-diff. Kode LOINC-nya dipakai sebagai penanda karena itulah yang
 * dikirimkan alat.
 */
function modeUji(tests) {
  const diff = ['751-8', '770-8', '731-0', '736-9', '742-7', '5905-5',
                '711-2', '713-8', '704-7', '706-2',
                'NEU', 'LYM', 'MON', 'EOS', 'BAS'];

  const adaDiff = (tests || []).some(
    (k) => diff.some((d) => String(k).toUpperCase().startsWith(d.toUpperCase()))
  );

  return adaDiff ? 'CBC+5DIFF' : 'CBC';
}

/**
 * Susun jawaban worklist.
 *
 * Bentuknya mengikuti query yang diterima (lihat jenisBalasanWorklist), dan
 * versi HL7-nya mencerminkan MSH-12 milik analyzer, bukan versi tetap.
 */
function bangunOrmWorklist(worklist, pesanAsli, namaHost = 'LIS', paksaBalasan = 'auto') {
  const { segmen, pembatas } = urai(pesanAsli);
  const msh = segmen.find((s) => s.startsWith('MSH'));
  const tujuan = msh === undefined ? '' : field(msh, 3, pembatas);
  const fasilitas = msh === undefined ? '' : field(msh, 4, pembatas);
  const cap = capWaktuSekarang();
  const versi = versiHl7(pesanAsli);
  const bentuk = jenisBalasanWorklist(pesanAsli, paksaBalasan);

  if (bentuk === 'off') {
    // Pengaman: jangan kirim worklist sama sekali.
    return bangunAck(pesanAsli, namaHost, 'AA', 'Pengiriman worklist dimatikan');
  }

  if (bentuk === 'mindray') {
    return bangunMindrayWorklist(worklist, pesanAsli, namaHost);
  }

  if (worklist === null || worklist === undefined ||
      !Array.isArray(worklist.tests) || worklist.tests.length === 0) {
    // Tidak ada order untuk sampel tersebut — kirim ACK penolakan lembut.
    return bangunAck(pesanAsli, namaHost, 'AR', 'Order tidak ditemukan');
  }

  const jenisPesanBalas = bentuk === 'rsp' ? 'RSP^K11' : 'ORM^O01';

  const baris = [
    `MSH|^~\\&|${namaHost}|LAB|${tujuan}|${fasilitas}|${cap}||${jenisPesanBalas}|${cap}|P|${versi}`,
  ];

  if (bentuk === 'rsp') {
    // RSP wajib membawa MSA dan QAK agar dianggap jawaban query yang sah.
    baris.push(`MSA|AA|${controlId(pesanAsli)}`);
    baris.push(`QAK|${queryTag(pesanAsli)}|OK`);

    const qpd = segmen.find((s) => s.startsWith('QPD'));
    if (qpd !== undefined) baris.push(qpd);
  }

  const p = worklist.patient || {};
  const jk = p.sex === 'L' ? 'M' : (p.sex === 'P' ? 'F' : 'U');
  const lahir = (p.birthdate || '').replace(/\D/g, '').slice(0, 8);
  const bersih = (t) => String(t ?? '').replace(/[|^~\\&\r\n]/g, ' ');

  baris.push(`PID|1||${bersih(p.id)}||${bersih(p.name)}||${lahir}|${jk}`);

  worklist.tests.forEach((kode, i) => {
    baris.push(`ORC|NW|${bersih(worklist.sample_id)}|||||||${cap}`);
    baris.push(
      `OBR|${i + 1}|${bersih(worklist.sample_id)}|${bersih(worklist.sample_id)}|` +
      `${bersih(kode)}^${bersih(kode)}|${worklist.priority === 'S' ? 'S' : 'R'}|${cap}`
    );
  });

  return baris.join('\r') + '\r';
}

/**
 * Sesi MLLP: memecah aliran byte menjadi pesan HL7 utuh.
 *
 * Peristiwa:
 *   'message' (string pesan)
 */
class Hl7Session extends EventEmitter {
  constructor({ write, logger, nama = 'alat', encoding = 'utf8' }) {
    super();
    this.write = write;
    this.logger = logger;
    this.nama = nama;

    // Bawaan utf8. Spesifikasi Mindray menetapkan MSH-18 = "UNICODE",
    // artinya pesan dikirim sebagai UTF-8. Membaca byte UTF-8 sebagai
    // latin1 merusak huruf beraksen pada nama pasien tanpa memunculkan
    // galat apa pun — data tetap tersimpan, hanya salah.
    this.encoding = encoding === 'latin1' ? 'latin1' : 'utf8';
    this.buffer = Buffer.alloc(0);
  }

  /**
   * Catat byte di luar blok MLLP tanpa membanjiri log.
   *
   * Heartbeat 0x02 adalah hal normal dan tidak perlu dilaporkan; byte lain
   * di luar blok justru menarik saat menelusuri masalah.
   */
  _catatDerau(buf) {
    const bukanDenyut = buf.filter((b) => b !== HEARTBEAT && b !== CR && b !== 0x0a);

    if (bukanDenyut.length > 0 && this.logger.aktifTrace()) {
      this.logger.trace(
        `[${this.nama}] byte di luar blok MLLP diabaikan: ${this.logger.bacaBiner(bukanDenyut)}`
      );
    }
  }

  feed(data) {
    this.buffer = Buffer.concat([this.buffer, data]);

    if (this.logger.aktifTrace()) {
      this.logger.trace(`[${this.nama}] << ${this.logger.bacaBiner(data)}`);
    }

    for (;;) {
      const mulai = this.buffer.indexOf(VT);

      if (mulai === -1) {
        // Tidak ada awal blok: seluruh isi buffer berada DI LUAR pesan
        // MLLP dan tidak akan pernah menjadi bagian pesan mana pun.
        //
        // Ini bukan kasus langka. Manual BC-5800 (D.2.1) menyebut analyzer
        // mengirim satu karakter heartbeat 0x02 setiap 3 detik pada port
        // satu arah, di sela-sela pesan. Bila byte itu ditahan, buffer
        // tumbuh terus sepanjang alat menyala.
        if (this.buffer.length > 0) {
          this._catatDerau(this.buffer);
          this.buffer = Buffer.alloc(0);
        }

        return;
      }

      // Byte sebelum <VT> juga derau — buang, jangan ikut diurai.
      if (mulai > 0) {
        this._catatDerau(this.buffer.subarray(0, mulai));
        this.buffer = this.buffer.subarray(mulai);
        continue;
      }

      const akhir = this.buffer.indexOf(FS, 1);
      if (akhir === -1) {
        // Pesan belum lengkap. Beri batas agar aliran yang rusak — <VT>
        // tanpa <FS> yang tak kunjung datang — tidak menahan buffer
        // selamanya.
        if (this.buffer.length > MAKS_PESAN) {
          this.logger.warn(
            `[${this.nama}] Pesan melebihi ${MAKS_PESAN} byte tanpa penutup <FS>, dibuang.`
          );
          this.buffer = Buffer.alloc(0);
        }

        return;
      }

      const pesan = this.buffer.subarray(1, akhir).toString(this.encoding);

      // Lewati FS dan CR penutup bila ada.
      let lanjut = akhir + 1;
      if (this.buffer[lanjut] === CR) {
        lanjut += 1;
      }
      this.buffer = this.buffer.subarray(lanjut);

      if (pesan.trim() !== '') {
        this.emit('message', pesan);
      }
    }
  }

  /** Kirim pesan HL7 dengan kerangka MLLP. */
  kirim(pesan) {
    const bingkai = Buffer.concat([
      Buffer.from([VT]),
      Buffer.from(pesan, this.encoding),
      Buffer.from([FS, CR]),
    ]);

    if (this.logger.aktifTrace()) {
      this.logger.trace(`[${this.nama}] >> ${this.logger.bacaBiner(bingkai)}`);
    }

    this.write(bingkai);
  }

  hancurkan() {
    this.buffer = Buffer.alloc(0);
  }
}

module.exports = {
  Hl7Session,
  urai,
  field,
  komponen,
  keNormal,
  jenisPesan,
  processingId,
  adalahQc,
  bukaEscape,
  pasangEscape,
  controlId,
  sampleIdQuery,
  bangunAck,
  bangunOrmWorklist,
  versiHl7,
  queryTag,
  jenisBalasanWorklist,
  bangunMindrayWorklist,
  modeUji,
  bacaWaktu,
  capWaktuSekarang,
  KODE: { VT, FS, CR },
};
