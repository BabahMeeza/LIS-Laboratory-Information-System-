<?php
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="masuk-latar">
    <div class="masuk-kotak">
        <h1>LIS Khanza</h1>
        <div class="sub">
            Laboratory Information System<br>
            <?= $e(Config::setting('app.nama_faskes', 'Fasilitas Kesehatan')) ?>
        </div>

        <?php foreach (Flash::ambil() as $pesan): ?>
            <div class="notif <?= $e($pesan['tipe']) ?>"><?= $e($pesan['pesan']) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= $e(Url::to('/login')) ?>">
            <?= Csrf::field() ?>

            <div class="form-baris">
                <label for="username">Nama Pengguna</label>
                <input type="text" id="username" name="username" autocomplete="username"
                       autofocus required value="<?= $e(Flash::old('username')) ?>">
            </div>

            <div class="form-baris">
                <label for="password">Kata Sandi</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button class="tombol utama" type="submit" style="width:100%;justify-content:center;padding:8px">
                Masuk
            </button>
        </form>

        <div class="kecil redup" style="margin-top:18px;text-align:center">
            Akses sistem ini tercatat dalam jejak audit.
        </div>
    </div>
</div>
