<?php
/**
 * @var array<int,array<string,mixed>> $perOrder
 * @var array<int,array<string,mixed>> $kategori
 * @var array<int,array<string,mixed>> $alat
 * @var array<string,mixed>            $filter
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$bidirectional = array_values(array_filter($alat, static fn ($a) => $a['mode'] === 'bidirectional'));
?>
<form method="post" action="<?= $e(Url::to('/worklist/kirim-alat')) ?>">
<?= Csrf::field() ?>

<div class="kartu">
    <div class="kepala">
        <h2>Worklist <span class="redup kecil">(<?= count($perOrder) ?> order)</span></h2>
        <div class="kanan">
            <?php if ($bidirectional !== [] && Auth::can('alat.kelola')): ?>
                <select name="instrument_id" style="width:auto">
                    <?php foreach ($bidirectional as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= $e($a['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="tombol utama" type="submit">Kirim Worklist ke Alat</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="filter">
        <div class="form-baris">
            <label>Kategori</label>
            <select name="kategori" form="filter-worklist">
                <option value="0">Semua kategori</option>
                <?php foreach ($kategori as $k): ?>
                    <option value="<?= (int) $k['id'] ?>" <?= (int) $filter['kategoriId'] === (int) $k['id'] ? 'selected' : '' ?>><?= $e($k['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Alat</label>
            <select name="alat" form="filter-worklist">
                <option value="0">Semua alat</option>
                <?php foreach ($alat as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) $filter['alatId'] === (int) $a['id'] ? 'selected' : '' ?>><?= $e($a['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Prioritas</label>
            <select name="prioritas" form="filter-worklist">
                <option value="">Semua</option>
                <option value="cito" <?= $filter['prioritas'] === 'cito' ? 'selected' : '' ?>>CITO</option>
                <option value="rutin" <?= $filter['prioritas'] === 'rutin' ? 'selected' : '' ?>>Rutin</option>
            </select>
        </div>
        <div class="form-baris">
            <label>Tampilkan</label>
            <select name="tampil" form="filter-worklist">
                <option value="belum" <?= $filter['tampil'] === 'belum' ? 'selected' : '' ?>>Belum ada hasil</option>
                <option value="semua" <?= $filter['tampil'] === 'semua' ? 'selected' : '' ?>>Semua</option>
            </select>
        </div>
        <button class="tombol utama" type="submit" form="filter-worklist">Terapkan</button>
        <input type="search" data-saring="#tabel-worklist" placeholder="Saring cepat…" style="width:200px">
    </div>

    <div class="isi rapat">
        <?php if ($perOrder === []): ?>
            <div class="kosong">
                <div class="besar">Worklist kosong</div>
                Tidak ada spesimen diterima yang menunggu dikerjakan.
            </div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel" id="tabel-worklist">
                <thead>
                <tr>
                    <th style="width:32px"><input type="checkbox" data-centang-semua='input[name="order_ids[]"]'></th>
                    <th>Barcode</th><th>No. Lab</th><th>Pasien</th><th>Pemeriksaan</th>
                    <th>Umur Order</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($perOrder as $o): ?>
                    <?php
                    $telat = $o['umur_menit'] > $o['tat_menit'];
                    ?>
                    <tr class="<?= $o['prioritas'] === 'cito' ? 'cito' : '' ?>">
                        <td><input type="checkbox" name="order_ids[]" value="<?= (int) $o['order_id'] ?>"></td>
                        <td class="mono"><b><?= $e($o['barcode']) ?></b></td>
                        <td class="mono kecil"><?= $e($o['no_lab'] ?? '-') ?>
                            <?php if ($o['prioritas'] === 'cito'): ?><div><span class="badge bahaya">CITO</span></div><?php endif; ?>
                        </td>
                        <td>
                            <b><?= $e($o['nama_pasien']) ?></b>
                            <div class="kecil redup">RM <?= $e($o['no_rm']) ?> &middot; <?= $e($o['jk']) ?> &middot; <?= $e(Helper::umurTeks($o['tgl_lahir'])) ?></div>
                        </td>
                        <td>
                            <?php foreach ($o['items'] as $i): ?>
                                <span class="pil" title="<?= $e($i['nama_test']) ?>">
                                    <?= $e($i['kode_test']) ?>
                                    <?php if ($i['result_id'] !== null): ?>
                                        <span style="color:var(--hijau)">&#10003;</span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td class="kecil <?= $telat ? 'nilai-abnormal' : 'redup' ?>">
                            <?= $e(Helper::durasi((int) $o['umur_menit'])) ?>
                            <div class="kecil redup">target <?= $e(Helper::durasi((int) $o['tat_menit'])) ?></div>
                        </td>
                        <td class="nowrap">
                            <?php if (Auth::can('hasil.entri')): ?>
                                <a class="tombol kecil utama" href="<?= $e(Url::to('/hasil/' . $o['order_id'] . '/entri')) ?>">Entri Hasil</a>
                            <?php endif; ?>
                            <a class="tombol kecil" href="<?= $e(Url::to('/order/' . $o['order_id'])) ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</form>

<form method="get" id="filter-worklist"></form>
