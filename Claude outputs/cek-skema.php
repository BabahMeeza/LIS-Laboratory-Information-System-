<?php
/**
 * Pemetaan skema lab pada database utama Khanza.
 *
 *   http://localhost/khanza-connector/cek-skema.php
 *
 * Hanya MEMBACA struktur — tidak menyentuh satu baris data pun, dan tidak
 * menampilkan isi kolom yang memuat identitas pasien.
 *
 * Dipakai ketika database bridging kosong sementara Khanza jelas sudah
 * berisi permintaan lab: kita perlu tahu nama tabel dan kolom yang
 * sebenarnya dipakai versi Khanza ini, bukan menebaknya.
 *
 * Hapus setelah selesai.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$konfig = include __DIR__ . '/config.php';
if (!is_array($konfig)) { exit("config.php tidak terbaca.\n"); }

function sambung(array $c) {
    return new PDO(
        'mysql:host=' . $c['host'] . ';port=' . (isset($c['port']) ? (int) $c['port'] : 3306)
        . ';dbname=' . $c['name'] . ';charset=' . (isset($c['charset']) ? $c['charset'] : 'utf8'),
        $c['user'], isset($c['pass']) ? $c['pass'] : '',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5)
    );
}

$garis = str_repeat('=', 66);
echo "$garis\n  Skema lab pada database Khanza\n$garis\n\n";

/*
 * Nomor order yang hendak dilacak, opsional:
 *
 *   cek-skema.php?noorder=PK202609190066
 *
 * Dipakai untuk menjawab satu pertanyaan yang tidak bisa dijawab dengan
 * membaca daftar tabel: nomor itu SEBENARNYA tersimpan di database dan
 * tabel yang mana. Konektor menolak dengan 404 "tidak ditemukan pada
 * permintaan_lab" ketika ia mencari di tempat yang salah, dan dari luar
 * pesan itu tidak membedakan "ordernya belum ada" dari "ordernya ada,
 * tetapi bukan di database yang kita tunjuk".
 */
$lacak = isset($_GET['noorder']) ? trim((string) $_GET['noorder']) : '';

/* ----------------------------------------------------------------
 * 0. Dua sambungan berdampingan
 *
 * db_bridging dan db_sik sering mengarah ke database BERBEDA, dan di
 * situlah kekeliruan yang paling mahal bersembunyi: konektor menulis
 * hasil ke satu database sementara Khanza membacanya dari yang lain.
 * Keduanya "berhasil", tidak ada galat, dan hasilnya tidak pernah
 * bertemu. Karena itu keduanya ditampilkan berdampingan lebih dulu.
 * ---------------------------------------------------------------- */

echo "0. Sambungan\n" . str_repeat('-', 66) . "\n";
foreach (array('db_bridging', 'db_sik') as $bagian) {
    if (!isset($konfig[$bagian])) {
        printf("  %-12s (tidak dikonfigurasi)\n", $bagian);
        continue;
    }
    $c = $konfig[$bagian];
    try {
        $uji = sambung($c);
        $n   = (int) $uji->query('SELECT 1')->fetchColumn();
        printf("  %-12s %s@%s:%s/%s  OK\n", $bagian, $c['user'], $c['host'],
            isset($c['port']) ? $c['port'] : 3306, $c['name']);
    } catch (Exception $e) {
        printf("  %-12s %s@%s/%s  GAGAL: %s\n", $bagian, $c['user'], $c['host'],
            $c['name'], $e->getMessage());
    }
}
if (isset($konfig['db_bridging']['name'], $konfig['db_sik']['name'])
    && $konfig['db_bridging']['name'] !== $konfig['db_sik']['name']) {
    echo "\n  Keduanya menunjuk database BERBEDA. Itu boleh saja, tetapi\n";
    echo "  pastikan permintaan_lab yang dibaca konektor adalah yang sama\n";
    echo "  dengan yang diisi Khanza — lihat bagian 4.\n";
}
echo "\n";

try {
    $pdo  = sambung($konfig['db_sik']);
    $nama = $konfig['db_sik']['name'];
} catch (Exception $e) {
    exit('Gagal menyambung db_sik: ' . $e->getMessage() . "\n");
}

echo "Database: $nama\n\n";

// 1. Tabel yang namanya berbau laboratorium.
echo "1. Tabel bernuansa lab\n" . str_repeat('-', 66) . "\n";
$q = $pdo->prepare(
    "SELECT table_name, table_rows
       FROM information_schema.tables
      WHERE table_schema = ?
        AND (table_name LIKE '%lab%' OR table_name LIKE '%permintaan%'
             OR table_name LIKE '%periksa%' OR table_name LIKE '%template%')
      ORDER BY table_name"
);
$q->execute(array($nama));
$tabel = $q->fetchAll(PDO::FETCH_ASSOC);

