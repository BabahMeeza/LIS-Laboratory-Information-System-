<?php
/**
 * @var array<int,array<string,mixed>> $daftar
 * @var array<int,array<string,mixed>> $terakhir
 * @var bool $wajibBaca
 * @var int  $batasBawaan
 */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Nilai Kritis
            <span class="redup kecil">(<?= count($daftar) ?> menunggu pelaporan)</span>
        </h2>
    </div>
    <div class="isi">
        <p class="kecil redup" style="margin:0">
            Nilai kritis wajib dilaporkan kepada klinisi peminta dalam
            <b><?= (int) $batasBawaan ?> menit</b> sejak terdeteksi, dan penerima wajib
            <b>mengulang</b> nilai yang disebutkan sebelum pelaporan dianggap sah
            (CLSI GP47). Salah dengar di telepon adalah moda kegagalan yang nyata:
            &ldquo;tujuh koma dua&rdquo; mudah terdengar sebagai &ldquo;tujuh puluh dua&rdquo;.
        </p>
    </div>
</div>

<?php if ($daftar === []): ?>
    <div class="kartu">
        <div class="isi">
            <div class="kosong">
                <div class="besar">Tidak ada nilai kritis tertunggak</div>
                Semua nilai kritis yang terdeteksi sudah dilaporkan.
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($daftar as $b): ?>
        <?php $lewat = (int) ($b['lewat_tenggat'] ?? 0) === 1; ?>
        <div class="kartu" style="border-left:4px solid var(--<?= $lewat ? 'merah' : 'kuning' ?>)">
            <div class="kepala">
                <h2>
                    <?= $e($b['nama_pasien']) ?>
                    <span class="kecil redup mono">RM <?= $e($b['no_rm']) ?></span>
                </h2>
                <div class="kanan">
                    <?php if ($lewat): ?>
                        <span class="badge bahaya">
                            Lewat tenggat <?= max(0, (int) $b['usia_menit'] - (int) $b['batas_menit']) ?> menit
                        </span>
                    <?php else: ?>
                        <span class="badge hati">
                            Sisa <?= max(0, (int) $b['batas_menit'] - (int) $b['usia_menit']) ?> menit
                        </span>
                    <?php endif; ?>
                    <?php if ((string) ($b['prioritas'] ?? '') === 'cito'): ?>
                        <span class="badge bahaya">CITO</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="isi">
                <div class="kecil redup" style="margin-bottom:8px">
                    <span class="mono"><?= $e($b['no_order']) ?></span>
                    <?php if (($b['no_lab'] ?? '') !== ''): ?>
                        &middot; Lab <?= $e($b['no_lab']) ?>
                    <?php endif; ?>
                    <?php if (($b['nama_ruang'] ?? '') !== ''): ?>
                        &middot; <?= $e($b['nama_ruang']) ?>
                    <?php endif; ?>
                    <?php if (($b['dokter_perujuk'] ?? '') !== ''): ?>
                        &middot; <?= $e($b['dokter_perujuk']) ?>
                    <?php endif; ?>
                </div>

                <p style="margin:0 0 4px">
                    <b><?= $e($b['nama_test']) ?></b>
                    <span class="nilai-kritis mono tebal" style="font-size:20px;margin:0 6px">
                        <?= $e($b['nilai']) ?> <?= $e($b['satuan'] ?? '') ?>
                    </span>
                    <span class="badge bahaya"><?= $e($b['flag']) ?></span>
                </p>
                <div class="kecil redup">
                    Terdeteksi <?= $e(Helper::tanggalPendek($b['terdeteksi_at'])) ?>
                    &middot; menunggu <?= (int) $b['usia_menit'] ?> menit
                </div>
            </div>

            <div class="isi">
                <form method="post" action="<?= $e(Url::to('/nilai-kritis/' . $b['id'] . '/lapor')) ?>">
                    <?= Csrf::field() ?>
                    <div class="grid k4">
                        <div class="form-baris">
                            <label for="pn<?= (int) $b['id'] ?>">
                                Dilaporkan kepada <span style="color:var(--merah)">*</span>
                            </label>
                            <input type="text" id="pn<?= (int) $b['id'] ?>" name="penerima_nama"
                                   required placeholder="Nama penerima">
                        </div>
                        <div class="form-baris">
                            <label for="pp<?= (int) $b['id'] ?>">Peran</label>
                            <input type="text" id="pp<?= (int) $b['id'] ?>" name="penerima_peran"
                                   placeholder="DPJP / perawat jaga">
                        </div>
                        <div class="form-baris">
                            <label for="cr<?= (int) $b['id'] ?>">Cara</label>
                            <select id="cr<?= (int) $b['id'] ?>" name="cara">
                                <option value="telepon">Telepon</option>
                                <option value="langsung">Langsung</option>
                                <option value="wa">Pesan (WA)</option>
                                <option value="lainnya">Lainnya</option>
                            </select>
                        </div>
                        <div class="form-baris">
                            <label for="bu<?= (int) $b['id'] ?>">
                                Pembacaan ulang
                                <?php if ($wajibBaca): ?><span style="color:var(--merah)">*</span><?php endif; ?>
                            </label>
                            <input type="text" id="bu<?= (int) $b['id'] ?>" name="bacaan_ulang"
                                   style="font-family:var(--mono)"
                                   <?= $wajibBaca ? 'required' : '' ?>
                                   placeholder="Angka yang disebut penerima">
                        </div>
                    </div>
                    <div class="form-baris">
                        <label for="ct<?= (int) $b['id'] ?>">Catatan</label>
                        <input type="text" id="ct<?= (int) $b['id'] ?>" name="catatan"
                               placeholder="Instruksi klinisi, tindakan yang diminta, dsb.">
                    </div>
                    <p class="kecil redup" style="margin:0 0 8px">
                        Ketik apa yang penerima <i>sebutkan kembali</i> — jangan menyalin nilai di atas.
                        Bila berbeda, sistem menolak dan penyampaian harus diulang. Itulah gunanya.
                    </p>
                    <button class="tombol utama" type="submit">Catat pelaporan</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($terakhir !== []): ?>
