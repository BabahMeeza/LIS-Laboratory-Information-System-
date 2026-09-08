<?php
/** @var array<int,array<string,mixed>> $spesimen @var array<string,string> $filter */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$statusOpsi = ['' => 'Semua', 'pending' => 'Menunggu', 'collected' => 'Diambil',
    'received' => 'Diterima', 'rejected' => 'Ditolak'];
?>
<div class="kartu">
    <div class="kepala">
        <h2>Daftar Spesimen</h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/spesimen/label-batch')) ?>" target="_blank">Cetak Label</a>
            <a class="tombol utama" href="<?= $e(Url::to('/spesimen/penerimaan')) ?>">Layar Penerimaan</a>
        </div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris"><label>Dari</label><input type="date" name="dari" value="<?= $e($filter['dari']) ?>"></div>
        <div class="form-baris"><label>Sampai</label><input type="date" name="sampai" value="<?= $e($filter['sampai']) ?>"></div>
        <div class="form-baris">
            <label>Status</label>
            <select name="status">
                <?php foreach ($statusOpsi as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter['status'] === $k ? 'selected' : '' ?>><?= $e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($filter['cari']) ?>" placeholder="Barcode / no. lab / pasien" style="min-width:230px">
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>

    <div class="isi rapat">
        <?php if ($spesimen === []): ?>
            <div class="kosong"><div class="besar">Tidak ada spesimen</div>Sesuaikan filter tanggal atau status.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>Barcode</th><th>No. Lab</th><th>Pasien</th><th>Jenis</th><th class="angka">Item</th>
                    <th>Status</th><th>Diambil</th><th>Diterima</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($spesimen as $s): ?>
                    <tr class="<?= $s['prioritas'] === 'cito' ? 'cito' : '' ?>">
                        <td class="mono"><b><?= $e($s['barcode']) ?></b></td>
                        <td class="mono kecil"><?= $e($s['no_lab'] ?? '-') ?></td>
                        <td><b><?= $e($s['nama_pasien']) ?></b><div class="kecil redup">RM <?= $e($s['no_rm']) ?> &middot; <?= $e(Helper::umurTeks($s['tgl_lahir'])) ?></div></td>
                        <td class="kecil"><?= $e($s['jenis_spesimen'] ?? '-') ?><div class="redup"><?= $e($s['container'] ?? '') ?></div></td>
                        <td class="angka"><?= (int) $s['jml_item'] ?></td>
                        <td><span class="badge <?= $e(Helper::warnaStatus((string) $s['status'])) ?>"><?= $e(Helper::labelStatus((string) $s['status'])) ?></span></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($s['collected_at'])) ?></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($s['received_at'])) ?></td>
                        <td class="nowrap">
                            <?php if ((string) $s['status'] === 'pending' && Auth::can('spesimen.ambil')): ?>
                                <form method="post" action="<?= $e(Url::to('/spesimen/' . $s['id'] . '/ambil')) ?>" style="display:inline">
                                    <?= Csrf::field() ?><button class="tombol kecil" type="submit">Ambil</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array((string) $s['status'], ['pending', 'collected'], true) && Auth::can('spesimen.terima')): ?>
                                <form method="post" action="<?= $e(Url::to('/spesimen/' . $s['id'] . '/terima')) ?>" style="display:inline">
                                    <?= Csrf::field() ?><button class="tombol kecil utama" type="submit">Terima</button>
                                </form>
                            <?php endif; ?>
                            <a class="tombol kecil" href="<?= $e(Url::to('/spesimen/' . $s['id'] . '/label')) ?>" target="_blank">Label</a>
                            <?php if (Auth::can('spesimen.tolak') && !in_array((string) $s['status'], ['rejected', 'disposed'], true)): ?>
                                <details style="display:inline-block;position:relative">
                                    <summary class="tombol kecil bahaya" style="list-style:none">Tolak</summary>
                                    <div style="position:absolute;right:0;z-index:30;background:#fff;border:1px solid var(--garis-tebal);
                                                border-radius:6px;padding:12px;box-shadow:var(--bayang);width:270px;text-align:left">
                                        <form method="post" action="<?= $e(Url::to('/spesimen/' . $s['id'] . '/tolak')) ?>">
                                            <?= Csrf::field() ?>
                                            <div class="form-baris">
                                                <label>Kondisi</label>
                                                <select name="kondisi">
                                                    <option value="lisis">Lisis</option>
                                                    <option value="beku">Beku / bergumpal</option>
                                                    <option value="kurang">Volume kurang</option>
                                                    <option value="ikterik">Ikterik</option>
                                                    <option value="lipemik">Lipemik</option>
                                                    <option value="tidak_sesuai">Identitas tidak sesuai</option>
                                                </select>
                                            </div>
                                            <div class="form-baris">
                                                <label>Alasan penolakan</label>
                                                <input type="text" name="alasan" required placeholder="Wajib diisi">
                                            </div>
                                            <button class="tombol kecil bahaya" type="submit">Tolak Spesimen</button>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::can('spesimen.tolak')): ?>
<div class="notif info kecil">
    Penolakan spesimen adalah indikator mutu pra-analitik. Gunakan tombol <b>Tolak</b> pada baris
    spesimen yang bersangkutan, lalu isi kondisi dan alasan — data ini muncul pada laporan penolakan.
</div>
<?php endif; ?>
