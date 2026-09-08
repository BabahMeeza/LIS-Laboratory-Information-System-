<?php
declare(strict_types=1);

/**
 * Siapkan kredensial API untuk konektor SIMRS Khanza, sampai terbukti jalan.
 *
 * LATAR
 * -----
 * Menyambungkan Khanza ke LIS memerlukan empat hal yang harus benar
 * bersamaan, dan setiap satu yang meleset muncul sebagai 401 atau 403 yang
 * bunyinya mirip:
 *
 *   1. kredensial aktif
 *   2. cakupannya memuat "khanza"        <- instrument saja tidak cukup
 *   3. batas IP memuat alamat pemanggil  <- di mesin yang sama biasanya ::1
 *   4. secret_enc dapat didekripsi       <- rusak bila app_key pernah diganti
 *
 * Skrip ini membereskan keempatnya sekaligus, lalu MENJALANKAN ULANG
 * pemeriksaan yang sama persis dengan ApiAuth::wajib() memakai kredensial
 * yang baru saja ditulis. Yang dilaporkan di akhir adalah hasil pemeriksaan
 * itu, bukan keyakinan skrip ini.
 *
 * HANYA CLI.
 *
 * PEMAKAIAN
 * ---------
 *   php bin/siapkan-khanza.php
 *       Periksa saja. Tidak menulis apa pun.
 *
 *   php bin/siapkan-khanza.php --terapkan
 *       Perbaiki kredensial Khanza: aktifkan, pastikan cakupan "khanza",
 *       longgarkan batas IP yang mengunci loopback, dan tulis ulang secret.
 *
 *   php bin/siapkan-khanza.php --terapkan --tulis-xml=/path/ke/setting/database.xml
 *       Sekalian tuliskan URLAPILISHANAU / APIKEYLISHANAU / APISECRETLISHANAU
 *       ke berkas pengaturan Khanza, sehingga tidak ada nilai yang disalin
 *       dengan tangan.
 *
 *   Pilihan lain:
 *     --nama="..."      nama kredensial yang dipakai/dibuat
 *     --url=http://...  alamat LIS yang ditulis ke database.xml
 *     --secret-baru     paksa buat secret baru walau yang lama masih terbaca
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;

// ---------------------------------------------------------------------
// Konfigurasi, dimuat persis seperti App::bootConfig()
// ---------------------------------------------------------------------
$berkasConfig = BASE_PATH . '/config/config.php';
if (!is_file($berkasConfig)) {
    exit("config/config.php tidak ditemukan.\n");
}
Config::load(require $berkasConfig);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

if (Config::get('db.host') === null) {
    exit("config/config.php termuat tetapi db.host tidak terbaca. Berhenti.\n");
}

// ---------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------
$opsi = [
    'terapkan'     => false,
    'secret-baru'  => false,
    'nama'         => 'SIMRS Khanza',
    'url'          => '',
    'tulis-xml'    => '',
];

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terapkan')    { $opsi['terapkan'] = true;    continue; }
    if ($a === '--secret-baru') { $opsi['secret-baru'] = true; continue; }

    foreach (['nama', 'url', 'tulis-xml'] as $k) {
        if (str_starts_with($a, '--' . $k . '=')) {
            $opsi[$k] = substr($a, strlen($k) + 3);
        }
    }
}

function samar(string $v, int $depan = 8): string
{
    $n = strlen($v);

    return $n <= $depan + 4 ? str_repeat('*', $n) : substr($v, 0, $depan) . '......' . substr($v, -4) . " [{$n}]";
}

function judul(string $t): void
{
    echo "\n" . $t . "\n" . str_repeat('-', 69) . "\n";
}

echo "=====================================================================\n";
echo " SIAPKAN KREDENSIAL KHANZA   " . date('Y-m-d H:i:s T') . "\n";
echo " mode: " . ($opsi['terapkan'] ? 'TERAPKAN' : 'PERIKSA SAJA') . "\n";
echo "=====================================================================\n";

// ---------------------------------------------------------------------
// 1. Enkripsi harus hidup sebelum menyentuh kredensial apa pun
// ---------------------------------------------------------------------
judul('1. Enkripsi');

if (!Crypto::tersedia()) {
    exit("   Crypto tidak tersedia. Isi security.app_key di config/config.php\n"
       . "   dengan nilai acak: php -r \"echo bin2hex(random_bytes(32));\"\n");
}

$cobaan = 'uji-' . bin2hex(random_bytes(8));
if (Crypto::dekripsi(Crypto::enkripsi($cobaan)) !== $cobaan) {
    exit("   Uji bolak-balik GAGAL. Berhenti sebelum menulis apa pun.\n");
}
echo "   uji bolak-balik : BERHASIL\n";

$appKey = (string) Config::get('security.app_key', '');

// ---------------------------------------------------------------------
// 2. Pilih kredensial yang akan dipakai konektor Khanza
// ---------------------------------------------------------------------
judul('2. Kredensial ber-cakupan "khanza"');

$semua = Database::select(
    'SELECT id, nama, api_key, scopes, aktif, ip_whitelist, secret_enc, secret_hash
       FROM api_clients ORDER BY id'
);

$punyaKhanza = static function (array $c): bool {
    $s = array_map('trim', explode(',', (string) $c['scopes']));

    return in_array('khanza', $s, true) || in_array('admin', $s, true);
};

foreach ($semua as $c) {
    printf(
        "   #%-2d %-30s %-9s %-20s %s\n",
        $c['id'],
        substr((string) $c['nama'], 0, 30),
        $c['aktif'] ? 'aktif' : 'NONAKTIF',
        (string) $c['scopes'],
        $punyaKhanza($c) ? '<= layak' : ''
    );
}

// Utamakan yang sudah aktif dan sudah bercakupan khanza.
$pilih = null;
foreach ($semua as $c) {
    if ($punyaKhanza($c) && (int) $c['aktif'] === 1) { $pilih = $c; break; }
}
if ($pilih === null) {
    foreach ($semua as $c) {
        if ($punyaKhanza($c)) { $pilih = $c; break; }
    }
}
if ($pilih === null) {
    foreach ($semua as $c) {
        if (strcasecmp((string) $c['nama'], $opsi['nama']) === 0) { $pilih = $c; break; }
    }
}

if ($pilih === null) {
    echo "\n   Tidak ada kredensial bercakupan \"khanza\".\n";
    if (!$opsi['terapkan']) {
        echo "   Jalankan dengan --terapkan untuk membuatkannya.\n";
    }
} else {
    echo "\n   dipilih : #{$pilih['id']} {$pilih['nama']}\n";
}

if (!$opsi['terapkan']) {
    judul('Selesai (periksa saja)');
    echo "   Tidak ada yang diubah. Tambahkan --terapkan untuk memperbaiki.\n";
    exit;
}

// ---------------------------------------------------------------------
// 3. Terapkan perbaikan
// ---------------------------------------------------------------------
judul('3. Perbaikan');

$secret = bin2hex(random_bytes(24));

if ($pilih === null) {
    $apiKey = 'lis_' . bin2hex(random_bytes(16));
    $id     = Database::insert('api_clients', [
        'nama'         => $opsi['nama'],
        'api_key'      => $apiKey,
        'secret_hash'  => password_hash($secret, PASSWORD_BCRYPT),
        'secret_enc'   => Crypto::enkripsi($secret),
        'scopes'       => 'khanza',
        'ip_whitelist' => null,
        'aktif'        => 1,
    ]);
    echo "   kredensial baru dibuat: #{$id} {$opsi['nama']}\n";
} else {
    $id     = (int) $pilih['id'];
    $apiKey = (string) $pilih['api_key'];
    $ubah   = [];

    // (a) cakupan — tambahkan "khanza" tanpa membuang yang sudah ada
    $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $pilih['scopes']))));
    if (!in_array('khanza', $scopes, true) && !in_array('admin', $scopes, true)) {
        $scopes[]        = 'khanza';
        $ubah['scopes']  = implode(',', $scopes);
        echo "   cakupan    : ditambah \"khanza\" -> " . $ubah['scopes'] . "\n";
    } else {
        echo "   cakupan    : sudah memuat khanza (" . $pilih['scopes'] . ")\n";
    }

    // (b) aktif
    if ((int) $pilih['aktif'] !== 1) {
        $ubah['aktif'] = 1;
        echo "   status     : diaktifkan\n";
    } else {
        echo "   status     : sudah aktif\n";
    }

    // (c) batas IP — hanya disentuh bila justru mengunci pemanggil lokal
    $wl = trim((string) ($pilih['ip_whitelist'] ?? ''));
    if ($wl === '') {
        echo "   batas IP   : kosong (semua alamat) — dibiarkan\n";
    } else {
        $m = new ReflectionMethod(\App\Core\ApiAuth::class, 'ipDiizinkan');
        $m->setAccessible(true);

        $lolosLokal = $m->invoke(null, '::1', $wl) && $m->invoke(null, '127.0.0.1', $wl);
        if ($lolosLokal) {
            echo "   batas IP   : {$wl} — sudah menerima loopback, dibiarkan\n";
        } else {
            $ubah['ip_whitelist'] = $wl . ', lokal';
            echo "   batas IP   : {$wl} tidak menerima ::1 -> ditambah \"lokal\"\n";
        }
    }

    // (d) secret — ditulis ulang bila tidak terbaca, atau bila diminta
    $lama = null;
    try {
        $lama = Crypto::dekripsi($pilih['secret_enc'] ?? null);
    } catch (\Throwable) {
        $lama = null;
    }

    if ($lama !== null && $lama !== '' && !$opsi['secret-baru']) {
        echo "   secret     : masih terbaca, dipakai lagi\n";
        $secret = $lama;
    } else {
        $ubah['secret_hash'] = password_hash($secret, PASSWORD_BCRYPT);
        $ubah['secret_enc']  = Crypto::enkripsi($secret);
        echo "   secret     : " . ($lama === null ? 'tidak terbaca' : 'diminta baru') . " -> dibuat baru\n";
    }

    if ($ubah !== []) {
        Database::update('api_clients', $ubah, 'id = ?', [$id]);
    }
}

// ---------------------------------------------------------------------
// 4. Uji ulang dengan pemeriksaan yang sama persis dengan ApiAuth
// ---------------------------------------------------------------------
judul('4. Uji ulang (dibaca ulang dari database)');

$c = Database::selectOne(
    'SELECT id, nama, api_key, scopes, aktif, ip_whitelist, secret_enc FROM api_clients WHERE id = ?',
    [$id]
);

if ($c === null) {
    exit("   Kredensial #{$id} hilang setelah ditulis. Berhenti.\n");
}

$badanContoh = '{"orders":[{"noorder":"UJI"}]}';
$lulus       = true;

/** @param bool $ok */
$periksa = static function (string $nama, bool $ok, string $ket = '') use (&$lulus): void {
    if (!$ok) {
        $lulus = false;
    }
    printf("   %-34s %s%s\n", $nama, $ok ? 'LULUS' : 'GAGAL', $ket === '' ? '' : '  — ' . $ket);
};

