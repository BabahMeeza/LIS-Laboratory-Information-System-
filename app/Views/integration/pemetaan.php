<?php
/** @var array<int,array<string,mixed>> $templates @var array<int,array<string,mixed>> $tests @var array<string,string> $filter */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Pemetaan Pemeriksaan Khanza &rarr; LIS</h2>
        <div class="kanan">
            <?php if (Auth::can('integrasi.kelola')): ?>
                <form method="post" action="<?= $e(Url::to('/integrasi/impor-template')) ?>" style="display:inline">
                    <?= Csrf::field() ?><button class="tombol kecil" type="submit">Impor Ulang dari Khanza</button>
                </form>
            <?php endif; ?>
            <a class="tombol kecil" href="<?= $e(Url::to('/integrasi')) ?>">Kembali</a>
        </div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Setiap parameter di <span class="mono">template_laboratorium</span> Khanza (kombinasi
            <span class="mono">kd_jenis_prw</span> + <span class="mono">id_template</span>) perlu dipasangkan
            dengan satu pemeriksaan di master LIS. Pemetaan ini dipakai dua arah: saat order Khanza masuk,
            dan saat hasil dikirim balik ke <span class="mono">detail_hasil_lab</span>.
        </p>
    </div>

    <form class="filter" method="get">
        <div class="form-baris">
            <label>Tampilkan</label>
            <select name="tampil">
                <option value="belum" <?= $filter['tampil'] === 'belum' ? 'selected' : '' ?>>Belum dipetakan</option>
                <option value="sudah" <?= $filter['tampil'] === 'sudah' ? 'selected' : '' ?>>Sudah dipetakan</option>
                <option value="semua" <?= $filter['tampil'] === 'semua' ? 'selected' : '' ?>>Semua</option>
            </select>
        </div>
        <div class="form-baris">
            <label>Cari</label>
            <input type="search" name="q" value="<?= $e($filter['cari']) ?>" placeholder="Nama pemeriksaan atau kode" style="min-width:250px">
        </div>
        <button class="tombol utama" type="submit">Terapkan</button>
    </form>
</div>

<form method="post" action="<?= $e(Url::to('/integrasi/pemetaan')) ?>">
    <?= Csrf::field() ?>

    <div class="kartu">
        <div class="kepala">
            <h2>Daftar Template (<?= count($templates) ?>)</h2>
            <div class="kanan"><input type="search" data-saring="#tabel-kzmap" placeholder="Saring cepat…" style="width:200px"></div>
        </div>
        <div class="isi rapat">
            <?php if ($templates === []): ?>
                <div class="kosong">
                    <div class="besar">Tidak ada template</div>
                    Jalankan "Impor Ulang dari Khanza" untuk mengambil master pemeriksaan.
                </div>
            <?php else: ?>
            <div class="tabel-bungkus">
                <table class="tabel" id="tabel-kzmap">
                    <thead>
                    <tr><th>kd_jenis_prw</th><th>Jenis Perawatan</th><th>id_template</th><th>Parameter Khanza</th>
                        <th>Satuan</th><th>Rujukan (LD)</th><th style="min-width:280px">Pemeriksaan LIS</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $t): ?>
                        <tr class="<?= $t['test_id'] === null ? 'cito' : '' ?>">
                            <td class="mono"><?= $e($t['kd_jenis_prw']) ?></td>
                            <td class="kecil"><?= $e(Helper::potong($t['nm_perawatan'], 32)) ?></td>
                            <td class="mono kecil"><?= (int) $t['id_template'] ?></td>
                            <td><b><?= $e($t['pemeriksaan'] ?? '-') ?></b></td>
                            <td class="kecil redup"><?= $e($t['satuan'] ?? '') ?></td>
                            <td class="kecil redup"><?= $e($t['nilai_rujukan_ld'] ?? '') ?></td>
                            <td>
                                <select name="test_id[<?= (int) $t['id'] ?>]">
                                    <option value="0">— belum dipetakan —</option>
                                    <?php foreach ($tests as $lis): ?>
                                        <option value="<?= (int) $lis['id'] ?>" <?= (int) $t['test_id'] === (int) $lis['id'] ? 'selected' : '' ?>>
                                            <?= $e($lis['kode']) ?> — <?= $e($lis['nama']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if (Auth::can('integrasi.kelola') && $templates !== []): ?>
        <div class="isi" style="border-top:1px solid var(--garis)">
            <div class="aksi-baris">
                <button class="tombol utama" type="submit">Simpan Pemetaan</button>
                <span class="kecil redup">Pemetaan juga ditulis ke master pemeriksaan LIS agar dipakai otomatis.</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</form>