<div class="kartu">
    <div class="kepala"><h2>Pelaporan Terakhir</h2></div>
    <div class="isi rapat">
        <div class="tabel-bungkus">
            <table class="tabel rapat">
                <thead>
                <tr>
                    <th>Pasien</th><th>Pemeriksaan</th><th class="angka">Nilai</th>
                    <th>Terdeteksi</th><th>Dilaporkan</th><th>Ketepatan</th>
                    <th>Penerima</th><th>Baca ulang</th><th>Oleh</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($terakhir as $t): ?>
                    <tr>
                        <td>
                            <?= $e($t['nama_pasien']) ?>
                            <div class="kecil redup mono"><?= $e($t['no_order']) ?></div>
                        </td>
                        <td><?= $e($t['nama_test']) ?></td>
                        <td class="angka mono">
                            <?= $e($t['nilai']) ?>
                            <span class="kecil redup"><?= $e($t['satuan'] ?? '') ?></span>
                        </td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($t['terdeteksi_at'])) ?></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($t['dilapor_at'])) ?></td>
                        <td>
                            <?php $lambat = (int) ($t['terlambat_menit'] ?? 0); ?>
                            <span class="badge <?= $lambat > 0 ? 'bahaya' : 'sukses' ?>">
                                <?= $lambat > 0 ? '+' . $lambat . ' mnt' : 'tepat waktu' ?>
                            </span>
                        </td>
                        <td>
                            <?= $e($t['penerima_nama']) ?>
                            <?php if (($t['penerima_peran'] ?? '') !== ''): ?>
                                <div class="kecil redup"><?= $e($t['penerima_peran']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="mono kecil">
                            <?php if ($t['bacaan_ulang'] !== null): ?>
                                <?= $e($t['bacaan_ulang']) ?>
                                <?php if ((int) ($t['bacaan_cocok'] ?? 0) === 1): ?>
                                    <span class="badge sukses">cocok</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="redup">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="kecil redup"><?= $e($t['pelapor'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
