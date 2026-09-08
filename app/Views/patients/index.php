<?php
/** @var array<int,array<string,mixed>> $pasien @var int $total @var int $hal @var int $per @var string $cari */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$halaman = (int) ceil($total / max(1, $per));
?>
<div class="kartu">
    <div class="kepala">
        <h2>Data Pasien <span class="redup kecil">(<?= number_format($total, 0, ',', '.') ?>)</span></h2>
        <div class="kanan">
            <?php if (Auth::can('pasien.kelola')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/pasien/baru')) ?>">Pasien Baru</a>
            <?php endif; ?>
        </div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($cari) ?>" placeholder="No. RM, nama, atau NIK" style="min-width:280px">
        </div>
        <button class="tombol utama" type="submit">Cari</button>
        <?php if ($cari !== ''): ?><a class="tombol" href="<?= $e(Url::to('/pasien')) ?>">Reset</a><?php endif; ?>
    </form>

    <div class="isi rapat">
        <?php if ($pasien === []): ?>
            <div class="kosong"><div class="besar">Tidak ada data</div>Ubah kata kunci pencarian atau daftarkan pasien baru.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr>
                    <th>No. RM</th><th>RM Khanza</th><th>Nama</th><th>JK</th><th>Tgl Lahir</th><th>Umur</th>
                    <th class="angka">Order</th><th>Terakhir</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pasien as $p): ?>
                    <tr>
                        <td class="mono"><?= $e($p['no_rm']) ?></td>
                        <td class="mono kecil redup"><?= $e($p['khanza_no_rkm_medis'] ?? '-') ?></td>
                        <td><a href="<?= $e(Url::to('/pasien/' . $p['id'])) ?>"><b><?= $e($p['nama']) ?></b></a></td>
                        <td><?= $e($p['jk']) ?></td>
                        <td class="kecil"><?= $e(Helper::tanggalPendek($p['tgl_lahir'], false)) ?></td>
                        <td class="kecil redup"><?= $e(Helper::umurTeks($p['tgl_lahir'])) ?></td>
                        <td class="angka"><?= (int) $p['jml_order'] ?></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($p['order_terakhir'], false)) ?></td>
                        <td class="nowrap">
                            <?php if (Auth::can('order.buat')): ?>
                                <a class="tombol kecil utama" href="<?= $e(Url::to('/order/baru?pasien_id=' . $p['id'])) ?>">Order</a>
                            <?php endif; ?>
                            <a class="tombol kecil" href="<?= $e(Url::to('/pasien/' . $p['id'] . '/riwayat')) ?>">Riwayat</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($halaman > 1): ?>
            <div class="paginasi">
                <?php for ($i = max(1, $hal - 3); $i <= min($halaman, $hal + 3); $i++): ?>
                    <?php if ($i === $hal): ?>
                        <span class="aktif"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= $e(Url::withQuery(['hal' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <span class="redup" style="border:0;background:none">Halaman <?= $hal ?> dari <?= $halaman ?></span>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
