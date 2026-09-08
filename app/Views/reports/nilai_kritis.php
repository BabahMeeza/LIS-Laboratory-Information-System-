<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $ringkasan
 * @var string $dari @var string $sampai
 */
use App\Core\Config;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$total      = (int) ($ringkasan['total'] ?? 0);
$dilaporkan = (int) ($ringkasan['dilaporkan'] ?? 0);
$persen     = $total > 0 ? (int) round(($dilaporkan / $total) * 100) : 100;
?>
<div class="kartu">
    <div class="kepala">
        <h2>Register Nilai Kritis</h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/laporan')) ?>">Rekap</a>
            <button class="tombol kecil tanpa-cetak" onclick="window.print()">Cetak</button>
        </div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Dokumen ini mencatat setiap hasil yang melewati ambang nilai kritis beserta bukti
            pelaporannya kepada dokter penanggung jawab pasien, termasuk selang waktu antara
            hasil keluar dan waktu pelaporan. Register ini termasuk berkas yang diminta saat
            penilaian akreditasi laboratorium.
        </p>
    </div>
    <form class="filter" method="get">
        <div class="form-baris"><label>Dari</label><input type="date" name="dari" value="<?= $e($dari) ?>"></div>
        <div class="form-baris"><label>Sampai</label><input type="date" name="sampai" value="<?= $e($sampai) ?>"></div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>
</div>

<div class="grid k3" style="margin-bottom:18px">
    <div class="stat bahaya">
        <div class="label">Total Nilai Kritis</div>
        <div class="angka"><?= $total ?></div>
    </div>
    <div class="stat <?= $persen === 100 ? 'sukses' : 'hati' ?>">
        <div class="label">Sudah Dilaporkan</div>
        <div class="angka"><?= $dilaporkan ?></div>
        <div class="catatan"><?= $persen ?>% kepatuhan pelaporan</div>
    </div>
    <div class="stat">
        <div class="label">Rata-rata Waktu Lapor</div>
        <div class="angka" style="font-size:20px">
            <?= $ringkasan['rata2_menit'] === null ? '—' : $e(Helper::durasi((int) round((float) $ringkasan['rata2_menit']))) ?>
        </div>
        <div class="catatan">sejak hasil tersimpan</div>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Rincian (<?= count($rows) ?>)</h2></div>
    <div class="isi rapat">
        <?php if ($rows === []): ?>
            <div class="kosong">
                <div class="besar">Tidak ada nilai kritis</div>
                Pada periode ini tidak ada hasil yang melewati ambang nilai kritis.
            </div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>Waktu Hasil</th><th>Pasien</th><th>No. Lab</th><th>Pemeriksaan</th>
                    <th class="angka">Hasil</th><th>Rujukan</th><th>DPJP / Perujuk</th>
                    <th>Dilaporkan Kepada</th><th>Waktu Lapor</th><th class="angka">Selang</th><th>Pelapor</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['kritis_dilapor_at'] === null ? 'cito' : '' ?>">
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($r['created_at'])) ?></td>
                        <td>
                            <a href="<?= $e(Url::to('/order/' . $r['order_id'])) ?>"><b><?= $e($r['nama_pasien']) ?></b></a>
                            <div class="kecil redup">RM <?= $e($r['no_rm']) ?> &middot; <?= $e(ucfirst((string) $r['asal'])) ?></div>
                        </td>
                        <td class="mono kecil"><?= $e($r['no_lab'] ?? '-') ?></td>
                        <td class="kecil"><?= $e($r['nama_test']) ?></td>
                        <td class="angka">
                            <span class="nilai-kritis"><?= $e($r['nilai']) ?></span>
                            <span class="kecil redup"><?= $e($r['satuan'] ?? '') ?></span>
                            <div><span class="flag kritis"><?= $e(Helper::labelFlag((string) $r['flag'])) ?></span></div>
                        </td>
                        <td class="kecil redup"><?= $e($r['ref_teks'] ?? '') ?></td>
                        <td class="kecil"><?= $e($r['dokter_perujuk'] ?? '-') ?></td>
                        <td class="kecil">
                            <?php if ($r['kritis_dilapor_ke'] === null): ?>
                                <span class="badge bahaya">Belum dilaporkan</span>
                            <?php else: ?>
                                <b><?= $e($r['kritis_dilapor_ke']) ?></b>
                            <?php endif; ?>
                        </td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($r['kritis_dilapor_at'])) ?></td>
                        <td class="angka kecil <?= ($r['menit_lapor'] !== null && (int) $r['menit_lapor'] > 30) ? 'nilai-abnormal' : '' ?>">
                            <?= $r['menit_lapor'] === null ? '—' : $e(Helper::durasi((int) $r['menit_lapor'])) ?>
                        </td>
                        <td class="kecil redup"><?= $e($r['pelapor'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="isi" style="border-top:1px solid var(--garis)">
        <p class="kecil redup mb0">
            <?= $e(Config::setting('app.nama_faskes', '')) ?> —
            <?= $e(Config::setting('app.nama_lab', '')) ?>.
            Dicetak <?= $e(Helper::tanggal(date('Y-m-d H:i:s'), true)) ?>.
            Selang waktu pelaporan yang melebihi 30 menit ditandai merah.
        </p>
    </div>
</div>
