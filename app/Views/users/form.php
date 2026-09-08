<?php
/** @var array<string,mixed>|null $user @var array<int,string> $roles */
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e     = static fn ($v) => Helper::e($v);
$nilai = static fn (string $k, mixed $d = '') => Helper::e(Flash::old($k, $user[$k] ?? $d));
$aksi  = $user === null ? Url::to('/pengguna') : Url::to('/pengguna/' . $user['id']);
?>
<div class="grid k2">
    <form method="post" action="<?= $e($aksi) ?>">
        <?= Csrf::field() ?>
        <div class="kartu">
            <div class="kepala"><h2><?= $user === null ? 'Pengguna Baru' : 'Ubah Pengguna' ?></h2></div>
            <div class="isi">
                <?php if (Flash::errors() !== []): ?>
                    <div class="notif error">
                        <?php foreach (Flash::errors() as $pesan): ?><div><?= $e($pesan) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="grid k2">
                    <div class="form-baris">
                        <label for="username">Nama Pengguna <span style="color:var(--merah)">*</span></label>
                        <input type="text" id="username" name="username" required value="<?= $nilai('username') ?>"
                               pattern="[A-Za-z0-9._-]+" autocomplete="off">
                        <div class="bantuan">Huruf, angka, titik, garis bawah, dan tanda hubung.</div>
                    </div>
                    <div class="form-baris">
                        <label for="role">Peran <span style="color:var(--merah)">*</span></label>
                        <select id="role" name="role" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $e($r) ?>" <?= (string) Flash::old('role', $user['role'] ?? 'analis') === $r ? 'selected' : '' ?>>
                                    <?= $e(ucfirst($r)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-baris">
                    <label for="nama">Nama Lengkap <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="nama" name="nama" required value="<?= $nilai('nama') ?>">
                </div>

                <div class="grid k3">
                    <div class="form-baris">
                        <label for="nip">NIP / NIK Pegawai</label>
                        <input type="text" id="nip" name="nip" value="<?= $nilai('nip') ?>">
                        <div class="bantuan">Dikirim ke Khanza sebagai identitas petugas.</div>
                    </div>
                    <div class="form-baris">
                        <label for="gelar">Gelar</label>
                        <input type="text" id="gelar" name="gelar" value="<?= $nilai('gelar') ?>" placeholder="dr., Sp.PK">
                        <div class="bantuan">Dicetak pada lembar hasil.</div>
                    </div>
                    <div class="form-baris">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= $nilai('email') ?>">
                    </div>
                </div>

                <div class="form-baris">
                    <label for="password">
                        Kata Sandi <?= $user === null ? '<span style="color:var(--merah)">*</span>' : '' ?>
                    </label>
                    <input type="password" id="password" name="password" autocomplete="new-password"
                           <?= $user === null ? 'required' : '' ?>>
                    <div class="bantuan">
                        <?= $user === null ? 'Minimal 8 karakter.' : 'Kosongkan bila tidak ingin mengganti kata sandi.' ?>
                    </div>
                </div>

                <label class="cek"><input type="checkbox" name="aktif" value="1"
                    <?= $user === null ? 'checked' : ((int) Flash::old('aktif', $user['aktif'] ?? 1) === 1 ? 'checked' : '') ?>>
                    Akun aktif (dapat masuk ke sistem)</label>

                <div class="aksi-baris mt16">
                    <button class="tombol utama" type="submit">Simpan</button>
                    <a class="tombol" href="<?= $e(Url::to('/pengguna')) ?>">Batal</a>
                </div>
            </div>
        </div>
    </form>

    <?php if ($user !== null): ?>
    <div class="kartu">
        <div class="kepala"><h2>Reset Kata Sandi</h2></div>
        <div class="isi">
            <p class="kecil redup">
                Gunakan bila pengguna lupa kata sandi. Tindakan ini tercatat pada jejak audit.
                Minta pengguna segera menggantinya melalui menu Profil.
            </p>
            <form method="post" action="<?= $e(Url::to('/pengguna/' . $user['id'] . '/reset-password')) ?>"
                  data-konfirmasi="Reset kata sandi pengguna ini?">
                <?= Csrf::field() ?>
                <div class="form-baris">
                    <label for="password_baru">Kata sandi baru</label>
                    <input type="password" id="password_baru" name="password_baru" required autocomplete="new-password">
                </div>
                <button class="tombol bahaya" type="submit">Reset Kata Sandi</button>
            </form>

            <hr style="border:0;border-top:1px solid var(--garis);margin:18px 0">
            <dl class="rincian">
                <dt>Dibuat</dt><dd><?= $e(Helper::tanggal($user['created_at'] ?? null, true)) ?></dd>
                <dt>Login terakhir</dt><dd><?= $e(Helper::tanggal($user['last_login_at'] ?? null, true)) ?></dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>
</div>
