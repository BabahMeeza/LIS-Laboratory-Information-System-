<?php
/** @var array<int,array<string,mixed>> $antrian @var array<string,string> $filter */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Antrian Verifikasi <span class="redup kecil">(<?= count($antrian) ?>)</span></h2>
        <div class="kanan"><input type="search" data-saring="#tabel-verifikasi" placeholder="Saring cepat…" style="width:220px"></div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris">
            <label>Prioritas</label>
            <select name="prioritas">
                <option value="">Semua</option>
                <option value="cito" <?= $filter['prioritas'] === 'cito' ? 'selected' : '' ?>>CITO</option>
                <option value="rutin" <?= $filter['prioritas'] === 'rutin' ? 'selected' : '' ?>>Rutin</option>
            </select>
        </div>
        <div class="form-baris">
            <label>Asal</label>
            <select name="asal">
                <option value="">Semua</option>
                <?php foreach (['ralan' => 'Rawat Jalan', 'ranap' => 'Rawat Inap', 'igd' => 'IGD', 'luar' => 'Luar', 'mcu' => 'MCU'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter['asal'] === $k ? 'selected' : '' ?>><?= $e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>

    <div class="isi rapat">
        <?php if ($antrian === []): ?>
            <div class="kosong">
                <div class="besar">Tidak ada antrian verifikasi</div>
                Semua hasil sudah divalidasi.
            </div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel" id="tabel-verifikasi">
                <thead>
                <tr>
                    <th>No. Lab</th><th>Pasien</th><th>Asal</th>
                    <th class="angka">Hasil</th><th class="angka">Abnormal</th><th class="angka">Kritis</th><th class="angka">Delta</th>
                    <th>Umur</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($antrian as $a): ?>
                    <tr class="<?= $a['prioritas'] === 'cito' ? 'cito' : '' ?>">
                        <td class="mono">
                            <b><?= $e($a['no_lab'] ?? '-') ?></b>
                            <?php if ($a['prioritas'] === 'cito'): ?><span class="badge bahaya">CITO</span><?php endif; ?>
                            <?php if ($a['khanza_noorder'] !== null): ?><div><span class="badge ungu">Khanza</span></div><?php endif; ?>
                        </td>
                        <td>
                            <b><?= $e($a['nama_pasien']) ?></b>
                            <div class="kecil redup">RM <?= $e($a['no_rm']) ?> &middot; <?= $e($a['jk']) ?> &middot; <?= $e(Helper::umurTeks($a['tgl_lahir'])) ?></div>
                        </td>
                        <td class="kecil"><?= $e(ucfirst((string) $a['asal'])) ?><div class="redup"><?= $e(Helper::potong($a['dokter_perujuk'], 20)) ?></div></td>
                        <td class="angka"><?= (int) $a['jml_hasil'] ?> / <?= (int) $a['jml_item'] ?></td>
                        <td class="angka"><?= (int) $a['jml_abnormal'] > 0 ? '<span class="badge hati">' . (int) $a['jml_abnormal'] . '</span>' : '-' ?></td>
                        <td class="angka"><?= (int) $a['jml_kritis'] > 0 ? '<span class="badge bahaya">' . (int) $a['jml_kritis'] . '</span>' : '-' ?></td>
                        <td class="angka"><?= (int) $a['jml_delta'] > 0 ? '<span class="badge info">' . (int) $a['jml_delta'] . '</span>' : '-' ?></td>
                        <td class="kecil <?= (int) $a['umur_menit'] > 240 ? 'nilai-abnormal' : 'redup' ?>"><?= $e(Helper::durasi((int) $a['umur_menit'])) ?></td>
                        <td><a class="tombol kecil utama" href="<?= $e(Url::to('/verifikasi/' . $a['id'])) ?>">Buka</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
