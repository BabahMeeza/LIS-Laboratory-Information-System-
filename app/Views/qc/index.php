<?php
/** @var array<int,array<string,mixed>> $lots */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$warna = static fn (?string $s) => match ($s) {
    'out'     => 'bahaya',
    'warning' => 'hati',
    'in'      => 'sukses',
    default   => 'redup',
};
?>
<div class="kartu">
    <div class="kepala">
        <h2>Kontrol Mutu Internal <span class="redup kecil">(<?= count($lots) ?> bahan kontrol)</span></h2>
        <div class="kanan">
            <?php if (Auth::can('qc.kelola')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/qc/lot-baru')) ?>">Bahan Kontrol Baru</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Setiap nilai QC dievaluasi terhadap mean dan SD lot memakai aturan Westgard
            (1-2s, 1-3s, 2-2s, R-4s, 4-1s, 10x). Pelanggaran aturan penolakan menandakan
            proses tidak terkendali — hentikan pemeriksaan pasien untuk parameter tersebut,
            lakukan tindakan perbaikan, lalu catat tindakannya.
        </p>
    </div>
    <div class="isi rapat">
        <?php if ($lots === []): ?>
            <div class="kosong">
                <div class="besar">Belum ada bahan kontrol</div>
                Daftarkan lot bahan kontrol beserta nilai target (mean) dan SD-nya.
            </div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>Pemeriksaan</th><th>Bahan Kontrol</th><th>Lot</th><th>Level</th><th>Alat</th>
                    <th class="angka">Mean</th><th class="angka">SD</th><th class="angka">Data</th>
                    <th>Uji Terakhir</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($lots as $l): ?>
                    <tr>
                        <td><b><?= $e($l['nama_test']) ?></b><div class="kecil redup"><?= $e($l['satuan'] ?? '') ?></div></td>
                        <td><?= $e($l['nama_bahan']) ?></td>
                        <td class="mono kecil"><?= $e($l['lot']) ?></td>
                        <td><span class="badge netral">L<?= $e($l['level']) ?></span></td>
                        <td class="kecil redup"><?= $e($l['nama_alat'] ?? 'semua') ?></td>
                        <td class="angka mono"><?= $e(rtrim(rtrim((string) $l['mean'], '0'), '.')) ?></td>
                        <td class="angka mono"><?= $e(rtrim(rtrim((string) $l['sd'], '0'), '.')) ?></td>
                        <td class="angka"><?= (int) $l['jml_data'] ?></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($l['uji_terakhir'])) ?></td>
                        <td><span class="badge <?= $warna($l['status_terakhir']) ?>">
                            <?= $e(match ((string) ($l['status_terakhir'] ?? '')) {
                                'out'     => 'Di luar kendali',
                                'warning' => 'Peringatan',
                                'in'      => 'Terkendali',
                                default   => 'Belum ada data',
                            }) ?>
                        </span></td>
                        <td><a class="tombol kecil utama" href="<?= $e(Url::to('/qc/lot/' . $l['id'])) ?>">Buka</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
