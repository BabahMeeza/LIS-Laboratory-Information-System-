<?php
/** @var array<int,array<string,mixed>> $panels */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Paket Pemeriksaan <span class="redup kecil">(<?= count($panels) ?>)</span></h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/master/pemeriksaan')) ?>">Master Pemeriksaan</a>
            <?php if (Auth::can('master.kelola')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/master/paket/baru')) ?>">Paket Baru</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="isi rapat">
        <?php if ($panels === []): ?>
            <div class="kosong"><div class="besar">Belum ada paket</div>Paket mempercepat pemesanan pemeriksaan yang sering diminta bersama.</div>
        <?php else: ?>
        <table class="tabel">
            <thead><tr><th>Kode</th><th>Nama Paket</th><th>Kategori</th><th class="angka">Item</th>
                <th class="angka">Total Tarif Item</th><th class="angka">Tarif Paket</th><th>Aktif</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($panels as $p): ?>
                <tr>
                    <td class="mono"><b><?= $e($p['kode']) ?></b></td>
                    <td><?= $e($p['nama']) ?></td>
                    <td class="kecil redup"><?= $e($p['kategori'] ?? '-') ?></td>
                    <td class="angka"><?= (int) $p['jml_item'] ?></td>
                    <td class="angka kecil redup"><?= $e(Helper::rupiah($p['harga_item'])) ?></td>
                    <td class="angka">
                        <?php if ((float) $p['harga'] > 0): ?>
                            <?= $e(Helper::rupiah($p['harga'])) ?>
                        <?php else: ?>
                            <span class="kecil redup">ikut tarif item</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= (int) $p['aktif'] === 1 ? 'sukses' : 'redup' ?>"><?= (int) $p['aktif'] === 1 ? 'Ya' : 'Tidak' ?></span></td>
                    <td>
                        <?php if (Auth::can('master.kelola')): ?>
                            <a class="tombol kecil" href="<?= $e(Url::to('/master/paket/' . $p['id'] . '/edit')) ?>">Ubah</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
