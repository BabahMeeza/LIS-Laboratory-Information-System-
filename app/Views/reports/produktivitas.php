<?php
/**
 * @var array<int,array<string,mixed>> $perPetugas
 * @var array<int,array<string,mixed>> $perAlat
 * @var array<string,mixed> $manualVsAlat
 * @var string $dari @var string $sampai
 */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$manual  = (int) ($manualVsAlat['manual'] ?? 0);
$dariAlat = (int) ($manualVsAlat['dari_alat'] ?? 0);
$total   = max(1, $manual + $dariAlat);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Produktivitas</h2>
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
</div>

<div class="grid k3" style="margin-bottom:18px">
    <div class="stat info">
        <div class="label">Hasil dari Alat</div>
        <div class="angka"><?= $dariAlat ?></div>
        <div class="catatan"><?= round(($dariAlat / $total) * 100) ?>% dari seluruh hasil</div>
    </div>
    <div class="stat hati">
        <div class="label">Hasil Entri Manual</div>
        <div class="angka"><?= $manual ?></div>
        <div class="catatan"><?= round(($manual / $total) * 100) ?>% dari seluruh hasil</div>
    </div>
    <div class="kartu" style="margin:0">
        <div class="isi">
            <div class="label kecil redup" style="margin-bottom:6px">Proporsi Otomatisasi</div>
            <div class="progress" style="height:14px">
                <span style="width:<?= round(($dariAlat / $total) * 100) ?>%"></span>
            </div>
            <p class="kecil redup mt8 mb0">
                Semakin tinggi porsi hasil dari alat, semakin kecil risiko salah ketik pada tahap analitik.
                Pemeriksaan yang masih banyak dientri manual layak ditinjau apakah alatnya sudah terhubung
                atau parameternya belum dipetakan.
            </p>
        </div>
    </div>
</div>

<div class="grid k2">
    <div class="kartu">
        <div class="kepala"><h2>Aktivitas per Petugas</h2></div>
        <div class="isi rapat">
            <table class="tabel">
                <thead><tr><th>Nama</th><th>Peran</th><th class="angka">Hasil Diinput</th><th class="angka">Diverifikasi</th></tr></thead>
                <tbody>
                <?php foreach ($perPetugas as $p): ?>
                    <tr>
                        <td><?= $e($p['nama']) ?></td>
                        <td><span class="badge netral"><?= $e(ucfirst((string) $p['role'])) ?></span></td>
                        <td class="angka"><?= (int) $p['diinput'] ?></td>
                        <td class="angka"><?= (int) $p['diverifikasi'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($perPetugas === []): ?>
                    <tr><td colspan="4" class="kosong">Tidak ada aktivitas pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Aktivitas per Alat</h2></div>
        <div class="isi rapat">
            <table class="tabel">
                <thead><tr><th>Alat</th><th class="angka">Hasil Tersimpan</th><th class="angka">Pesan Diterima</th><th>Rasio</th></tr></thead>
                <tbody>
                <?php foreach ($perAlat as $a): ?>
                    <?php
                    $pesan = (int) $a['jml_pesan'];
                    $hasil = (int) $a['jml_hasil'];
                    ?>
                    <tr>
                        <td><?= $e($a['nama']) ?></td>
                        <td class="angka"><?= $hasil ?></td>
                        <td class="angka"><?= $pesan ?></td>
                        <td class="kecil redup">
                            <?= $pesan > 0 ? number_format($hasil / $pesan, 1) . ' hasil/pesan' : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($perAlat === []): ?>
                    <tr><td colspan="4" class="kosong">Belum ada alat yang mengirim hasil.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
