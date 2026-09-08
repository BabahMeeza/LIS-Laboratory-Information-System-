<?php
/**
 * @var array<string,mixed>|null $panel
 * @var array<int,int> $items
 * @var array<int,array<string,mixed>> $tests
 * @var array<int,array<string,mixed>> $kategori
 */
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e     = static fn ($v) => Helper::e($v);
$nilai = static fn (string $k, mixed $d = '') => Helper::e(Flash::old($k, $panel[$k] ?? $d));
$aksi  = $panel === null ? Url::to('/master/paket') : Url::to('/master/paket/' . $panel['id']);

$perKategori = [];
foreach ($tests as $t) {
    $perKategori[(string) ($t['kategori'] ?? 'Lain-lain')][] = $t;
}
?>
<form method="post" action="<?= $e($aksi) ?>">
    <?= Csrf::field() ?>

    <div class="kartu">
        <div class="kepala"><h2><?= $panel === null ? 'Paket Pemeriksaan Baru' : 'Ubah Paket' ?></h2></div>
        <div class="isi">
            <?php if (Flash::errors() !== []): ?>
                <div class="notif error">
                    <?php foreach (Flash::errors() as $pesan): ?><div><?= $e($pesan) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="grid k4">
                <div class="form-baris">
                    <label for="kode">Kode Paket <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="kode" name="kode" required value="<?= $nilai('kode') ?>" placeholder="DL">
                </div>
                <div class="form-baris" style="grid-column: span 2">
                    <label for="nama">Nama Paket <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="nama" name="nama" required value="<?= $nilai('nama') ?>" placeholder="Darah Lengkap (CBC)">
                </div>
                <div class="form-baris">
                    <label for="category_id">Kategori</label>
                    <select id="category_id" name="category_id">
                        <option value="">—</option>
                        <?php foreach ($kategori as $k): ?>
                            <option value="<?= (int) $k['id'] ?>"
                                <?= (int) Flash::old('category_id', $panel['category_id'] ?? 0) === (int) $k['id'] ? 'selected' : '' ?>>
                                <?= $e($k['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid k3">
                <div class="form-baris">
                    <label for="harga">Tarif Paket (Rp)</label>
                    <input type="number" id="harga" name="harga" min="0" step="1" value="<?= $nilai('harga', 0) ?>">
                    <div class="bantuan">Isi 0 agar tarif dihitung dari penjumlahan item.</div>
                </div>
                <div class="form-baris">
                    <label for="khanza_kd_jenis_prw">Khanza: kd_jenis_prw</label>
                    <input type="text" id="khanza_kd_jenis_prw" name="khanza_kd_jenis_prw" value="<?= $nilai('khanza_kd_jenis_prw') ?>">
                </div>
                <div class="form-baris">
                    <label>&nbsp;</label>
                    <label class="cek"><input type="checkbox" name="aktif" value="1"
                        <?= $panel === null ? 'checked' : ((int) Flash::old('aktif', $panel['aktif'] ?? 1) === 1 ? 'checked' : '') ?>> Aktif</label>
                </div>
            </div>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala">
            <h2>Isi Paket</h2>
            <div class="kanan"><input type="search" data-saring="#tabel-paket" placeholder="Saring pemeriksaan…" style="width:220px"></div>
        </div>
        <div class="isi rapat">
            <div class="tabel-bungkus" style="max-height:520px;overflow-y:auto">
                <table class="tabel rapat" id="tabel-paket">
                    <thead><tr><th style="width:34px"></th><th>Kode</th><th>Pemeriksaan</th><th>Kategori</th><th class="angka">Tarif</th></tr></thead>
                    <tbody>
                    <?php foreach ($perKategori as $namaKategori => $daftar): ?>
                        <?php foreach ($daftar as $t): ?>
                            <tr>
                                <td><input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>"
                                           <?= in_array((int) $t['id'], $items, true) ? 'checked' : '' ?>></td>
                                <td class="mono kecil"><?= $e($t['kode']) ?></td>
                                <td><?= $e($t['nama']) ?></td>
                                <td class="kecil redup"><?= $e($namaKategori) ?></td>
                                <td class="angka kecil"><?= $e(number_format((float) $t['harga'], 0, ',', '.')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="isi" style="border-top:1px solid var(--garis)">
            <div class="aksi-baris">
                <button class="tombol utama" type="submit">Simpan Paket</button>
                <a class="tombol" href="<?= $e(Url::to('/master/paket')) ?>">Batal</a>
            </div>
        </div>
    </div>
</form>