foreach ($tabel as $t) {
    $n = isset($t['table_name']) ? $t['table_name'] : $t['TABLE_NAME'];
    try { $jml = (int) $pdo->query("SELECT COUNT(*) FROM `$n`")->fetchColumn(); }
    catch (Exception $e) { $jml = -1; }
    printf("  %-42s %s\n", $n, $jml < 0 ? '(tak terbaca)' : $jml . ' baris');
}
if ($tabel === array()) { echo "  (tidak ada)\n"; }

// 2. Struktur tabel yang benar-benar kita butuhkan.
$perlu = array('permintaan_lab', 'permintaan_detail_permintaan_lab',
               'permintaan_pemeriksaan_lab',
               'reg_periksa', 'pasien', 'poliklinik', 'penjab', 'dokter',
               'kamar_inap', 'bangsal', 'kamar');

echo "\n2. Struktur tabel kunci\n" . str_repeat('-', 66) . "\n";
foreach ($perlu as $t) {
    try {
        $kolom = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        continue;   // tabel tidak ada pada versi ini
    }
    $nm = array();
    foreach ($kolom as $k) { $nm[] = isset($k['Field']) ? $k['Field'] : reset($k); }
    echo "\n  $t\n    " . implode(', ', $nm) . "\n";
}

// 3. Contoh baris permintaan_lab — TANPA identitas pasien.
echo "\n3. Contoh isi permintaan_lab (identitas disamarkan)\n" . str_repeat('-', 66) . "\n";
try {
    $rows = $pdo->query('SELECT * FROM permintaan_lab ORDER BY 1 DESC LIMIT 4')
                ->fetchAll(PDO::FETCH_ASSOC);

    $sensitif = array('nm_pasien', 'alamat', 'email', 'tgl_lahir', 'tmp_lahir', 'no_rkm_medis');

    foreach ($rows as $i => $r) {
        echo "\n  --- baris " . ($i + 1) . " ---\n";
        foreach ($r as $k => $v) {
            if (in_array(strtolower($k), $sensitif, true)) { $v = '(disamarkan)'; }
            printf("    %-24s %s\n", $k, $v === null ? 'NULL' : $v);
        }
    }
    if ($rows === array()) { echo "  (kosong)\n"; }
} catch (Exception $e) {
    echo '  Gagal membaca: ' . $e->getMessage() . "\n";
}

/* ----------------------------------------------------------------
 * 4. Lacak satu nomor order di KEDUA database
 *
 * Inilah pengukuran yang menggantikan tebakan. Setiap tabel yang punya
 * kolom bernama noorder ditanyai langsung: apakah nomor ini ada padamu?
 * Jawabannya menunjuk database dan tabel yang benar tanpa perlu tahu
 * versi Khanza mana yang dipakai.
 * ---------------------------------------------------------------- */

if ($lacak !== '') {
    echo "\n4. Jejak nomor order \"$lacak\"\n" . str_repeat('-', 66) . "\n";

    $ketemu = 0;
    foreach (array('db_bridging', 'db_sik') as $bagian) {
        if (!isset($konfig[$bagian])) { continue; }

        try {
            $p2 = sambung($konfig[$bagian]);
            $nm = $konfig[$bagian]['name'];
        } catch (Exception $e) {
            printf("  %-12s tidak tersambung\n", $bagian);
            continue;
        }

        $q2 = $p2->prepare(
            "SELECT table_name FROM information_schema.columns
              WHERE table_schema = ? AND column_name = 'noorder'
              ORDER BY table_name"
        );
        $q2->execute(array($nm));
        $daftar = $q2->fetchAll(PDO::FETCH_COLUMN);

        if ($daftar === array()) {
            printf("  %s (%s): tidak ada tabel berkolom noorder\n", $bagian, $nm);
            continue;
        }

        foreach ($daftar as $t) {
            try {
                $c2 = $p2->prepare("SELECT COUNT(*) FROM `$t` WHERE noorder = ?");
                $c2->execute(array($lacak));
                $jml = (int) $c2->fetchColumn();
            } catch (Exception $e) {
                continue;
            }

            if ($jml > 0) {
                printf("  ADA   %-14s %-34s %d baris\n", $nm, $t, $jml);
                $ketemu++;
            }
        }
    }

    if ($ketemu === 0) {
        echo "  Nomor ini tidak ditemukan di database mana pun yang dikonfigurasi.\n";
        echo "  Berarti ordernya memang belum tersimpan di sisi Khanza — bukan\n";
        echo "  soal salah tabel. Periksa apakah permintaannya sudah disimpan.\n";
    } else {
        echo "\n  Konektor mencari permintaan_lab pada db_bridging ("
             . $konfig['db_bridging']['name'] . ").\n";
        echo "  Bila baris di atas menunjukkan nomor itu ada di database LAIN,\n";
        echo "  ubah db_bridging pada config.php agar menunjuk database itu.\n";
    }
}

echo "\n$garis\n";
echo "Yang dicari: tabel mana yang memuat RINCIAN pemeriksaan per order,\n";
echo "dan kolom apa yang menghubungkannya ke permintaan_lab (biasanya noorder).\n";
echo "$garis\n\nHapus cek-skema.php setelah selesai.\n";
