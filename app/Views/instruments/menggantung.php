<?php
/** @var array<int,array<string,mixed>> $rows @var array<int,array<string,mixed>> $kandidat */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Hasil Belum Terpetakan (<?= count($rows) ?>)</h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/alat')) ?>">Daftar Alat</a></div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Hasil di bawah diterima dari alat tetapi tidak menemukan order yang cocok, atau memuat parameter
            yang belum dipetakan. Sebab yang paling sering: sampel dikerjakan sebelum order dibuat,
            barcode tertukar, atau alat memakai kode parameter baru. Pasangkan ke order yang benar,
            atau buang bila memang bukan sampel pasien (mis. bahan kontrol).
        </p>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="kartu"><div class="kosong">
        <div class="besar">Tidak ada hasil menggantung</div>
        Semua hasil dari alat berhasil dipasangkan ke order.
    </div></div>
<?php else: ?>
    <?php foreach ($rows as $r): ?>
        <div class="kartu">
            <div class="kepala">
                <h2>Sample <span class="mono"><?= $e($r['sample_id'] ?? '(kosong)') ?></span></h2>
                <div class="kanan kecil redup">
                    <?= $e($r['nama_alat'] ?? '?') ?> &middot; <?= $e(Helper::tanggalPendek($r['created_at'])) ?>
                    &middot; <span class="badge hati"><?= $e(str_replace('_', ' ', (string) $r['alasan'])) ?></span>
                </div>
            </div>
            <div class="isi">
                <div class="mb0" style="margin-bottom:12px">
                    <?php foreach ($r['ringkasan'] as $ring): ?>
                        <span class="pil mono"><?= $e($ring) ?></span>
                    <?php endforeach; ?>
                    <?php if ($r['jml_hasil'] > count($r['ringkasan'])): ?>
                        <span class="kecil redup">+<?= $r['jml_hasil'] - count($r['ringkasan']) ?> lagi</span>
                    <?php endif; ?>
                </div>

                <?php if (Auth::can('hasil.entri')): ?>
                <div class="grid k2">
                    <form method="post" action="<?= $e(Url::to('/alat/menggantung/' . $r['id'] . '/pasang')) ?>">
                        <?= Csrf::field() ?>
                        <div style="display:flex;gap:8px;align-items:flex-end">
                            <div class="form-baris" style="flex:1;margin:0">
                                <label>Pasangkan ke order</label>
                                <select name="order_id" required>
                                    <option value="">— pilih order —</option>
                                    <?php foreach ($kandidat as $k): ?>
                                        <option value="<?= (int) $k['id'] ?>"
                                                <?= (string) $k['barcode'] === (string) $r['sample_id'] ? 'selected' : '' ?>>
                                            <?= $e($k['no_lab'] ?? $k['no_order']) ?> — <?= $e($k['nama_pasien']) ?>
                                            (RM <?= $e($k['no_rm']) ?>)<?= $k['barcode'] !== null ? ' [' . $e($k['barcode']) . ']' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="tombol utama" type="submit">Pasang</button>
                        </div>
                    </form>

                    <?php if (Auth::can('alat.kelola')): ?>
                    <form method="post" action="<?= $e(Url::to('/alat/menggantung/' . $r['id'] . '/buang')) ?>"
                          data-konfirmasi="Buang data hasil menggantung ini?" style="align-self:flex-end">
                        <?= Csrf::field() ?>
                        <button class="tombol bahaya" type="submit">Buang</button>
                        <span class="kecil redup" style="margin-left:8px">Gunakan untuk bahan kontrol atau sampel uji.</span>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
