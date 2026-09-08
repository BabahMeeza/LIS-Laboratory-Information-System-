<?php
/**
 * @var array<string,mixed> $ringkasan
 * @var array<int,array<string,mixed>> $perPrioritas
 * @var array<int,array<string,mixed>> $perTest
 * @var string $dari @var string $sampai
 */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Analisis Turn Around Time</h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/laporan')) ?>">Rekap</a>
            <button class="tombol kecil tanpa-cetak" onclick="window.print()">Cetak</button>
        </div>
    </div>
    <form class="filter" method="get">
        <div class="form-baris"><label>Dari</label><input type="date" name="dari" value="<?= $e($dari) ?>"></div>
        <div class="form-baris"><label>Sampai</label><input type="date" name="sampai" value="<?= $e($sampai) ?>"></div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            TAT dihitung dari waktu order sampai hasil dirilis. Kepatuhan per pemeriksaan
            membandingkan waktu order sampai verifikasi terhadap target TAT pada master pemeriksaan.
        </p>
    </div>
</div>

<div class="grid k4" style="margin-bottom:18px">
    <div class="stat info">
        <div class="label">Order Dirilis</div>
        <div class="angka"><?= (int) ($ringkasan['jml'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label">Rata-rata TAT</div>
        <div class="angka" style="font-size:20px"><?= $e(Helper::durasi($ringkasan['rata2'] === null ? null : (int) round((float) $ringkasan['rata2']))) ?></div>
    </div>
    <div class="stat sukses">
        <div class="label">Tercepat</div>
        <div class="angka" style="font-size:20px"><?= $e(Helper::durasi($ringkasan['tercepat'] === null ? null : (int) $ringkasan['tercepat'])) ?></div>
    </div>
    <div class="stat bahaya">
        <div class="label">Terlama</div>
        <div class="angka" style="font-size:20px"><?= $e(Helper::durasi($ringkasan['terlama'] === null ? null : (int) $ringkasan['terlama'])) ?></div>
    </div>
</div>

<div class="grid k2">
    <div class="kartu">
        <div class="kepala"><h2>TAT per Prioritas</h2></div>
        <div class="isi rapat">
            <table class="tabel">
                <thead><tr><th>Prioritas</th><th class="angka">Jumlah</th><th class="angka">Rata-rata TAT</th></tr></thead>
                <tbody>
                <?php foreach ($perPrioritas as $p): ?>
                    <tr>
                        <td><?= $p['prioritas'] === 'cito' ? '<span class="badge bahaya">CITO</span>' : '<span class="badge netral">Rutin</span>' ?></td>
                        <td class="angka"><?= (int) $p['jml'] ?></td>
                        <td class="angka"><?= $e(Helper::durasi((int) round((float) $p['rata2']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($perPrioritas === []): ?>
                    <tr><td colspan="3" class="kosong">Belum ada order yang dirilis pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Kepatuhan Target TAT per Pemeriksaan</h2></div>
        <div class="isi rapat">
            <div class="tabel-bungkus" style="max-height:480px;overflow-y:auto">
                <table class="tabel rapat">
                    <thead><tr><th>Pemeriksaan</th><th class="angka">n</th><th class="angka">Target</th><th class="angka">Rata-rata</th><th>Kepatuhan</th></tr></thead>
                    <tbody>
                    <?php foreach ($perTest as $t): ?>
                        <?php
                        $jml   = (int) $t['jml'];
                        $tepat = (int) $t['tepat_waktu'];
                        $persen = $jml > 0 ? (int) round(($tepat / $jml) * 100) : 0;
                        $kelas  = $persen >= 90 ? '' : ($persen >= 75 ? 'hati' : 'bahaya');
                        ?>
                        <tr>
                            <td><?= $e($t['nama']) ?><div class="kecil redup mono"><?= $e($t['kode']) ?></div></td>
                            <td class="angka"><?= $jml ?></td>
                            <td class="angka kecil"><?= $e(Helper::durasi((int) $t['target'])) ?></td>
                            <td class="angka kecil"><?= $e(Helper::durasi((int) round((float) $t['rata2']))) ?></td>
                            <td style="min-width:120px">
                                <div class="progress <?= $kelas ?>"><span style="width:<?= $persen ?>%"></span></div>
                                <div class="kecil redup"><?= $persen ?>% (<?= $tepat ?>/<?= $jml ?>)</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($perTest === []): ?>
                        <tr><td colspan="5" class="kosong">Belum ada hasil terverifikasi pada periode ini.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
