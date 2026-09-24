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
/**
 * HL7 membedakan "tidak dikirim" dari "sengaja kosong". Yang kedua ditulis
 * sebagai dua tanda kutip: "". Itu penanda null, BUKAN teks dua karakter.
 *
 * Alat urinalisa mengisi hampir seluruh medan satuan dan flag dengan "",
 * dan bila diteruskan apa adanya, yang tersimpan di kolom satuan adalah
 * tanda kutip — lalu muncul di lembar hasil pasien sebagai satuan yang
 * berbunyi "". Tidak ada galat, hanya cetakan yang salah.
 */
function bersihkanNull(teks) {
  return teks === '""' ? '' : teks;
}

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
 * @param {{bedakanTipeNilai?:boolean}} [opsi]
 */
function keNormal(pesan, kodeAlat, opsi = {}) {
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

  // Identitas tabung yang tiba SEBELUM OBR.
  //
  // Pada OUL^R22 (profil IHE LAB-29, dipakai MediGo/URIT), urutan
  // segmennya PID -> SPM -> SAC -> OBR. Barcode tabung ada di SAC-3
  // (Container Identifier) atau SPM-2, dan keduanya tiba ketika sampelAktif
  // belum dibentuk — sampelAktif baru lahir di OBR. Tanpa penampung ini,
  // barcode yang dipindai operator dibuang diam-diam, lalu OBR-3 (nomor
  // otomatis buatan alat) yang dipakai sebagai sample_id. Hasilnya tidak
  // pernah menemukan ordernya, walaupun operator sudah memindai dengan
  // benar.
  let idWadah = '';

  for (const s of segmen) {
    const tipe = s.slice(0, 3).toUpperCase();

    if (tipe === 'SAC') {
      // SAC-3 = Container Identifier: barcode fisik pada tabung. Inilah
      // identitas paling spesifik, karena menunjuk tabung itu sendiri.
      const idSac = bersihkanNull(komponen(field(s, 3, pembatas), 1, pembatas));
      if (idSac !== '') {
        idWadah = idSac;
        if (sampelAktif !== null) sampelAktif.sample_id = idSac;
      }
      continue;
    }

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
      const filler = bersihkanNull(komponen(field(s, 3, pembatas), 1, pembatas));
      const placer = bersihkanNull(komponen(field(s, 2, pembatas), 1, pembatas));

      // Barcode tabung (SAC-3 / SPM-2) didahulukan bila ada. Bila tidak,
      // perilaku lama dipertahankan — OBR-3 lalu OBR-2 — supaya analyzer
      // yang tidak mengirim SAC/SPM, seperti Mindray BC-5000, tidak
      // terpengaruh sedikit pun.
      sampelAktif = {
        // OBR-2 (placer) didahulukan atas OBR-3 bila terisi: pada alat
        // urinalisa OBR-3 SELALU berisi nomor otomatis buatan alat, jadi
        // bila ada nomor order di OBR-2, itulah identitas yang benar.
        // Aman bagi BC-5000: pada seluruh 154 pesan hematologi yang
        // tercatat, OBR-2 selalu kosong, sehingga tetap jatuh ke OBR-3.
        sample_id: idWadah !== '' ? idWadah : (placer !== '' ? placer : filler),
        instrument_sample_id: placer,
        requested_at: bacaWaktu(field(s, 6, pembatas)),
        collected_at: bacaWaktu(field(s, 7, pembatas)),
        priority: field(s, 5, pembatas).trim(),
        patient: pasienAktif,
        results: [],
      };
      idWadah = '';
      continue;
    }

    if (tipe === 'SPM') {
      // SPM-2 memuat identitas spesimen pada varian OUL^R22.
      // SPM-2 sering dikirim kosong oleh alat urinalisa, sedangkan
      // identitas sampel yang benar ada di OBR-3. Menimpanya dengan
      // kosong membuat hasil kehilangan tujuan dan berakhir di Hasil
      // Menggantung — karena itu hanya diisi bila memang ada isinya.
      const idSpm = bersihkanNull(komponen(field(s, 2, pembatas), 1, pembatas));
      if (idSpm !== '') {
        // SAC-3 lebih spesifik daripada SPM-2; jangan ditimpa bila sudah ada.
        if (idWadah === '') idWadah = idSpm;
        if (sampelAktif !== null) sampelAktif.sample_id = idWadah;
      }
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
      let kode = komponen(identifier, 1, pembatas) || identifier.trim();
      const tipeNilai = field(s, 2, pembatas).trim().toUpperCase();

      // SATU KODE, DUA ARTI
      //
      // Sebagian alat memakai kode yang sama untuk dua pemeriksaan yang
      // berbeda dalam satu pesan, dan membedakannya hanya lewat OBX-2.
      // MediGo URO mengirim:
      //
      //   OBX|..|NM|WBC||20|/[HPF]      hitung leukosit sedimen
      //   OBX|..|CE|WBC||+2|            leukosit esterase carik celup
      //
      // Tabel pemetaan LIS berkunci (instrument_id, kode_alat), jadi satu
      // kode hanya boleh punya satu tujuan. Tanpa pembeda, kedua nilai itu
      // menuju pemeriksaan yang sama dan yang datang belakangan MENIMPA
      // yang pertama — hitung sedimen 20 tergantikan oleh "+2", tanpa galat
      // dan tanpa jejak.
      //
      // Bila dinyalakan, kode menjadi "WBC^NM" dan "WBC^CE" sehingga
      // keduanya dapat dipetakan ke pemeriksaan masing-masing. Sengaja
      // per-alat dan mati secara bawaan: menyalakannya mengubah seluruh
      // kode alat itu, sehingga pemetaannya harus ditulis ulang.
      if (opsi.bedakanTipeNilai === true && tipeNilai !== '') {
        kode = `${kode}^${tipeNilai}`;
      }

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

      // WARNA DAN KEJERNIHAN DI MEDAN YANG SALAH
      //
      // MediGo URO (SA_V1.0 maupun V2.0) menaruh warna dan kejernihan urine
      // di OBX-7, medan NILAI RUJUKAN, dan membiarkan OBX-5 kosong:
      //
      //   OBX|21|ST|Color|1||""|Light yellow|...
      //   OBX|24|ST|UD_Color^UD_Color^65||||Light yellow|N|...
      //
      // Dibaca apa adanya, hasilnya kosong dan warna pasien tidak pernah
      // tampil. Pemindahan ini SENGAJA dibatasi pada kode warna/kejernihan
      // saja: menerapkannya pada semua ST berarti rujukan seperti
      // "Negative" pada OBX lain bisa terbaca sebagai hasil pasien.
      let rujukan = bersihkanNull(field(s, 7, pembatas).trim());
      const kodeDasar = (komponen(identifier, 1, pembatas) || identifier).trim();
      if (bersihkanNull(nilai) === '' && rujukan !== '' && tipeNilai === 'ST'
        && /(^|_)(color|colour|transparency|tur|clarity|turbidity)$/i.test(kodeDasar)) {
        nilai = rujukan;
        rujukan = '';
      }

      sampelAktif.results.push({
        code: kode,
        raw_code: identifier.trim(),
        name: bukaEscape(komponen(identifier, 2, pembatas), pembatas),
        value: bersihkanNull(bukaEscape(nilai, pembatas)),
        unit: bersihkanNull(komponen(field(s, 6, pembatas), 1, pembatas) || field(s, 6, pembatas).trim()),
        ref_range: rujukan,
        flags: bersihkanNull(field(s, 8, pembatas).trim()),
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
  // ORC-3 dan ORC-5 sengaja dikosongkan: manual menyatakan keduanya hanya
  // terisi pada arah ORM, bukan pada balasan ORR.
  baris.push(`ORC|AF|${bersih(sampleId || worklist.sample_id)}`);

  baris.push(bangunObrWorklist(worklist, bersih));

  // ------------------------------------------------------------------
  // Blok informasi sampel — struktur ORR^O02 menyebutnya "{[OBX]} Data
  // of other sample information, including work mode, etc."
  //
  // Manual mendefinisikan tiga butir (tabel D.4.5):
  //   08001 Take Mode   "O" open vial | "A" autoloading
  //   08002 Blood Mode  "W" whole blood | "P" prediluted
  //   08003 Test Mode   "CBC" | "CBC+5DIFF"
  //
  // Take Mode SENGAJA TIDAK DIKIRIM. Itu menyatakan apakah sampel dihisap
  // dari tabung terbuka atau lewat autoloader — keputusan operator di
  // depan alat, bukan sesuatu yang diketahui LIS. Mengirim tebakan dapat
  // memindahkan alat ke mode yang salah pada saat rak sudah terpasang.
  // Medan yang kosong dapat diisi operator; medan yang salah tidak
  // terlihat salah.
  // ------------------------------------------------------------------
  let n = 0;

  const bloodMode = modeDarah(worklist);
  if (bloodMode !== '') {
    baris.push(`OBX|${++n}|IS|08002^Blood Mode^99MRC||${bloodMode}||||||F`);
  }

  // Mode pemeriksaan: CBC bila order hanya memuat parameter dasar,
  // CBC+5DIFF bila hitung jenis leukosit ikut diminta.
  baris.push(`OBX|${++n}|IS|08003^Test Mode^99MRC||${modeUji(worklist.tests)}||||||F`);

  return baris.join('\r') + '\r';
}

/**
 * Blood Mode dari jenis spesimen.
 *
 * Hanya dikirim bila jenis spesimennya jelas. Darah vena EDTA dijalankan
 * sebagai whole blood; sampel kapiler yang memang sudah diencerkan
 * dijalankan sebagai prediluted. Bila tidak yakin, medan dikosongkan —
 * pengenceran adalah keputusan di meja kerja, bukan sesuatu yang boleh
 * disimpulkan LIS dari nama tabung.
 */
function modeDarah(worklist) {
  const teks = `${worklist.specimen_code || ''} ${worklist.specimen_name || ''}`.toUpperCase();

  if (/PREDILUT|ENCER/.test(teks)) return 'P';
  if (/EDTA|VENA|VENOUS|WHOLE|DARAH LENGKAP|WB/.test(teks)) return 'W';

  return '';
}

/**
 * Susun segmen OBR untuk jawaban worklist Mindray.
 *
 * PENOMORAN MEDANNYA BERBEDA DARI HL7 UMUM — ini sumber kekeliruan yang
 * mudah terjadi. Menurut BC-5800 LIS Protocol Manual tabel 12-13:
 *
 *   OBR-4   Universal Service ID      "00001^Automated Count^99MRC"
 *   OBR-5   (tidak didefinisikan)     dikosongkan
 *   OBR-6   Requested Date/time       "to express the SAMPLING date and
 *                                      time" → inilah Draw Time
 *   OBR-7   Observation Date/Time     "Run Time" → kosong pada worklist,
 *                                      karena pemeriksaannya belum berjalan
 *   OBR-10  Collector Identifier      "to indicate the deliverer"
 *   OBR-13  Relevant Clinical Info    diagnosis klinis
 *   OBR-14  Specimen Received         "to express the DELIVERY time"
 *   OBR-15  Specimen Source           BLDV vena / BLDC kapiler
 *   OBR-24  Diagnostic Service ID     "HM" untuk hematologi
 *
 * Dua jebakan yang sudah kami masuki:
 *
 *   1. HL7 umum menaruh waktu pengambilan pada OBR-7. Mindray menaruhnya
 *      pada OBR-6, dan memakai OBR-7 untuk waktu ALAT MENJALANKAN sampel.
 *      Menukar keduanya membuat layar alat menampilkan Draw Time kosong.
 *   2. OBR-5 pada HL7 umum adalah Priority. Tabel Mindray melompatinya —
 *      contoh resminya pun mengosongkannya. Mengisinya membuat sebagian
 *      firmware menggeser pembacaan medan sesudahnya.
 */
function bangunObrWorklist(worklist, bersih) {
  const waktu = (t) => {
    if (t === null || t === undefined || t === '') return '';
    const d = new Date(String(t).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}`
      + `${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
  };

  // Sumber spesimen. Hanya darah vena dan kapiler yang dikenali alat
  // hematologi; jenis lain dibiarkan kosong daripada dipaksakan.
  const kode = String(worklist.specimen_code || '').toUpperCase();
  const nama = String(worklist.specimen_name || '').toUpperCase();
  let sumber = '';
  if (/KAPILER|CAPILLARY|BLDC/.test(kode + ' ' + nama)) {
    sumber = 'BLDC';
  } else if (/VENA|VENOUS|EDTA|WB|DARAH|BLOOD|BLDV/.test(kode + ' ' + nama)) {
    sumber = 'BLDV';
  }

  const f = [];
  f[1]  = '1';
  f[2]  = bersih(worklist.sample_id);        // Placer Order Number
  f[3]  = '';                                 // Filler — kosong pada ORR
  f[4]  = '00001^Automated Count^99MRC';      // Universal Service ID
  f[5]  = '';                                 // tidak didefinisikan Mindray
  f[6]  = waktu(worklist.drawn_at);           // DRAW TIME
  f[7]  = '';                                 // Run Time — belum dijalankan
  f[8]  = '';
  f[9]  = '';
  f[10] = bersih(worklist.deliverer || worklist.collector); // pengantar
  f[11] = '';
  f[12] = '';
  f[13] = bersih(worklist.diagnosis);         // info klinis
  f[14] = waktu(worklist.delivered_at);       // DELIVERY TIME
  f[15] = sumber;                             // BLDV / BLDC
  for (let i = 16; i <= 23; i++) f[i] = '';
  f[24] = 'HM';                               // Diagnostic Service Sect ID

  // Buang medan kosong di ekor supaya pesan tidak berakhir dengan
  // deretan pemisah tanpa isi.
  let akhir = f.length - 1;
  while (akhir > 4 && (f[akhir] === '' || f[akhir] === undefined)) akhir--;

  const isi = [];
  for (let i = 1; i <= akhir; i++) isi.push(f[i] === undefined ? '' : f[i]);

  return 'OBR|' + isi.join('|');
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
