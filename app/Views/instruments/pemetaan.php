<?php
/** @var array<string,mixed> $alat @var array<int,array<string,mixed>> $pemetaan @var array<int,array<string,mixed>> $tests */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
$belum = array_filter($pemetaan, static fn ($p) => $p['test_id'] === null && (int) $p['abaikan'] === 0);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Pemetaan Parameter — <?= $e($alat['nama']) ?></h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/alat/' . $alat['id'] . '/edit')) ?>">Konfigurasi Alat</a>
            <a class="tombol kecil" href="<?= $e(Url::to('/alat/log?alat=' . $alat['id'])) ?>">Log</a>
        </div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0">
            Kode parameter yang dikirim alat (mis. <span class="mono">HGB</span>, <span class="mono">WBC</span>)
            dipetakan ke pemeriksaan di master LIS. Kode baru yang belum dikenal akan otomatis muncul di sini
            begitu alat pertama kali mengirimkannya — cukup pilih pemeriksaan tujuannya.
        </p>
        <?php if ($belum !== []): ?>
            <div class="notif peringatan">
                <b><?= count($belum) ?> kode parameter belum dipetakan.</b>
                Hasil untuk kode tersebut tidak tersimpan sampai pemetaan dilengkapi.
                Setelah dipetakan, proses ulang pesan terkait dari menu Log.
            </div>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= $e(Url::to('/alat/' . $alat['id'] . '/pemetaan')) ?>">
    <?= Csrf::field() ?>

    <div class="kartu">
        <div class="kepala">
            <h2>Daftar Pemetaan (<?= count($pemetaan) ?>)</h2>
            <div class="kanan"><input type="search" data-saring="#tabel-map" placeholder="Saring kode…" style="width:200px"></div>
        </div>
        <div class="isi rapat">
            <?php if ($pemetaan === []): ?>
                <div class="kosong">
                    <div class="besar">Belum ada pemetaan</div>
                    Tambahkan manual di bawah, atau biarkan terisi otomatis saat alat mengirim hasil pertama kali.
                </div>
            <?php else: ?>
            <div class="tabel-bungkus">
                <table class="tabel" id="tabel-map">
                    <thead>
                    <tr><th>Kode Alat</th><th>Satuan Alat</th><th style="min-width:280px">Pemeriksaan LIS</th>
                        <th style="width:110px">Faktor</th><th style="width:80px">Abaikan</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pemetaan as $p): ?>
                        <?php $belumPetak = $p['test_id'] === null && (int) $p['abaikan'] === 0; ?>
                        <tr class="<?= $belumPetak ? 'cito' : '' ?>">
                            <td class="mono"><b><?= $e($p['kode_alat']) ?></b></td>
                            <td class="kecil redup"><?= $e($p['satuan_alat'] ?? '-') ?></td>
                            <td>
                                <select name="test_id[<?= (int) $p['id'] ?>]">
                                    <option value="0">— belum dipetakan —</option>
                                    <?php foreach ($tests as $t): ?>
                                        <option value="<?= (int) $t['id'] ?>" <?= (int) $p['test_id'] === (int) $t['id'] ? 'selected' : '' ?>>
                                            <?= $e($t['kode']) ?> — <?= $e($t['nama']) ?><?= $t['satuan'] !== null ? ' (' . $e($t['satuan']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="faktor[<?= (int) $p['id'] ?>]" value="<?= $e($p['faktor']) ?>"
                                       class="kecil" style="font-family:var(--mono)" title="Pengali konversi satuan"></td>
                            <td class="tengah">
                                <input type="checkbox" name="abaikan[<?= (int) $p['id'] ?>]" value="1" <?= (int) $p['abaikan'] === 1 ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="isi" style="border-top:1px solid var(--garis)">
            <h3 style="font-size:13px;margin:0 0 8px">Tambah Pemetaan Manual</h3>
            <div class="grid k3">
                <div class="form-baris">
                    <label for="kode_baru">Kode parameter alat</label>
                    <input type="text" id="kode_baru" name="kode_baru" placeholder="mis. HGB">
                </div>
                <div class="form-baris" style="grid-column: span 2">
                    <label for="test_baru">Pemeriksaan LIS</label>
                    <select id="test_baru" name="test_baru">
                        <option value="0">— pilih —</option>
                        <?php foreach ($tests as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"><?= $e($t['kode']) ?> — <?= $e($t['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="aksi-baris mt8">
                <button class="tombol utama" type="submit">Simpan Pemetaan</button>
                <a class="tombol" href="<?= $e(Url::to('/alat')) ?>">Kembali</a>
            </div>
        </div>
    </div>
</form>
