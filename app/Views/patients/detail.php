<?php
/** @var array<string,mixed> $pasien @var array<int,array<string,mixed>> $orders */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="grid k2">
    <div class="kartu">
        <div class="kepala">
            <h2><?= $e($pasien['nama']) ?></h2>
            <div class="kanan">
                <?php if (Auth::can('pasien.kelola')): ?>
                    <a class="tombol kecil" href="<?= $e(Url::to('/pasien/' . $pasien['id'] . '/edit')) ?>">Ubah</a>
                <?php endif; ?>
                <a class="tombol kecil" href="<?= $e(Url::to('/pasien/' . $pasien['id'] . '/riwayat')) ?>">Riwayat Hasil</a>
                <?php if (Auth::can('order.buat')): ?>
                    <a class="tombol kecil utama" href="<?= $e(Url::to('/order/baru?pasien_id=' . $pasien['id'])) ?>">Order Baru</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="isi">
            <dl class="rincian">
                <dt>No. RM</dt><dd class="mono"><?= $e($pasien['no_rm']) ?></dd>
                <dt>RM Khanza</dt><dd class="mono"><?= $e($pasien['khanza_no_rkm_medis'] ?? '-') ?></dd>
                <dt>NIK</dt><dd class="mono"><?= $e($pasien['nik'] ?? '-') ?></dd>
                <dt>Jenis kelamin</dt><dd><?= $e(Helper::jenisKelamin($pasien['jk'])) ?></dd>
                <dt>Tempat, tgl lahir</dt><dd><?= $e($pasien['tempat_lahir'] ?? '-') ?>, <?= $e(Helper::tanggal($pasien['tgl_lahir'])) ?></dd>
                <dt>Umur</dt><dd><?= $e(Helper::umurTeks($pasien['tgl_lahir'])) ?></dd>
                <dt>Gol. darah</dt><dd><?= $e($pasien['gol_darah'] ?? '-') ?></dd>
                <dt>Alamat</dt><dd><?= $e($pasien['alamat'] ?? '-') ?></dd>
                <dt>Telepon</dt><dd><?= $e($pasien['telepon'] ?? '-') ?></dd>
                <dt>Email</dt><dd><?= $e($pasien['email'] ?? '-') ?></dd>
            </dl>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Riwayat Order (<?= count($orders) ?>)</h2></div>
        <div class="isi rapat">
            <?php if ($orders === []): ?>
                <div class="kosong"><div class="besar">Belum ada order</div>Pasien ini belum pernah diperiksa.</div>
            <?php else: ?>
            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead><tr><th>Tanggal</th><th>No. Lab</th><th>Asal</th><th class="angka">Item</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($o['tgl_order'])) ?></td>
                            <td class="mono"><a href="<?= $e(Url::to('/order/' . $o['id'])) ?>"><?= $e($o['no_lab'] ?? $o['no_order']) ?></a></td>
                            <td class="kecil"><?= $e(ucfirst((string) $o['asal'])) ?></td>
                            <td class="angka"><?= (int) $o['jml_item'] ?></td>
                            <td><span class="badge <?= $e(Helper::warnaStatus((string) $o['status'])) ?>"><?= $e(Helper::labelStatus((string) $o['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
