<?php
/** @var array<int,array<string,mixed>> $log @var array<int,string> $daftarAksi @var array<string,string> $filter */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Jejak Audit</h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/pengaturan')) ?>">Pengaturan</a></div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Setiap tindakan yang mengubah data klinis tercatat di sini beserta pelaku, waktu, dan alamat IP.
            Catatan ini merupakan bukti telusur untuk keperluan akreditasi laboratorium.
        </p>
    </div>

    <form class="filter" method="get">
        <div class="form-baris"><label>Sejak</label><input type="date" name="dari" value="<?= $e($filter['dari']) ?>"></div>
        <div class="form-baris">
            <label>Aksi</label>
            <select name="aksi">
                <option value="">Semua aksi</option>
                <?php foreach ($daftarAksi as $a): ?>
                    <option value="<?= $e($a) ?>" <?= $filter['aksi'] === $a ? 'selected' : '' ?>><?= $e(str_replace('_', ' ', $a)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($filter['cari']) ?>" placeholder="Pelaku, deskripsi, atau ID" style="min-width:240px">
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>

    <div class="isi rapat">
        <?php if ($log === []): ?>
            <div class="kosong"><div class="besar">Tidak ada catatan</div>Perlebar rentang tanggal atau ubah filter.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead><tr><th>Waktu</th><th>Pelaku</th><th>Aksi</th><th>Objek</th><th>Keterangan</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($log as $l): ?>
                    <tr>
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($l['created_at'])) ?></td>
                        <td class="kecil"><?= $e($l['actor']) ?></td>
                        <td><span class="badge netral"><?= $e(str_replace('_', ' ', (string) $l['aksi'])) ?></span></td>
                        <td class="kecil mono redup">
                            <?php if ((string) $l['ref_type'] === 'order' && $l['ref_id'] !== null): ?>
                                <a href="<?= $e(Url::to('/order/' . $l['ref_id'])) ?>">order #<?= $e($l['ref_id']) ?></a>
                            <?php else: ?>
                                <?= $e($l['ref_type'] ?? '-') ?><?= $l['ref_id'] !== null ? ' #' . $e($l['ref_id']) : '' ?>
                            <?php endif; ?>
                        </td>
                        <td class="kecil" style="max-width:420px"><?= $e($l['deskripsi'] ?? '') ?></td>
                        <td class="kecil mono redup"><?= $e($l['ip'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
