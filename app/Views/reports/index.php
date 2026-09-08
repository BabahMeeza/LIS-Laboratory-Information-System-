<?php
/**
 * @var array<string,mixed> $rekap
 * @var array<int,array<string,mixed>> $perKategori
 * @var array<int,array<string,mixed>> $terbanyak
 * @var array<int,array<string,mixed>> $penolakan
 * @var string $dari @var string $sampai
 */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$n = static fn ($v) => (int) ($v ?? 0);
$maks = 1;
foreach ($terbanyak as $t) { $maks = max($maks, (int) $t['jml']); }
?>
<div class="kartu">
    <div class="kepala">
        <h2>Rekap Laboratorium</h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/laporan/tat?dari=' . $dari . '&sampai=' . $sampai)) ?>">Analisis TAT</a>
            <a class="tombol kecil" href="<?= $e(Url::to('/laporan/nilai-kritis?dari=' . $dari . '&sampai=' . $sampai)) ?>">Register Nilai Kritis</a>
            <a class="tombol kecil" href="<?= $e(Url::to('/laporan/produktivitas?dari=' . $dari . '&sampai=' . $sampai)) ?>">Produktivitas</a>
            <?php if (Auth::can('laporan.ekspor')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/laporan/ekspor?dari=' . $dari . '&sampai=' . $sampai)) ?>">Ekspor CSV</a>
            <?php endif; ?>
        </div>
    </div>
    <form class="filter" method="get">
        <div class="form-baris"><label>Dari</label><input type="date" name="dari" value="<?= $e($dari) ?>"></div>
        <div class="form-baris"><label>Sampai</label><input type="date" name="sampai" value="<?= $e($sampai) ?>"></div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>
</div>

<div class="grid k6" style="margin-bottom:18px">
    <div class="stat info"><div class="label">Total Order</div><div class="angka"><?= $n($rekap['total_order'] ?? 0) ?></div></div>
    <div class="stat sukses"><div class="label">Dirilis</div><div class="angka"><?= $n($rekap['dirilis'] ?? 0) ?></div></div>
    <div class="stat bahaya"><div class="label">CITO</div><div class="angka"><?= $n($rekap['cito'] ?? 0) ?></div></div>
    <div class="stat"><div class="label">Rawat Jalan</div><div class="angka"><?= $n($rekap['ralan'] ?? 0) ?></div></div>
    <div class="stat"><div class="label">Rawat Inap</div><div class="angka"><?= $n($rekap['ranap'] ?? 0) ?></div></div>
    <div class="stat"><div class="label">Dibatalkan</div><div class="angka"><?= $n($rekap['dibatalkan'] ?? 0) ?></div></div>
</div>

<div class="grid k2">
    <div class="kartu">
        <div class="kepala"><h2>Volume per Kategori</h2></div>
        <div class="isi rapat">
            <table class="tabel">
                <thead><tr><th>Kategori</th><th class="angka">Jumlah</th><th class="angka">Nilai Tarif</th></tr></thead>
                <tbody>
                <?php foreach ($perKategori as $k): ?>
                    <tr>
                        <td><?= $e($k['kategori'] ?? 'Lain-lain') ?></td>
                        <td class="angka"><?= $n($k['jml']) ?></td>
                        <td class="angka"><?= $e(Helper::rupiah($k['biaya'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($perKategori === []): ?>
                    <tr><td colspan="3" class="kosong">Tidak ada data pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($perKategori !== []): ?>
                <tfoot>
                <tr style="background:#f7f9fb;font-weight:600">
                    <td>Total</td>
                    <td class="angka"><?= array_sum(array_column($perKategori, 'jml')) ?></td>
                    <td class="angka"><?= $e(Helper::rupiah((float) ($rekap['total_biaya'] ?? 0))) ?></td>
                </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Pemeriksaan Terbanyak</h2></div>
        <div class="isi rapat">
            <table class="tabel rapat">
                <tbody>
                <?php foreach ($terbanyak as $t): ?>
                    <tr>
                        <td style="width:44%"><?= $e($t['nama']) ?><div class="kecil redup mono"><?= $e($t['kode']) ?></div></td>
                        <td>
                            <div class="progress"><span style="width:<?= (int) round(($n($t['jml']) / $maks) * 100) ?>%"></span></div>
                        </td>
                        <td class="angka" style="width:60px"><?= $n($t['jml']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($terbanyak === []): ?>
                    <tr><td class="kosong">Tidak ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kepala">
        <h2>Penolakan Spesimen (indikator mutu pra-analitik)</h2>
    </div>
    <div class="isi rapat">
        <?php if ($penolakan === []): ?>
            <div class="kosong"><div class="besar">Tidak ada penolakan spesimen</div>Pada periode ini seluruh spesimen diterima.</div>
        <?php else: ?>
        <table class="tabel">
            <thead><tr><th>Kondisi</th><th>Alasan</th><th class="angka">Jumlah</th></tr></thead>
            <tbody>
            <?php foreach ($penolakan as $p): ?>
                <tr>
                    <td><?= $e(ucfirst(str_replace('_', ' ', (string) $p['kondisi']))) ?></td>
                    <td class="kecil"><?= $e($p['alasan_tolak']) ?></td>
                    <td class="angka"><?= $n($p['jml']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
