<?php
/** @var int $status @var string $pesan */
use App\Core\Helper;
use App\Core\Url;
?>
<div class="kartu" style="max-width:620px;margin:40px auto">
    <div class="isi" style="text-align:center;padding:36px">
        <div style="font-size:44px;font-weight:700;color:var(--merah);line-height:1"><?= (int) $status ?></div>
        <p style="font-size:15px;margin:14px 0 22px"><?= Helper::e($pesan) ?></p>
        <div class="aksi-baris" style="justify-content:center">
            <a class="tombol utama" href="<?= Helper::e(Url::to('/')) ?>">Kembali ke Dashboard</a>
            <a class="tombol" href="javascript:history.back()">Halaman Sebelumnya</a>
        </div>
    </div>
</div>