$periksa('kredensial ditemukan', true);
$periksa('aktif', (int) $c['aktif'] === 1);

$scopes = array_map('trim', explode(',', (string) $c['scopes']));
$periksa(
    'cakupan memuat "khanza"',
    in_array('khanza', $scopes, true) || in_array('admin', $scopes, true),
    (string) $c['scopes']
);

$m = new ReflectionMethod(\App\Core\ApiAuth::class, 'ipDiizinkan');
$m->setAccessible(true);
$wl = trim((string) ($c['ip_whitelist'] ?? ''));

foreach (['::1', '127.0.0.1', '192.168.0.108'] as $ipUji) {
    $periksa(
        'batas IP menerima ' . $ipUji,
        $wl === '' ? true : (bool) $m->invoke(null, $ipUji, $wl),
        $wl === '' ? 'daftar kosong' : $wl
    );
}

$secretDb = Crypto::dekripsi($c['secret_enc'] ?? null);
$periksa('secret_enc dapat didekripsi', $secretDb !== null && $secretDb !== '');

if ($secretDb !== null && $secretDb !== '') {
    // Inilah perhitungan yang persis dilakukan ApiAuth atas raw body.
    $tandaTangan = hash_hmac('sha256', $badanContoh, $secretDb);
    $periksa(
        'X-Signature cocok atas contoh body',
        hash_equals(hash_hmac('sha256', $badanContoh, $secret), $tandaTangan)
    );
}

