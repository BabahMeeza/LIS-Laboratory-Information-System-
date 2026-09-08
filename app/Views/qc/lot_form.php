<?php
/** @var array<int,array<string,mixed>> $tests @var array<int,array<string,mixed>> $alat */
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<form method="post" action="<?= $e(Url::to('/qc/lot')) ?>">
    <?= Csrf::field() ?>

    <div class="kartu" style="max-width:820px">
        <div class="kepala"><h2>Bahan Kontrol Baru</h2></div>
        <div class="isi">
            <?php if (Flash::errors() !== []): ?>
                <div class="notif error">
                    <?php foreach (Flash::errors() as $pesan): ?><div><?= $e($pesan) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="grid k2">
                <div class="form-baris">
                    <label for="test_id">Pemeriksaan <span style="color:var(--merah)">*</span></label>
                    <select id="test_id" name="test_id" required>
                        <option value="">— pilih —</option>
                        <?php foreach ($tests as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= (int) Flash::old('test_id', 0) === (int) $t['id'] ? 'selected' : '' ?>>
                                <?= $e($t['kode']) ?> — <?= $e($t['nama']) ?><?= $t['satuan'] !== null ? ' (' . $e($t['satuan']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="bantuan">Hanya pemeriksaan dengan hasil numerik yang dapat dikontrol dengan cara ini.</div>
                </div>
                <div class="form-baris">
                    <label for="instrument_id">Alat</label>
                    <select id="instrument_id" name="instrument_id">
                        <option value="">Semua alat</option>
                        <?php foreach ($alat as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= $e($a['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid k3">
                <div class="form-baris">
                    <label for="nama_bahan">Nama Bahan Kontrol <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="nama_bahan" name="nama_bahan" required
                           value="<?= $e(Flash::old('nama_bahan')) ?>" placeholder="Randox Assayed Chemistry">
                </div>
                <div class="form-baris">
                    <label for="lot">Nomor Lot <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="lot" name="lot" required value="<?= $e(Flash::old('lot')) ?>">
                </div>
                <div class="form-baris">
                    <label for="level">Level</label>
                    <select id="level" name="level">
                        <option value="1">Level 1 (rendah / normal)</option>
                        <option value="2">Level 2 (menengah)</option>
                        <option value="3">Level 3 (tinggi / patologis)</option>
                    </select>
                </div>
            </div>

            <div class="grid k3">
                <div class="form-baris">
                    <label for="mean">Nilai Target / Mean <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="mean" name="mean" required inputmode="decimal"
                           value="<?= $e(Flash::old('mean')) ?>" style="font-family:var(--mono)">
                    <div class="bantuan">Dari insert bahan kontrol atau hasil periode evaluasi sendiri.</div>
                </div>
                <div class="form-baris">
                    <label for="sd">Simpangan Baku (SD) <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="sd" name="sd" required inputmode="decimal"
                           value="<?= $e(Flash::old('sd')) ?>" style="font-family:var(--mono)">
                </div>
                <div class="form-baris">
                    <label for="cv_target">Target CV (%)</label>
                    <input type="text" id="cv_target" name="cv_target" inputmode="decimal" value="<?= $e(Flash::old('cv_target')) ?>">
                </div>
            </div>

            <div class="grid k2">
                <div class="form-baris">
                    <label for="tgl_mulai">Tanggal Mulai Dipakai</label>
                    <input type="date" id="tgl_mulai" name="tgl_mulai" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-baris">
                    <label for="tgl_kadaluarsa">Tanggal Kedaluwarsa</label>
                    <input type="date" id="tgl_kadaluarsa" name="tgl_kadaluarsa">
                </div>
            </div>

            <div class="notif info kecil">
                Mean dan SD sebaiknya diverifikasi sendiri dengan minimal 20 kali pengukuran
                pada kondisi rutin, bukan langsung memakai angka dari insert pabrikan.
            </div>

            <div class="aksi-baris mt16">
                <button class="tombol utama" type="submit">Simpan</button>
                <a class="tombol" href="<?= $e(Url::to('/qc')) ?>">Batal</a>
            </div>
        </div>
    </div>
</form>
