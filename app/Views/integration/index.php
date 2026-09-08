<?php
/**
 * @var bool   $aktif @var string $baseUrl @var string $modeOrder @var string $kirimSaat
 * @var array<string,mixed> $statistik
 * @var array<int,array<string,mixed>> $terakhir
 * @var array<int,array<string,mixed>> $orderKhanza
 * @var int $belumDipetakan
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$n = static fn ($v) => (int) ($v ?? 0);
?>
<?php if (!$aktif): ?>
    <div class="notif peringatan">
        <b>Integrasi SIMRS Khanza nonaktif.</b>
        Aktifkan pada <a href="<?= $e(Url::to('/pengaturan')) ?>">Pengaturan Sistem</a> setelah mengisi
        URL konektor dan kredensial API.
    </div>
<?php endif; ?>

<div class="grid k4" style="margin-bottom:18px">
    <div class="stat info">
        <div class="label">Order Masuk Hari Ini</div>
        <div class="angka"><?= $n($statistik['order_masuk_hari_ini'] ?? 0) ?></div>
        <div class="catatan">dari SIMRS Khanza</div>
    </div>
    <div class="stat sukses">
        <div class="label">Hasil Terkirim Hari Ini</div>
        <div class="angka"><?= $n($statistik['hasil_terkirim_hari_ini'] ?? 0) ?></div>
        <div class="catatan">ke detail_hasil_lab</div>
    </div>
    <div class="stat <?= $n($statistik['antri'] ?? 0) > 0 ? 'hati' : '' ?>">
        <div class="label">Antrian Kirim</div>
        <div class="angka"><?= $n($statistik['antri'] ?? 0) ?></div>
        <div class="catatan">menunggu percobaan ulang</div>
    </div>
    <div class="stat <?= $n($statistik['gagal'] ?? 0) > 0 ? 'bahaya' : '' ?>">
        <div class="label">Gagal Permanen</div>
        <div class="angka"><?= $n($statistik['gagal'] ?? 0) ?></div>
        <div class="catatan">perlu tindakan manual</div>
    </div>
</div>

<div class="grid k2">
    <div class="kartu">
        <div class="kepala"><h2>Konfigurasi &amp; Tindakan</h2></div>
        <div class="isi">
            <dl class="rincian">
                <dt>Status</dt><dd><span class="badge <?= $aktif ? 'sukses' : 'redup' ?>"><?= $aktif ? 'Aktif' : 'Nonaktif' ?></span></dd>
                <dt>URL konektor</dt><dd class="mono kecil"><?= $e($baseUrl) ?: '<span class="redup">belum diisi</span>' ?></dd>
                <dt>Mode order</dt><dd><?= $e($modeOrder === 'push' ? 'Push — Khanza mengirim ke LIS' : 'Pull — LIS menarik berkala') ?></dd>
                <dt>Kirim hasil saat</dt><dd><?= $e($kirimSaat === 'rilis' ? 'Rilis hasil' : 'Verifikasi hasil') ?></dd>
                <dt>Belum dipetakan</dt><dd>
                    <?php if ($belumDipetakan > 0): ?>
                        <span class="badge hati"><?= $belumDipetakan ?> template</span>
                        <a class="kecil" href="<?= $e(Url::to('/integrasi/pemetaan')) ?>">Petakan sekarang</a>
                    <?php else: ?><span class="badge sukses">Lengkap</span><?php endif; ?>
                </dd>
            </dl>

            <div class="aksi-baris mt16">
                <form method="post" action="<?= $e(Url::to('/integrasi/uji-koneksi')) ?>" style="display:inline">
                    <?= Csrf::field() ?><button class="tombol" type="submit">Uji Koneksi</button>
                </form>
                <?php if (Auth::can('integrasi.kelola')): ?>
                    <form method="post" action="<?= $e(Url::to('/integrasi/tarik-order')) ?>" style="display:inline">
                        <?= Csrf::field() ?><button class="tombol" type="submit">Tarik Order Baru</button>
                    </form>
                    <form method="post" action="<?= $e(Url::to('/integrasi/impor-template')) ?>" style="display:inline">
                        <?= Csrf::field() ?><button class="tombol" type="submit">Impor Master Pemeriksaan</button>
                    </form>
                    <form method="post" action="<?= $e(Url::to('/integrasi/proses-antrian')) ?>" style="display:inline">
                        <?= Csrf::field() ?><button class="tombol utama" type="submit">Proses Antrian Kirim</button>
                    </form>
                <?php endif; ?>
                <a class="tombol" href="<?= $e(Url::to('/integrasi/log')) ?>">Log Lengkap</a>
            </div>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Aktivitas Terakhir</h2></div>
        <div class="isi rapat">
            <?php if ($terakhir === []): ?>
                <div class="kosong"><div class="besar">Belum ada aktivitas</div>Jalankan uji koneksi untuk memulai.</div>
            <?php else: ?>
            <table class="tabel rapat">
                <thead><tr><th>Waktu</th><th>Arah</th><th>Jenis</th><th>HTTP</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($terakhir as $t): ?>
                    <tr>
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($t['created_at'])) ?></td>
                        <td class="kecil"><?= $e($t['arah']) ?></td>
                        <td class="kecil"><?= $e($t['jenis']) ?></td>
                        <td class="kecil mono"><?= $e($t['http_code'] ?? '-') ?></td>
                        <td><span class="badge <?= $t['status'] === 'sukses' ? 'sukses' : ($t['status'] === 'antri' ? 'hati' : 'bahaya') ?>"><?= $e($t['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Order dari SIMRS Khanza</h2></div>
    <div class="isi rapat">
        <?php if ($orderKhanza === []): ?>
            <div class="kosong"><div class="besar">Belum ada order dari Khanza</div></div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead><tr><th>No. Order Khanza</th><th>No. Lab LIS</th><th>Pasien</th><th>Waktu</th><th>Status LIS</th><th>Hasil Terkirim</th></tr></thead>
                <tbody>
                <?php foreach ($orderKhanza as $o): ?>
                    <tr>
                        <td class="mono"><?= $e($o['khanza_noorder']) ?></td>
                        <td class="mono"><a href="<?= $e(Url::to('/order/' . $o['id'])) ?>"><?= $e($o['no_lab'] ?? $o['no_order']) ?></a></td>
                        <td><b><?= $e($o['nama_pasien']) ?></b><div class="kecil redup">RM <?= $e($o['no_rm']) ?></div></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($o['tgl_order'])) ?></td>
                        <td><span class="badge <?= $e(Helper::warnaStatus((string) $o['status'])) ?>"><?= $e(Helper::labelStatus((string) $o['status'])) ?></span></td>
                        <td>
                            <?php if ((int) $o['hasil_terkirim'] > 0): ?>
                                <span class="badge sukses">Terkirim</span>
                            <?php elseif (in_array((string) $o['status'], ['verified', 'released'], true)): ?>
                                <span class="badge hati">Menunggu</span>
                            <?php else: ?>
                                <span class="badge redup">Belum siap</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
