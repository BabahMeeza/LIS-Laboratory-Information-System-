<?php
/**
 * @var array<string,mixed> $lot
 * @var array<int,array<string,mixed>> $hasil  kronologis (lama → baru) untuk grafik
 * @var array<int,array<string,mixed>> $tabel  terbaru lebih dulu
 * @var array<string,mixed> $statistik
 * @var float|null $cv
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e    = static fn ($v) => Helper::e($v);
$mean = (float) $lot['mean'];
$sd   = (float) $lot['sd'];

// Grafik Levey-Jennings: skala ±4 SD.
$lebar   = 760;
$tinggi  = 230;
$padKiri = 54;
$padAtas = 14;
$plotW   = $lebar - $padKiri - 12;
$plotH   = $tinggi - $padAtas - 24;
$n       = max(1, count($hasil));

$yUntukZ = static fn (float $z): float => $padAtas + (($z + 4) / 8) * $plotH; // z=+4 di atas
$xUntukI = static fn (int $i) => $padKiri + ($n <= 1 ? $plotW / 2 : ($i / ($n - 1)) * $plotW);
?>
<div class="kartu">
    <div class="kepala">
        <h2><?= $e($lot['nama_test']) ?> — <?= $e($lot['nama_bahan']) ?> Level <?= $e($lot['level']) ?></h2>
        <div class="kanan">
            <span class="kecil redup">Lot <?= $e($lot['lot']) ?><?= $lot['nama_alat'] !== null ? ' · ' . $e($lot['nama_alat']) : '' ?></span>
            <a class="tombol kecil" href="<?= $e(Url::to('/qc')) ?>">Daftar QC</a>
        </div>
    </div>
    <div class="isi">
        <div class="grid k4">
            <div><div class="label kecil redup">Target (Mean)</div><div class="tebal mono" style="font-size:18px"><?= $e(rtrim(rtrim((string) $lot['mean'], '0'), '.')) ?></div></div>
            <div><div class="label kecil redup">SD</div><div class="tebal mono" style="font-size:18px"><?= $e(rtrim(rtrim((string) $lot['sd'], '0'), '.')) ?></div></div>
            <div><div class="label kecil redup">Mean Aktual (30 hari)</div>
                <div class="tebal mono" style="font-size:18px"><?= $statistik['mean_aktual'] === null ? '—' : $e(number_format((float) $statistik['mean_aktual'], 3)) ?></div>
                <div class="kecil redup">n = <?= (int) ($statistik['n'] ?? 0) ?></div>
            </div>
            <div><div class="label kecil redup">CV Aktual</div>
                <div class="tebal mono" style="font-size:18px"><?= $cv === null ? '—' : $e(number_format($cv, 2)) . '%' ?></div>
                <?php if ($lot['cv_target'] !== null): ?>
                    <div class="kecil <?= ($cv !== null && $cv > (float) $lot['cv_target']) ? 'nilai-abnormal' : 'redup' ?>">
                        target &le; <?= $e(rtrim(rtrim((string) $lot['cv_target'], '0'), '.')) ?>%
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Grafik Levey-Jennings (<?= count($hasil) ?> titik terakhir)</h2></div>
    <div class="isi">
        <?php if ($hasil === []): ?>
            <div class="kosong">Belum ada data QC untuk lot ini.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <svg viewBox="0 0 <?= $lebar ?> <?= $tinggi ?>" width="100%" style="max-width:<?= $lebar ?>px;min-width:520px">
            <rect x="<?= $padKiri ?>" y="<?= $padAtas ?>" width="<?= $plotW ?>" height="<?= $plotH ?>" fill="#fbfcfd" stroke="#dde2e8"/>

            <?php
            $garis = [
                [3, '#b3261e', '+3s'], [2, '#8a6100', '+2s'], [1, '#c3ccd6', '+1s'],
                [0, '#1f7a4d', 'Mean'],
                [-1, '#c3ccd6', '-1s'], [-2, '#8a6100', '-2s'], [-3, '#b3261e', '-3s'],
            ];
            foreach ($garis as [$z, $warna, $label]):
                $y = $yUntukZ((float) $z);
                ?>
                <line x1="<?= $padKiri ?>" y1="<?= round($y, 1) ?>" x2="<?= $padKiri + $plotW ?>" y2="<?= round($y, 1) ?>"
                      stroke="<?= $warna ?>" stroke-width="<?= $z === 0 ? 1.6 : 1 ?>"
                      stroke-dasharray="<?= $z === 0 ? '' : '4 3' ?>"/>
                <text x="4" y="<?= round($y + 3.5, 1) ?>" font-size="10" fill="<?= $warna ?>" font-family="monospace">
                    <?= $label ?>
                </text>
                <text x="<?= $padKiri - 6 ?>" y="<?= round($y + 3.5, 1) ?>" font-size="9" fill="#5d6570"
                      text-anchor="end" font-family="monospace">
                    <?= $e(number_format($mean + $z * $sd, 2)) ?>
                </text>
            <?php endforeach; ?>

            <?php
            $titik = [];
            foreach ($hasil as $i => $h) {
                $z = max(-4.0, min(4.0, (float) $h['z_score']));
                $titik[] = round($xUntukI($i), 1) . ',' . round($yUntukZ($z), 1);
            }
            ?>
            <polyline points="<?= implode(' ', $titik) ?>" fill="none" stroke="#1d5f9e" stroke-width="1.4"/>

            <?php foreach ($hasil as $i => $h): ?>
                <?php
                $z  = max(-4.0, min(4.0, (float) $h['z_score']));
                $cx = round($xUntukI($i), 1);
                $cy = round($yUntukZ($z), 1);
                $fill = match ((string) $h['status']) {
                    'out'     => '#b3261e',
                    'warning' => '#8a6100',
                    default   => '#1d5f9e',
                };
                ?>
                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= (string) $h['status'] === 'in' ? 3 : 4.4 ?>" fill="<?= $fill ?>">
                    <title><?= $e(Helper::tanggalPendek($h['tgl_uji'])) ?> — <?= $e($h['nilai']) ?> (z = <?= $e(number_format((float) $h['z_score'], 2)) ?>)<?= $h['westgard'] !== null ? ' — ' . $e($h['westgard']) : '' ?></title>
                </circle>
            <?php endforeach; ?>
        </svg>
        </div>
        <p class="kecil redup mb0">
            Titik biru = terkendali, kuning = peringatan (1-2s), merah = aturan penolakan dilanggar.
            Arahkan kursor ke titik untuk melihat nilai dan tanggalnya.
        </p>
        <?php endif; ?>
    </div>
</div>

<div class="grid k2">
    <?php if (Auth::can('qc.entri')): ?>
    <div class="kartu">
        <div class="kepala"><h2>Entri Hasil QC</h2></div>
        <div class="isi">
            <form method="post" action="<?= $e(Url::to('/qc/lot/' . $lot['id'] . '/entri')) ?>">
                <?= Csrf::field() ?>
                <div class="grid k2">
                    <div class="form-baris">
                        <label for="nilai">Nilai Terukur <span style="color:var(--merah)">*</span></label>
                        <input type="text" id="nilai" name="nilai" required inputmode="decimal"
                               style="font-family:var(--mono)" autofocus>
                    </div>
                    <div class="form-baris">
                        <label for="tgl_uji">Waktu Uji</label>
                        <input type="datetime-local" id="tgl_uji" name="tgl_uji" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>
                <div class="form-baris">
                    <label for="catatan">Catatan</label>
                    <input type="text" id="catatan" name="catatan" placeholder="Reagen lot baru, kalibrasi ulang, dsb.">
                </div>
                <div class="form-baris">
                    <label for="tindakan">Tindakan Perbaikan (bila di luar kendali)</label>
                    <input type="text" id="tindakan" name="tindakan">
                </div>
                <button class="tombol utama" type="submit">Simpan Hasil QC</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="kartu">
        <div class="kepala"><h2>Riwayat (<?= count($tabel) ?>)</h2></div>
        <div class="isi rapat">
            <div class="tabel-bungkus" style="max-height:420px;overflow-y:auto">
                <table class="tabel rapat">
                    <thead><tr><th>Waktu</th><th class="angka">Nilai</th><th class="angka">z</th><th>Westgard</th><th>Status</th><th>Oleh</th></tr></thead>
                    <tbody>
                    <?php foreach ($tabel as $h): ?>
                        <tr>
                            <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($h['tgl_uji'])) ?></td>
                            <td class="angka mono"><?= $e(rtrim(rtrim((string) $h['nilai'], '0'), '.')) ?></td>
                            <td class="angka mono"><?= $e(number_format((float) $h['z_score'], 2)) ?></td>
                            <td class="kecil"><?= $e($h['westgard'] ?? '-') ?></td>
                            <td><span class="badge <?= (string) $h['status'] === 'out' ? 'bahaya' : ((string) $h['status'] === 'warning' ? 'hati' : 'sukses') ?>">
                                <?= $e(match ((string) $h['status']) { 'out' => 'Ditolak', 'warning' => 'Peringatan', default => 'Terkendali' }) ?>
                            </span></td>
                            <td class="kecil redup"><?= $e($h['oleh'] ?? '-') ?></td>
                        </tr>
                        <?php if (($h['tindakan'] ?? '') !== ''): ?>
                            <tr><td colspan="6" class="kecil" style="padding-left:18px;color:var(--kuning)">
                                Tindakan: <?= $e($h['tindakan']) ?>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($tabel === []): ?>
                        <tr><td colspan="6" class="kosong">Belum ada data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
