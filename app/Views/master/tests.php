<?php
/** @var array<int,array<string,mixed>> $tests @var array<int,array<string,mixed>> $kategori @var array<string,mixed> $filter */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Master Pemeriksaan <span class="redup kecil">(<?= count($tests) ?>)</span></h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/master/paket')) ?>">Paket Pemeriksaan</a>
            <?php if (Auth::can('master.kelola')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/master/pemeriksaan/baru')) ?>">Pemeriksaan Baru</a>
            <?php endif; ?>
        </div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris">
            <label>Kategori</label>
            <select name="kategori">
                <option value="0">Semua kategori</option>
                <?php foreach ($kategori as $k): ?>
                    <option value="<?= (int) $k['id'] ?>" <?= (int) $filter['kategoriId'] === (int) $k['id'] ? 'selected' : '' ?>><?= $e($k['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($filter['cari']) ?>" placeholder="Kode, nama, atau LOINC" style="min-width:250px">
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
        <input type="search" data-saring="#tabel-master" placeholder="Saring cepat…" style="width:180px">
    </form>

    <div class="isi rapat">
        <?php if ($tests === []): ?>
            <div class="kosong"><div class="besar">Tidak ada pemeriksaan</div>Sesuaikan filter atau tambahkan pemeriksaan baru.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel" id="tabel-master">
                <thead>
                <tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Spesimen</th><th>Satuan</th><th>Tipe</th>
                    <th class="angka">Rujukan</th><th class="angka">Tarif</th><th>Khanza</th><th>Aktif</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tests as $t): ?>
                    <tr style="<?= (int) $t['aktif'] === 0 ? 'opacity:.55' : '' ?>">
                        <td class="mono"><b><?= $e($t['kode']) ?></b></td>
                        <td>
                            <?= $e($t['nama']) ?>
                            <?php if ((int) $t['is_kritis'] === 1): ?><span class="badge bahaya" title="Dipantau nilai kritis">K</span><?php endif; ?>
                            <?php if (($t['loinc'] ?? '') !== ''): ?>
                                <div class="kecil redup mono">LOINC <?= $e($t['loinc']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="kecil"><?= $e($t['kategori'] ?? '-') ?></td>
                        <td class="kecil redup"><?= $e($t['spesimen'] ?? '-') ?></td>
                        <td class="kecil redup"><?= $e($t['satuan'] ?? '') ?></td>
                        <td class="kecil"><?= $e(ucfirst((string) $t['tipe_hasil'])) ?></td>
                        <td class="angka">
                            <?php if ((int) $t['jml_rujukan'] === 0): ?>
                                <span class="badge hati">belum ada</span>
                            <?php else: ?><?= (int) $t['jml_rujukan'] ?><?php endif; ?>
                        </td>
                        <td class="angka kecil"><?= $e(number_format((float) $t['harga'], 0, ',', '.')) ?></td>
                        <td class="kecil mono redup">
                            <?= $e($t['khanza_kd_jenis_prw'] ?? '-') ?><?= $t['khanza_id_template'] !== null ? '/' . (int) $t['khanza_id_template'] : '' ?>
                        </td>
                        <td><span class="badge <?= (int) $t['aktif'] === 1 ? 'sukses' : 'redup' ?>"><?= (int) $t['aktif'] === 1 ? 'Ya' : 'Tidak' ?></span></td>
                        <td class="nowrap">
                            <a class="tombol kecil" href="<?= $e(Url::to('/master/pemeriksaan/' . $t['id'] . '/rujukan')) ?>">Rujukan</a>
                            <?php if (Auth::can('master.kelola')): ?>
                                <a class="tombol kecil" href="<?= $e(Url::to('/master/pemeriksaan/' . $t['id'] . '/edit')) ?>">Ubah</a>
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
