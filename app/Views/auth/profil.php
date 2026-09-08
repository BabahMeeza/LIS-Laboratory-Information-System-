<?php
/** @var array<string,mixed>|null $user */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="grid k2">
    <div class="kartu">
        <div class="kepala"><h2>Identitas</h2></div>
        <div class="isi">
            <dl class="rincian">
                <dt>Nama</dt><dd><?= $e($user['nama'] ?? '') ?></dd>
                <dt>Nama pengguna</dt><dd class="mono"><?= $e($user['username'] ?? '') ?></dd>
                <dt>NIP</dt><dd><?= $e($user['nip'] ?? '-') ?></dd>
                <dt>Gelar</dt><dd><?= $e($user['gelar'] ?? '-') ?></dd>
                <dt>Peran</dt><dd><span class="badge info"><?= $e(ucfirst((string) ($user['role'] ?? ''))) ?></span></dd>
                <dt>Email</dt><dd><?= $e($user['email'] ?? '-') ?></dd>
                <dt>Login terakhir</dt><dd><?= $e(Helper::tanggal($user['last_login_at'] ?? null, true)) ?></dd>
            </dl>
            <p class="kecil redup mt16 mb0">
                Perubahan nama, peran, dan NIP dilakukan oleh administrator melalui menu Pengguna.
            </p>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Ganti Kata Sandi</h2></div>
        <div class="isi">
            <form method="post" action="<?= $e(Url::to('/profil/password')) ?>">
                <?= Csrf::field() ?>

                <div class="form-baris">
                    <label for="password_lama">Kata sandi saat ini</label>
                    <input type="password" id="password_lama" name="password_lama" required autocomplete="current-password">
                </div>
                <div class="form-baris">
                    <label for="password_baru">Kata sandi baru</label>
                    <input type="password" id="password_baru" name="password_baru" required autocomplete="new-password">
                    <div class="bantuan">Minimal 8 karakter. Gunakan kombinasi huruf, angka, dan simbol.</div>
                </div>
                <div class="form-baris">
                    <label for="password_konfirmasi">Ulangi kata sandi baru</label>
                    <input type="password" id="password_konfirmasi" name="password_konfirmasi" required autocomplete="new-password">
                </div>

                <button class="tombol utama" type="submit">Simpan Kata Sandi</button>
            </form>
        </div>
    </div>
</div>
