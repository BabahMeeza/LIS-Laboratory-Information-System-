<?php
/** @var array<int,array<string,mixed>> $orders @var int $total @var int $hal @var int $per @var array<string,string> $filter */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$halaman = (int) ceil($total / max(1, $per));

$statusOpsi = ['' => 'Semua status', 'ordered' => 'Terdaftar', 'collected' => 'Sampel Diambil',
    'received' => 'Sampel Diterima', 'in_progress' => 'Sedang Diperiksa', 'resulted' => 'Ada Hasil',
    'verified' => 'Terverifikasi', 'released' => 'Dirilis', 'cancelled' => 'Dibatalkan'];
$asalOpsi = ['' => 'Semua asal', 'ralan' => 'Rawat Jalan', 'ranap' => 'Rawat Inap',
    'igd' => 'IGD', 'luar' => 'Rujukan Luar', 'mcu' => 'MCU'];
?>
<div class="kartu">
    <div class="kepala">
        <h2>Order Pemeriksaan <span class="redup kecil">(<?= number_format($total, 0, ',', '.') ?>)</span></h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/spesimen/label-batch')) ?>" target="_blank">Cetak Label Hari Ini</a>
            <?php if (Auth::can('order.buat')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/order/baru')) ?>">Order Baru</a>
            <?php endif; ?>
        </div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris"><label>Dari</label><input type="date" name="dari" value="<?= $e($filter['dari']) ?>"></div>
        <div class="form-baris"><label>Sampai</label><input type="date" name="sampai" value="<?= $e($filter['sampai']) ?>"></div>
        <div class="form-baris">
            <label>Status</label>
            <select name="status">
                <?php foreach ($statusOpsi as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter['status'] === $k ? 'selected' : '' ?>><?= $e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Asal</label>
            <select name="asal">
                <?php foreach ($asalOpsi as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter['asal'] === $k ? 'selected' : '' ?>><?= $e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Prioritas</label>
            <select name="prioritas">
                <option value="">Semua</option>
                <option value="cito" <?= $filter['prioritas'] === 'cito' ? 'selected' : '' ?>>CITO</option>
                <option value="rutin" <?= $filter['prioritas'] === 'rutin' ? 'selected' : '' ?>>Rutin</option>
            </select>
        </div>
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($filter['cari']) ?>" placeholder="No. lab / order / nama / RM" style="min-width:240px">
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
        <a class="tombol" href="<?= $e(Url::to('/order')) ?>">Reset</a>
    </form>

    <div class="isi rapat">
        <?php if ($orders === []): ?>
            <div class="kosong"><div class="besar">Tidak ada order</div>Sesuaikan rentang tanggal atau filter.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr>
                    <th>Waktu</th><th>No. Lab</th><th>Barcode</th><th>Pasien</th><th>Asal</th>
                    <th class="angka">Item</th><th class="angka">Hasil</th><th>Status</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr class="<?= $o['prioritas'] === 'cito' ? 'cito' : '' ?>">
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($o['tgl_order'])) ?></td>
                        <td class="mono">
                            <a href="<?= $e(Url::to('/order/' . $o['id'])) ?>"><b><?= $e($o['no_lab'] ?? '-') ?></b></a>
                            <?php if ($o['prioritas'] === 'cito'): ?><span class="badge bahaya">CITO</span><?php endif; ?>
                            <div class="kecil redup"><?= $e($o['no_order']) ?></div>
                        </td>
                        <td class="mono kecil"><?= $e($o['barcode'] ?? '-') ?></td>
                        <td>
                            <b><?= $e($o['nama_pasien']) ?></b>
                            <div class="kecil redup">RM <?= $e($o['no_rm']) ?> &middot; <?= $e($o['jk']) ?> &middot; <?= $e(Helper::umurTeks($o['tgl_lahir'])) ?></div>
                        </td>
                        <td class="kecil">
                            <?= $e(ucfirst((string) $o['asal'])) ?>
                            <?php if ($o['khanza_noorder'] !== null): ?>
                                <div><span class="badge ungu" title="Order dari SIMRS Khanza">Khanza</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="angka"><?= (int) $o['jml_item'] ?></td>
                        <td class="angka"><?= (int) $o['jml_hasil'] ?></td>
                        <td><span class="badge <?= $e(Helper::warnaStatus((string) $o['status'])) ?>"><?= $e(Helper::labelStatus((string) $o['status'])) ?></span></td>
                        <td class="nowrap">
                            <?php if (Auth::can('hasil.entri') && !in_array((string) $o['status'], ['cancelled', 'released'], true)): ?>
                                <a class="tombol kecil" href="<?= $e(Url::to('/hasil/' . $o['id'] . '/entri')) ?>">Hasil</a>
                            <?php endif; ?>
                            <?php if (in_array((string) $o['status'], ['verified', 'released'], true)): ?>
                                <a class="tombol kecil utama" href="<?= $e(Url::to('/laporan/hasil/' . $o['id'])) ?>" target="_blank">Cetak</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($halaman > 1): ?>
            <div class="paginasi">
                <?php for ($i = max(1, $hal - 3); $i <= min($halaman, $hal + 3); $i++): ?>
                    <?php if ($i === $hal): ?><span class="aktif"><?= $i ?></span>
                    <?php else: ?><a href="<?= $e(Url::withQuery(['hal' => $i])) ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <span class="redup" style="border:0;background:none">Halaman <?= $hal ?> dari <?= $halaman ?></span>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
