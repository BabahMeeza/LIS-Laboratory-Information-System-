<?php
/**
 * Layout utama aplikasi.
 *
 * @var string $judul
 * @var string $konten
 */

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e     = static fn ($v) => Helper::e($v);
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base  = Url::base();
$aktif = static function (string $prefix) use ($path, $base): string {
    $rel = $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    $rel = '/' . ltrim($rel, '/');

    if ($prefix === '/') {
        return $rel === '/' ? 'aktif' : '';
    }

    return str_starts_with($rel, $prefix) ? 'aktif' : '';
};

// Angka lencana pada menu — dihitung ringan, aman bila database belum siap.
$lencana = ['verifikasi' => 0, 'menggantung' => 0, 'kritis' => 0];
if (Auth::check()) {
    try {
        $lencana['verifikasi']  = (int) Database::scalar("SELECT COUNT(*) FROM orders WHERE status = 'resulted'");
        $lencana['menggantung'] = (int) Database::scalar("SELECT COUNT(*) FROM orphan_results WHERE status = 'menunggu'");
        $lencana['kritis']      = (int) Database::scalar('SELECT COUNT(*) FROM results WHERE is_kritis = 1 AND kritis_dilapor_at IS NULL');
    } catch (\Throwable) {
        // abaikan
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($judul !== '' ? $judul . ' — LIS' : 'LIS') ?></title>
<link rel="stylesheet" href="<?= $e(Url::asset('css/app.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>&#129514;</text></svg>">
</head>
<body>

<?php if (!Auth::check()): ?>
    <?= $konten ?>
<?php else: ?>
<div class="app">

    <aside class="sidebar tanpa-cetak">
        <div class="merek">
            <strong>LIS Khanza</strong>
            <span><?= $e(Config::setting('app.nama_lab', 'Laboratorium Klinik')) ?></span>
        </div>

        <nav>
            <a class="item <?= $aktif('/') ?>" href="<?= $e(Url::to('/')) ?>">Dashboard</a>

            <div class="grup">Alur Kerja</div>
            <?php if (Auth::can('order.lihat')): ?>
                <a class="item <?= $aktif('/order') ?>" href="<?= $e(Url::to('/order')) ?>">Order Pemeriksaan</a>
            <?php endif; ?>
            <?php if (Auth::can('spesimen.lihat')): ?>
                <a class="item <?= $aktif('/spesimen') ?>" href="<?= $e(Url::to('/spesimen/penerimaan')) ?>">Penerimaan Sampel</a>
            <?php endif; ?>
            <?php if (Auth::can('worklist.lihat')): ?>
                <a class="item <?= $aktif('/worklist') ?>" href="<?= $e(Url::to('/worklist')) ?>">Worklist</a>
            <?php endif; ?>
            <?php if (Auth::can('hasil.lihat')): ?>
                <a class="item <?= $aktif('/verifikasi') ?>" href="<?= $e(Url::to('/verifikasi')) ?>">
                    Verifikasi
                    <?php if ($lencana['verifikasi'] > 0): ?><span class="lencana"><?= $lencana['verifikasi'] ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <?php if (Auth::can('pasien.lihat')): ?>
                <a class="item <?= $aktif('/pasien') ?>" href="<?= $e(Url::to('/pasien')) ?>">Pasien</a>
            <?php endif; ?>

            <div class="grup">Alat &amp; Integrasi</div>
            <?php if (Auth::can('alat.lihat')): ?>
                <a class="item <?= $aktif('/alat') ?>" href="<?= $e(Url::to('/alat')) ?>">
                    Alat Laboratorium
                    <?php if ($lencana['menggantung'] > 0): ?><span class="lencana"><?= $lencana['menggantung'] ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <?php if (Auth::can('integrasi.lihat')): ?>
                <a class="item <?= $aktif('/integrasi') ?>" href="<?= $e(Url::to('/integrasi')) ?>">SIMRS Khanza</a>
            <?php endif; ?>
            <?php if (Auth::can('qc.lihat')): ?>
                <a class="item <?= $aktif('/qc') ?>" href="<?= $e(Url::to('/qc')) ?>">Kontrol Mutu</a>
            <?php endif; ?>
            <?php if (Auth::can('hasil.lihat')): ?>
                <a class="item <?= $aktif('/nilai-kritis') ?>" href="<?= $e(Url::to('/nilai-kritis')) ?>">
                    Nilai Kritis
                    <?php if ($lencana['kritis'] > 0): ?><span class="lencana"><?= $lencana['kritis'] ?></span><?php endif; ?>
                </a>
            <?php endif; ?>

            <div class="grup">Laporan</div>
            <?php if (Auth::canAny(['laporan.lihat', 'laporan.cetak'])): ?>
                <a class="item <?= $aktif('/laporan') ?>" href="<?= $e(Url::to('/laporan')) ?>">Rekap &amp; Laporan</a>
            <?php endif; ?>

            <?php if (Auth::canAny(['master.lihat', 'pengguna.kelola', 'pengaturan.kelola'])): ?>
                <div class="grup">Pengaturan</div>
                <?php if (Auth::can('master.lihat')): ?>
                    <a class="item <?= $aktif('/master') ?>" href="<?= $e(Url::to('/master/pemeriksaan')) ?>">Master Pemeriksaan</a>
                <?php endif; ?>
                <?php if (Auth::can('pengguna.kelola')): ?>
                    <a class="item <?= $aktif('/pengguna') ?>" href="<?= $e(Url::to('/pengguna')) ?>">Pengguna</a>
                <?php endif; ?>
                <?php if (Auth::can('pengaturan.kelola')): ?>
                    <a class="item <?= $aktif('/pengaturan') ?>" href="<?= $e(Url::to('/pengaturan')) ?>">Pengaturan Sistem</a>
                <?php endif; ?>
            <?php endif; ?>

            <div class="grup">Informasi</div>
            <a class="item <?= $aktif('/tentang') ?>" href="<?= $e(Url::to('/tentang')) ?>">Tentang Aplikasi</a>
        </nav>
    </aside>

    <div class="utama">
        <header class="topbar tanpa-cetak">
            <h1><?= $e($judul) ?></h1>
            <div class="kanan">
                <?php if ($lencana['kritis'] > 0): ?>
                    <?php // Sebelumnya lencana ini menunjuk ke dasbor — terlihat mendesak
                          // tetapi tidak membawa ke mana pun. Kini ia membuka antrean
                          // pelaporannya langsung. ?>
                    <a class="badge bahaya" href="<?= $e(Url::to('/nilai-kritis')) ?>"
                       title="Nilai kritis belum dilaporkan">
                        <?= $lencana['kritis'] ?> nilai kritis
                    </a>
                <?php endif; ?>
                <div class="pengguna">
                    <b><?= $e(Auth::nama()) ?></b>
                    <?= $e(ucfirst(Auth::role())) ?>
                </div>
                <a class="tombol kecil" href="<?= $e(Url::to('/profil')) ?>">Profil</a>
                <form method="post" action="<?= $e(Url::to('/logout')) ?>" style="margin:0">
                    <?= Csrf::field() ?>
                    <button class="tombol kecil" type="submit">Keluar</button>
                </form>
            </div>
        </header>

        <main class="konten">
            <?php foreach (Flash::ambil() as $pesan): ?>
                <div class="notif <?= $e($pesan['tipe']) ?>"><?= $e($pesan['pesan']) ?></div>
            <?php endforeach; ?>

            <?= $konten ?>
        </main>
    </div>
</div>
<?php endif; ?>

<script src="<?= $e(Url::asset('js/app.js')) ?>"></script>
</body>
</html>
