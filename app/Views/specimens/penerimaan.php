<?php
/** @var array<int,array<string,mixed>> $terbaru @var int $menunggu */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<?= Csrf::field() ?>

<div class="grid k4" style="margin-bottom:18px">
    <div class="stat info">
        <div class="label">Menunggu Diterima</div>
        <div class="angka"><?= $menunggu ?></div>
        <div class="catatan">seluruh spesimen aktif</div>
    </div>
    <div class="stat sukses">
        <div class="label">Diterima Hari Ini</div>
        <div class="angka"><?= count($terbaru) ?></div>
        <div class="catatan">30 terakhir ditampilkan</div>
    </div>
    <div class="kartu" style="grid-column: span 2;margin:0">
        <div class="isi">
            <div class="form-baris" style="margin:0">
                <label for="input-barcode" style="font-size:14px">Pindai Barcode Spesimen</label>
                <input type="text" id="input-barcode" data-url="<?= $e(Url::to('/spesimen/terima-barcode')) ?>"
                       placeholder="Arahkan scanner ke sini lalu pindai…"
                       style="font-size:19px;padding:10px;font-family:var(--mono)"
                       autocomplete="off">
                <div class="bantuan">
                    Tekan Enter setelah pemindaian. Kondisi sampel:
                    <select id="kondisi-sampel" style="width:auto;display:inline-block;padding:2px 6px">
                        <option value="baik">Baik</option>
                        <option value="lisis">Lisis</option>
                        <option value="ikterik">Ikterik</option>
                        <option value="lipemik">Lipemik</option>
                        <option value="kurang">Volume kurang</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="pesan-dinamis"></div>

<div class="kartu">
    <div class="kepala">
        <h2>Penerimaan Hari Ini</h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/spesimen')) ?>">Semua Spesimen</a></div>
    </div>
    <div class="isi rapat">
        <div class="tabel-bungkus">
            <table class="tabel" id="tabel-terima">
                <thead>
                <tr><th>Barcode</th><th>No. Lab</th><th>Pasien</th><th>Jenis</th><th>Prioritas</th><th>Pemeriksaan</th><th>Waktu</th></tr>
                </thead>
                <tbody>
                <?php if ($terbaru === []): ?>
                    <tr class="baris-kosong"><td colspan="7" class="kosong">
                        <div class="besar">Belum ada penerimaan hari ini</div>
                        Pindai barcode spesimen untuk memulai.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($terbaru as $t): ?>
                        <tr>
                            <td class="mono"><b><?= $e($t['barcode']) ?></b></td>
                            <td class="mono kecil"><?= $e($t['no_lab'] ?? '-') ?></td>
                            <td><b><?= $e($t['nama_pasien']) ?></b><div class="kecil redup">RM <?= $e($t['no_rm']) ?></div></td>
                            <td class="kecil"><?= $e($t['jenis_spesimen'] ?? '-') ?></td>
                            <td><?php if ($t['prioritas'] === 'cito'): ?>
                                <span class="badge bahaya">CITO</span>
                            <?php else: ?><span class="badge netral">Rutin</span><?php endif; ?></td>
                            <td class="kecil redup"><?= $e(ucfirst(str_replace('_', ' ', (string) $t['kondisi']))) ?></td>
                            <td class="kecil redup"><?= $e(Helper::tanggalPendek($t['received_at'])) ?><div><?= $e($t['diterima_oleh'] ?? '') ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
