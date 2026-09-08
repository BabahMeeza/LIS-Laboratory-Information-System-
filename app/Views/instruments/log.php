<?php
/** @var array<int,array<string,mixed>> $log @var array<int,array<string,mixed>> $alat @var array<string,mixed> $filter */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$warna = static fn (string $s) => match ($s) {
    'diproses'    => 'sukses',
    'sebagian'    => 'hati',
    'tidak_cocok' => 'bahaya',
    'error'       => 'bahaya',
    'diabaikan'   => 'redup',
    default       => 'netral',
};
?>
<div class="kartu">
    <div class="kepala">
        <h2>Log Komunikasi Alat</h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/alat')) ?>">Daftar Alat</a></div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris">
            <label>Alat</label>
            <select name="alat">
                <option value="0">Semua alat</option>
                <?php foreach ($alat as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) $filter['alatId'] === (int) $a['id'] ? 'selected' : '' ?>><?= $e($a['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Status</label>
            <select name="status">
                <option value="">Semua</option>
                <?php foreach (['diterima', 'diproses', 'sebagian', 'tidak_cocok', 'error', 'diabaikan'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter['status'] === $s ? 'selected' : '' ?>><?= $e(ucfirst(str_replace('_', ' ', $s))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($filter['cari']) ?>" placeholder="Sample ID atau isi pesan" style="min-width:230px">
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>

    <div class="isi rapat">
        <?php if ($log === []): ?>
            <div class="kosong"><div class="besar">Belum ada komunikasi tercatat</div>Pastikan middleware berjalan dan alat terhubung.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>Waktu</th><th>Alat</th><th>Arah</th><th>Protokol</th><th>Sample ID</th>
                    <th class="angka">Hasil</th><th>Status</th><th>Keterangan</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($log as $l): ?>
                    <tr>
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($l['created_at'])) ?></td>
                        <td class="kecil"><?= $e($l['nama_alat'] ?? $l['kode_alat'] ?? '?') ?></td>
                        <td class="kecil"><?= $e($l['arah']) ?></td>
                        <td class="kecil"><?= $e(strtoupper((string) ($l['protokol'] ?? '-'))) ?></td>
                        <td class="mono"><?= $e($l['sample_id'] ?? '-') ?></td>
                        <td class="angka"><?= (int) $l['jml_tersimpan'] ?> / <?= (int) $l['jml_hasil'] ?></td>
                        <td><span class="badge <?= $warna((string) $l['status']) ?>"><?= $e(ucfirst(str_replace('_', ' ', (string) $l['status']))) ?></span></td>
                        <td class="kecil" style="color:var(--merah)"><?= $e(Helper::potong($l['pesan_error'], 60)) ?></td>
                        <td><a class="tombol kecil" href="<?= $e(Url::to('/alat/log/' . $l['id'])) ?>">Detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
