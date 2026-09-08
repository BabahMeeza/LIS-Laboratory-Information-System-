<?php
/** @var array<string,mixed>|null $pasien */
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e      = static fn ($v) => Helper::e($v);
$errors = Flash::errors();
$nilai  = static fn (string $k, mixed $d = '') => Helper::e(Flash::old($k, $pasien[$k] ?? $d));
$aksi   = $pasien === null ? Url::to('/pasien') : Url::to('/pasien/' . $pasien['id']);
?>
<form method="post" action="<?= $e($aksi) ?>">
    <?= Csrf::field() ?>

    <div class="kartu" style="max-width:820px">
        <div class="kepala"><h2><?= $pasien === null ? 'Pendaftaran Pasien Baru' : 'Ubah Data Pasien' ?></h2></div>
        <div class="isi">
            <?php if ($errors !== []): ?>
                <div class="notif error">
                    <?php foreach ($errors as $pesan): ?><div><?= $e($pesan) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="grid k2">
                <div class="form-baris">
                    <label for="no_rm">Nomor Rekam Medis</label>
                    <input type="text" id="no_rm" name="no_rm" value="<?= $nilai('no_rm') ?>"
                           <?= $pasien !== null ? 'readonly' : '' ?>>
                    <div class="bantuan">Kosongkan untuk dibuatkan otomatis oleh sistem.</div>
                </div>
                <div class="form-baris">
                    <label for="khanza_no_rkm_medis">No. RM di SIMRS Khanza</label>
                    <input type="text" id="khanza_no_rkm_medis" name="khanza_no_rkm_medis" value="<?= $nilai('khanza_no_rkm_medis') ?>">
                    <div class="bantuan">Kunci pencocokan saat order datang dari Khanza.</div>
                </div>
            </div>

            <div class="form-baris">
                <label for="nama">Nama Lengkap <span style="color:var(--merah)">*</span></label>
                <input type="text" id="nama" name="nama" required value="<?= $nilai('nama') ?>">
            </div>

            <div class="grid k3">
                <div class="form-baris">
                    <label for="jk">Jenis Kelamin <span style="color:var(--merah)">*</span></label>
                    <select id="jk" name="jk" required>
                        <?php foreach (['L' => 'Laki-laki', 'P' => 'Perempuan', 'X' => 'Tidak diketahui'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= (string) Flash::old('jk', $pasien['jk'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="bantuan">Menentukan nilai rujukan yang dipakai.</div>
                </div>
                <div class="form-baris">
                    <label for="tempat_lahir">Tempat Lahir</label>
                    <input type="text" id="tempat_lahir" name="tempat_lahir" value="<?= $nilai('tempat_lahir') ?>">
                </div>
                <div class="form-baris">
                    <label for="tgl_lahir">Tanggal Lahir</label>
                    <input type="date" id="tgl_lahir" name="tgl_lahir" value="<?= $nilai('tgl_lahir') ?>">
                    <div class="bantuan">Menentukan rentang umur nilai rujukan.</div>
                </div>
            </div>

            <div class="grid k3">
                <div class="form-baris">
                    <label for="nik">NIK</label>
                    <input type="text" id="nik" name="nik" maxlength="20" value="<?= $nilai('nik') ?>">
                </div>
                <div class="form-baris">
                    <label for="telepon">Telepon</label>
                    <input type="text" id="telepon" name="telepon" value="<?= $nilai('telepon') ?>">
                </div>
                <div class="form-baris">
                    <label for="gol_darah">Golongan Darah</label>
                    <input type="text" id="gol_darah" name="gol_darah" maxlength="5" value="<?= $nilai('gol_darah') ?>">
                </div>
            </div>

            <div class="grid k2">
                <div class="form-baris">
                    <label for="alamat">Alamat</label>
                    <input type="text" id="alamat" name="alamat" value="<?= $nilai('alamat') ?>">
                </div>
                <div class="form-baris">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= $nilai('email') ?>">
                </div>
            </div>

            <div class="aksi-baris mt16">
                <button class="tombol utama" type="submit">Simpan</button>
                <a class="tombol" href="<?= $e(Url::to('/pasien')) ?>">Batal</a>
            </div>
        </div>
    </div>
</form>
