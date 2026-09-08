<?php
/** @var array<string,mixed>|null $test @var array<int,array<string,mixed>> $kategori @var array<int,array<string,mixed>> $spesimen */
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e     = static fn ($v) => Helper::e($v);
$nilai = static fn (string $k, mixed $d = '') => Helper::e(Flash::old($k, $test[$k] ?? $d));
$pil   = static fn (string $k, string $v, mixed $d = '') => ((string) Flash::old($k, $test[$k] ?? $d) === $v ? 'selected' : '');
$cek   = static fn (string $k, mixed $d = 0) => ((int) Flash::old($k, $test[$k] ?? $d) === 1 ? 'checked' : '');
$aksi  = $test === null ? Url::to('/master/pemeriksaan') : Url::to('/master/pemeriksaan/' . $test['id']);
?>
<form method="post" action="<?= $e($aksi) ?>">
    <?= Csrf::field() ?>

    <div class="grid k2">
        <div class="kartu">
            <div class="kepala"><h2>Identitas Pemeriksaan</h2></div>
            <div class="isi">
                <?php if (Flash::errors() !== []): ?>
                    <div class="notif error">
                        <?php foreach (Flash::errors() as $pesan): ?><div><?= $e($pesan) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="grid k2">
                    <div class="form-baris">
                        <label for="kode">Kode <span style="color:var(--merah)">*</span></label>
                        <input type="text" id="kode" name="kode" required value="<?= $nilai('kode') ?>" placeholder="HB">
                    </div>
                    <div class="form-baris">
                        <label for="loinc">Kode LOINC</label>
                        <input type="text" id="loinc" name="loinc" value="<?= $nilai('loinc') ?>" placeholder="718-7">
                        <div class="bantuan">Dipakai untuk interoperabilitas (Satu Sehat / FHIR).</div>
                    </div>
                </div>
                <div class="form-baris">
                    <label for="nama">Nama Pemeriksaan <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="nama" name="nama" required value="<?= $nilai('nama') ?>">
                </div>
                <div class="form-baris">
                    <label for="nama_singkat">Nama Singkat (untuk lembar hasil)</label>
                    <input type="text" id="nama_singkat" name="nama_singkat" value="<?= $nilai('nama_singkat') ?>">
                </div>
                <div class="grid k2">
                    <div class="form-baris">
                        <label for="category_id">Kategori</label>
                        <select id="category_id" name="category_id">
                            <option value="">—</option>
                            <?php foreach ($kategori as $k): ?>
                                <option value="<?= (int) $k['id'] ?>" <?= $pil('category_id', (string) $k['id']) ?>><?= $e($k['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-baris">
                        <label for="specimen_type_id">Jenis Spesimen</label>
                        <select id="specimen_type_id" name="specimen_type_id">
                            <option value="">—</option>
                            <?php foreach ($spesimen as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $pil('specimen_type_id', (string) $s['id']) ?>>
                                    <?= $e($s['nama']) ?> — <?= $e($s['container']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="bantuan">Menentukan tabung mana yang dibuatkan barcode saat order.</div>
                    </div>
                </div>
                <div class="form-baris">
                    <label for="metode">Metode Pemeriksaan</label>
                    <input type="text" id="metode" name="metode" value="<?= $nilai('metode') ?>" placeholder="GOD-PAP">
                </div>
            </div>
        </div>

        <div class="kartu">
            <div class="kepala"><h2>Format Hasil, Tarif &amp; Pemetaan</h2></div>
            <div class="isi">
                <div class="grid k3">
                    <div class="form-baris">
                        <label for="tipe_hasil">Tipe Hasil</label>
                        <select id="tipe_hasil" name="tipe_hasil">
                            <option value="numerik" <?= $pil('tipe_hasil', 'numerik', 'numerik') ?>>Numerik</option>
                            <option value="pilihan" <?= $pil('tipe_hasil', 'pilihan') ?>>Pilihan</option>
                            <option value="teks"    <?= $pil('tipe_hasil', 'teks') ?>>Teks singkat</option>
                            <option value="narasi"  <?= $pil('tipe_hasil', 'narasi') ?>>Narasi panjang</option>
                        </select>
                    </div>
                    <div class="form-baris">
                        <label for="satuan">Satuan</label>
                        <input type="text" id="satuan" name="satuan" value="<?= $nilai('satuan') ?>" placeholder="mg/dL">
                    </div>
                    <div class="form-baris">
                        <label for="desimal">Desimal</label>
                        <input type="number" id="desimal" name="desimal" min="0" max="6" value="<?= $nilai('desimal', 2) ?>">
                    </div>
                </div>
                <div class="form-baris">
                    <label for="pilihan">Daftar Pilihan</label>
                    <input type="text" id="pilihan" name="pilihan" value="<?= $nilai('pilihan') ?>" placeholder="Negatif,Positif">
                    <div class="bantuan">Hanya untuk tipe hasil "Pilihan". Pisahkan dengan koma.</div>
                </div>
                <div class="grid k3">
                    <div class="form-baris">
                        <label for="harga">Tarif (Rp)</label>
                        <input type="number" id="harga" name="harga" step="1" min="0" value="<?= $nilai('harga', 0) ?>">
                    </div>
                    <div class="form-baris">
                        <label for="tat_menit">Target TAT (menit)</label>
                        <input type="number" id="tat_menit" name="tat_menit" min="1" value="<?= $nilai('tat_menit', 120) ?>">
                    </div>
                    <div class="form-baris">
                        <label for="urut">Urutan Tampil</label>
                        <input type="number" id="urut" name="urut" value="<?= $nilai('urut', 0) ?>">
                    </div>
                </div>

                <div class="grid k2">
                    <div class="form-baris">
                        <label for="khanza_kd_jenis_prw">Khanza: kd_jenis_prw</label>
                        <input type="text" id="khanza_kd_jenis_prw" name="khanza_kd_jenis_prw" value="<?= $nilai('khanza_kd_jenis_prw') ?>">
                    </div>
                    <div class="form-baris">
                        <label for="khanza_id_template">Khanza: id_template</label>
                        <input type="number" id="khanza_id_template" name="khanza_id_template" value="<?= $nilai('khanza_id_template') ?>">
                    </div>
                </div>
                <div class="bantuan" style="margin-top:-6px">
                    Lebih mudah diisi lewat menu <a href="<?= $e(Url::to('/integrasi/pemetaan')) ?>">Integrasi &rarr; Pemetaan Pemeriksaan</a>.
                </div>

                <label class="cek mt16"><input type="checkbox" name="is_kritis" value="1" <?= $cek('is_kritis') ?>> Pantau nilai kritis untuk pemeriksaan ini</label>
                <label class="cek mt8"><input type="checkbox" name="aktif" value="1" <?= $test === null ? 'checked' : $cek('aktif', 1) ?>> Aktif</label>
            </div>
        </div>
    </div>

    <div class="kartu">
        <div class="isi">
            <div class="aksi-baris">
                <button class="tombol utama" type="submit">Simpan</button>
                <a class="tombol" href="<?= $e(Url::to('/master/pemeriksaan')) ?>">Batal</a>
                <span class="kecil redup">Setelah disimpan Anda diarahkan ke pengaturan nilai rujukan.</span>
            </div>
        </div>
    </div>
</form>
