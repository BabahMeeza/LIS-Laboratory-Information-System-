<?php
/**
 * Tampilan kumulatif: pemeriksaan sebagai baris, tanggal sebagai kolom.
 * Berguna untuk melihat tren antar kunjungan.
 *
 * @var array<string,mixed> $pasien
 * @var array<string,array{satuan:?string,ref:?string,nilai:array<string,array{nilai:?string,flag:string}>}> $matriks
 * @var array<int,string>   $tanggal
 */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Riwayat Hasil — <?= $e($pasien['nama']) ?></h2>
        <div class="kanan">
            <span class="kecil redup">RM <?= $e($pasien['no_rm']) ?> &middot; <?= $e(Helper::umurTeks($pasien['tgl_lahir'])) ?></span>
            <a class="tombol kecil" href="<?= $e(Url::to('/pasien/' . $pasien['id'])) ?>">Kembali</a>
            <button class="tombol kecil tanpa-cetak" onclick="window.print()">Cetak</button>
        </div>
    </div>
    <div class="isi rapat">
        <?php if ($matriks === []): ?>
            <div class="kosong"><div class="besar">Belum ada hasil</div>Pasien ini belum memiliki hasil terverifikasi.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel rapat">
                <thead>
                <tr>
                    <th style="min-width:200px">Pemeriksaan</th>
                    <th>Satuan</th>
                    <th>Rujukan</th>
                    <?php foreach ($tanggal as $t): ?>
                        <th class="angka nowrap"><?= $e(Helper::tanggalPendek($t, false)) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matriks as $namaTest => $baris): ?>
                    <tr>
                        <td><b><?= $e($namaTest) ?></b></td>
                        <td class="kecil redup"><?= $e($baris['satuan'] ?? '') ?></td>
                        <td class="kecil redup"><?= $e($baris['ref'] ?? '') ?></td>
                        <?php foreach ($tanggal as $t): ?>
                            <?php $sel = $baris['nilai'][$t] ?? null; ?>
                            <td class="angka">
                                <?php if ($sel === null): ?>
                                    <span class="redup">-</span>
                                <?php else: ?>
                                    <?php $kelas = Helper::warnaFlag((string) $sel['flag']); ?>
                                    <span class="<?= $kelas === 'kritis' ? 'nilai-kritis' : ($kelas === 'abnormal' ? 'nilai-abnormal' : '') ?>">
                                        <?= $e($sel['nilai']) ?></span>
                                    <?php if ($sel['flag'] !== '' && $sel['flag'] !== 'N'): ?>
                                        <span class="flag <?= $e($kelas) ?>"><?= $e(Helper::labelFlag((string) $sel['flag'])) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
