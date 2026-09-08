<?php
/** @var array<string,mixed> $result @var array<int,array<string,mixed>> $riwayat */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Riwayat Hasil — <?= $e($result['nama_test']) ?></h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/order/' . $result['oid'])) ?>">Kembali ke Order</a></div>
    </div>
    <div class="isi">
        <dl class="rincian">
            <dt>Pasien</dt><dd><?= $e($result['nama_pasien']) ?></dd>
            <dt>Order</dt><dd class="mono"><?= $e($result['no_order']) ?></dd>
            <dt>Nilai saat ini</dt><dd><b><?= $e($result['nilai']) ?></b> <?= $e($result['satuan'] ?? $result['satuan_master'] ?? '') ?>
                <?php if ((string) $result['flag'] !== '' && $result['flag'] !== 'N'): ?>
                    <span class="flag <?= $e(Helper::warnaFlag((string) $result['flag'])) ?>"><?= $e(Helper::labelFlag((string) $result['flag'])) ?></span>
                <?php endif; ?>
            </dd>
            <dt>Status</dt><dd><span class="badge <?= $e(Helper::warnaStatus((string) $result['status'])) ?>"><?= $e(Helper::labelStatus((string) $result['status'])) ?></span></dd>
            <dt>Rujukan</dt><dd><?= $e($result['ref_teks'] ?? '-') ?></dd>
        </dl>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Perubahan (<?= count($riwayat) ?>)</h2></div>
    <div class="isi rapat">
        <?php if ($riwayat === []): ?>
            <div class="kosong"><div class="besar">Belum ada perubahan tercatat</div></div>
        <?php else: ?>
        <table class="tabel">
            <thead><tr><th>Waktu</th><th>Nilai Lama</th><th>Nilai Baru</th><th>Status</th><th>Alasan</th><th>Sumber</th><th>Oleh</th></tr></thead>
            <tbody>
            <?php foreach ($riwayat as $h): ?>
                <tr>
                    <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($h['created_at'])) ?></td>
                    <td class="mono"><?= $e($h['nilai_lama'] ?? '—') ?></td>
                    <td class="mono tebal"><?= $e($h['nilai_baru'] ?? '—') ?></td>
                    <td class="kecil"><?= $e(Helper::labelStatus((string) $h['status_lama'])) ?> &rarr; <?= $e(Helper::labelStatus((string) $h['status_baru'])) ?></td>
                    <td class="kecil"><?= $e($h['alasan'] ?? '-') ?></td>
                    <td class="kecil redup"><?= $e($h['sumber']) ?></td>
                    <td class="kecil"><?= $e($h['oleh'] ?? 'sistem') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::can('hasil.koreksi')): ?>
<div class="kartu" style="max-width:640px">
    <div class="kepala"><h2>Koreksi Hasil</h2></div>
    <div class="isi">
        <p class="kecil redup">
            Koreksi menandai hasil sebagai <b>Dikoreksi</b> dan tercatat permanen pada riwayat di atas.
            Bila lembar hasil sudah dicetak atau dikirim ke SIMRS, kirim ulang setelah koreksi.
        </p>
        <form method="post" action="<?= $e(Url::to('/hasil/' . $result['id'] . '/koreksi')) ?>"
              data-konfirmasi="Simpan koreksi hasil ini?">
            <?= Csrf::field() ?>
            <div class="form-baris">
                <label for="nilai">Nilai baru</label>
                <input type="text" id="nilai" name="nilai" required value="<?= $e($result['nilai']) ?>" style="font-family:var(--mono)">
            </div>
            <div class="form-baris">
                <label for="alasan">Alasan koreksi <span style="color:var(--merah)">*</span></label>
                <input type="text" id="alasan" name="alasan" required placeholder="Contoh: salah ketik saat entri manual">
            </div>
            <button class="tombol bahaya" type="submit">Simpan Koreksi</button>
        </form>
    </div>
</div>
<?php endif; ?>