// ---------------------------------------------------------------------
// 5. Nilai untuk setting/database.xml
// ---------------------------------------------------------------------
judul('5. Nilai untuk Khanza (setting/database.xml)');

$url = $opsi['url'] !== '' ? $opsi['url'] : (string) Config::get('app.url', '');
if ($url === '') {
    $url = 'http://' . (gethostbyname(gethostname()) ?: '127.0.0.1') . ':8080';
}
$url = rtrim($url, '/');

$nilaiXml = [
    'URLAPILISHANAU'    => $url,
    'APIKEYLISHANAU'    => (string) $c['api_key'],
    'APISECRETLISHANAU' => $secret,
];

foreach ($nilaiXml as $k => $v) {
    printf("   <entry key=\"%s\">%s</entry>\n", $k, $v);
}

if ($opsi['tulis-xml'] !== '') {
    $berkasXml = $opsi['tulis-xml'];

    if (!is_file($berkasXml)) {
        echo "\n   database.xml tidak ditemukan di {$berkasXml}. Dilewati.\n";
    } elseif (!is_writable($berkasXml)) {
        echo "\n   database.xml tidak dapat ditulis: {$berkasXml}\n";
    } else {
        $isi = (string) file_get_contents($berkasXml);
        $awal = $isi;

        foreach ($nilaiXml as $k => $v) {
            $vEsc = htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $pola = '/<entry\s+key="' . preg_quote($k, '/') . '"\s*>.*?<\/entry>/s';

            if (preg_match($pola, $isi) === 1) {
                $isi = preg_replace($pola, '<entry key="' . $k . '">' . $vEsc . '</entry>', $isi, 1);
            } else {
                // Sisipkan sebelum penutup, jangan ditempel di akhir berkas.
                $isi = preg_replace(
                    '/<\/properties>/',
                    '<entry key="' . $k . '">' . $vEsc . "</entry>\n</properties>",
                    $isi,
                    1
                );
            }
        }

        // Baca ulang sebagai XML sebelum menimpa. Berkas pengaturan yang
        // rusak membuat Khanza gagal berjalan seluruhnya, bukan cuma gagal
        // menghubungi LIS.
        $cek = @simplexml_load_string($isi);
        if ($cek === false) {
            echo "\n   Hasil suntingan bukan XML yang sah. TIDAK ditulis.\n";
        } elseif ($isi === $awal) {
            echo "\n   database.xml sudah berisi nilai yang sama. Tidak diubah.\n";
        } else {
            $cadangan = $berkasXml . '.bak-' . date('YmdHis');
            copy($berkasXml, $cadangan);
            file_put_contents($berkasXml, $isi);
            echo "\n   database.xml diperbarui. Cadangan: " . basename($cadangan) . "\n";
        }
    }
} else {
    echo "\n   Salin ketiga baris di atas ke setting/database.xml milik Khanza,\n";
    echo "   atau jalankan lagi dengan --tulis-xml=/path/ke/setting/database.xml\n";
}

judul($lulus ? 'HASIL: SIAP DIPAKAI' : 'HASIL: MASIH ADA YANG GAGAL');

if ($lulus) {
    echo "   Seluruh pemeriksaan yang dilakukan ApiAuth lulus dengan kredensial ini.\n";
    echo "   Sisa langkah ada di Khanza: Clean and Build, lalu tekan tombolnya.\n";
} else {
    echo "   Perhatikan baris GAGAL di bagian 4 di atas.\n";
}
