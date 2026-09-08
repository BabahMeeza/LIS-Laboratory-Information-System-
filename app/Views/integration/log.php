<?php
/** @var array<int,array<string,mixed>> $log @var array<string,string> $filter */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Log Integrasi Khanza</h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/integrasi')) ?>">Kembali</a></div>
    </div>

    <form class="filter" method="get">
        <div class="form-baris">
            <label>Status</label>
            <select name="status">
                <option value="">Semua</option>
                <?php foreach (['sukses', 'antri', 'gagal'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter['status'] === $s ? 'selected' : '' ?>><?= $e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-baris">
            <label>Arah</label>
            <select name="arah">
                <option value="">Semua</option>
                <option value="masuk"  <?= $filter['arah'] === 'masuk' ? 'selected' : '' ?>>Masuk</option>
                <option value="keluar" <?= $filter['arah'] === 'keluar' ? 'selected' : '' ?>>Keluar</option>
            </select>
        </div>
        <div class="form-baris">
            <label>Jenis</label>
            <select name="jenis">
                <option value="">Semua</option>
                <?php foreach (['order', 'hasil', 'template', 'ping', 'tarik_order'] as $j): ?>
                    <option value="<?= $j ?>" <?= $filter['jenis'] === $j ? 'selected' : '' ?>><?= $e(ucfirst(str_replace('_', ' ', $j))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>

    <div class="isi rapat">
        <?php if ($log === []): ?>
            <div class="kosong"><div class="besar">Belum ada log</div>Aktivitas integrasi akan tercatat di sini.</div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>Waktu</th><th>Arah</th><th>Jenis</th><th>Referensi</th><th>Endpoint</th>
                    <th>HTTP</th><th>Status</th><th class="angka">Coba</th><th>Retry</th><th>Pesan</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($log as $l): ?>
                    <tr>
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($l['created_at'])) ?></td>
                        <td class="kecil"><?= $e($l['arah']) ?></td>
                        <td class="kecil"><?= $e($l['jenis']) ?></td>
                        <td class="kecil mono">
                            <?php if ((string) $l['ref_type'] === 'order' && $l['ref_id'] !== null): ?>
                                <a href="<?= $e(Url::to('/order/' . $l['ref_id'])) ?>">#<?= $e($l['ref_id']) ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="kecil redup"><?= $e(Helper::potong($l['endpoint'], 44)) ?></td>
                        <td class="kecil mono"><?= $e($l['http_code'] ?? '-') ?></td>
                        <td><span class="badge <?= $l['status'] === 'sukses' ? 'sukses' : ($l['status'] === 'antri' ? 'hati' : 'bahaya') ?>"><?= $e($l['status']) ?></span></td>
                        <td class="angka kecil"><?= (int) $l['percobaan'] ?></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($l['next_retry_at'])) ?></td>
                        <td class="kecil" style="color:var(--merah);max-width:260px"><?= $e(Helper::potong($l['pesan'], 90)) ?></td>
                        <td>
                            <?php if (Auth::can('integrasi.kelola') && $l['status'] !== 'sukses' && (string) $l['ref_type'] === 'order'): ?>
                                <form method="post" action="<?= $e(Url::to('/integrasi/log/' . $l['id'] . '/kirim-ulang')) ?>">
                                    <?= Csrf::field() ?>
                                    <button class="tombol kecil" type="submit">Kirim Ulang</button>
                                </form>
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
