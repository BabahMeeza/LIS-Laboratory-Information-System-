<?php
/**
 * @var array<string,mixed>            $order
 * @var array<int,array<string,mixed>> $items
 * @var int|null                       $umur
 */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);

$perKategori = [];
foreach ($items as $i) {
    $perKategori[(string) ($i['kategori'] ?? 'Lain-lain')][] = $i;
}
?>
<div class="kartu">
    <div class="kepala">
        <h2>Entri Hasil — <?= $e($order['no_lab'] ?? $order['no_order']) ?>
            <?php if ($order['prioritas'] === 'cito'): ?><span class="badge bahaya">CITO</span><?php endif; ?>
        </h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/order/' . $order['id'])) ?>">Detail Order</a>
            <a class="tombol kecil" href="<?= $e(Url::to('/pasien/' . $order['patient_id'] . '/riwayat')) ?>">Riwayat Pasien</a>
        </div>
    </div>
    <div class="isi">
        <div class="grid k3">
            <dl class="rincian">
                <dt>Pasien</dt><dd><b><?= $e($order['nama_pasien']) ?></b></dd>
                <dt>No. RM</dt><dd class="mono"><?= $e($order['no_rm']) ?></dd>
            </dl>
            <dl class="rincian">
                <dt>JK / Umur</dt><dd><?= $e(Helper::jenisKelamin($order['jk'])) ?> &middot; <?= $e(Helper::umurTeks($order['tgl_lahir'])) ?></dd>
                <dt>Asal</dt><dd><?= $e(ucfirst((string) $order['asal'])) ?></dd>
            </dl>
            <dl class="rincian">
                <dt>Diagnosa</dt><dd><?= $e($order['diagnosa_klinis'] ?? '-') ?></dd>
                <dt>Perujuk</dt><dd><?= $e($order['dokter_perujuk'] ?? '-') ?></dd>
            </dl>
        </div>
        <?php if (($order['tgl_lahir'] ?? null) === null || $umur === null): ?>
            <div class="notif peringatan mt8">
                <b>Tanggal lahir pasien belum terisi.</b>
                Nilai rujukan yang ditampilkan memakai asumsi pasien dewasa dan bisa keliru
                untuk bayi, anak, atau lansia.
                <a href="<?= $e(Url::to('/pasien/' . $order['patient_id'] . '/edit')) ?>">Lengkapi data pasien</a>
                sebelum memverifikasi hasil.
            </div>
        <?php endif; ?>
        <?php if (($order['jk'] ?? 'X') === 'X'): ?>
            <div class="notif peringatan mt8">
                <b>Jenis kelamin pasien belum terisi.</b>
                Rujukan yang bergantung jenis kelamin (Hb, hematokrit, kreatinin, asam urat)
                memakai rentang gabungan dan bisa kurang tepat.
            </div>
        <?php endif; ?>
        <p class="kecil redup mt8 mb0">
            Nilai rujukan di bawah sudah disesuaikan dengan jenis kelamin dan umur pasien.
            Tekan <b>Enter</b> untuk berpindah ke kolom berikutnya.
            Hasil yang sudah diverifikasi tidak dapat diubah di sini — gunakan menu koreksi.
        </p>
    </div>
</div>

<form method="post" action="<?= $e(Url::to('/hasil/' . $order['id'] . '/simpan')) ?>" data-form-hasil>
    <?= Csrf::field() ?>

    <?php foreach ($perKategori as $namaKategori => $daftar): ?>
    <div class="kartu">
        <div class="kepala"><h2><?= $e($namaKategori) ?></h2></div>
        <div class="isi rapat">
            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead>
                    <tr>
                        <th style="min-width:210px">Pemeriksaan</th>
                        <th style="width:180px">Hasil</th>
                        <th>Satuan</th>
                        <th>Nilai Rujukan</th>
                        <th>Sebelumnya</th>
                        <th style="width:200px">Catatan</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daftar as $i): ?>
                        <?php
                        $terkunci = $i['result_id'] !== null
                            && in_array((string) $i['status_hasil'], ['verified', 'corrected'], true);
                        $ruj = $i['rujukan'] ?? null;
                        ?>
                        <tr>
                            <td>
                                <b><?= $e($i['nama_test']) ?></b>
                                <div class="kecil redup mono"><?= $e($i['kode_test']) ?><?= $i['metode'] !== null ? ' · ' . $e($i['metode']) : '' ?></div>
                            </td>
                            <td>
                                <?php if ($terkunci): ?>
                                    <span class="tebal"><?= $e($i['nilai']) ?></span>
                                    <input type="hidden" name="nilai[<?= (int) $i['order_item_id'] ?>]" value="">
                                <?php elseif ($i['tipe_hasil'] === 'pilihan' && $i['opsi'] !== []): ?>
                                    <select name="nilai[<?= (int) $i['order_item_id'] ?>]">
                                        <option value="">— pilih —</option>
                                        <?php foreach ($i['opsi'] as $opsi): ?>
                                            <option value="<?= $e($opsi) ?>" <?= (string) $i['nilai'] === $opsi ? 'selected' : '' ?>><?= $e($opsi) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($i['tipe_hasil'] === 'narasi'): ?>
                                    <textarea name="nilai[<?= (int) $i['order_item_id'] ?>]" rows="3"><?= $e($i['nilai']) ?></textarea>
                                <?php else: ?>
                                    <input type="text"
                                           name="nilai[<?= (int) $i['order_item_id'] ?>]"
                                           value="<?= $e($i['nilai']) ?>"
                                           inputmode="<?= $i['tipe_hasil'] === 'numerik' ? 'decimal' : 'text' ?>"
                                           <?php if ($ruj !== null): ?>
                                               data-low="<?= $e($ruj['low']) ?>"
                                               data-high="<?= $e($ruj['high']) ?>"
                                               data-clow="<?= $e($ruj['critical_low']) ?>"
                                               data-chigh="<?= $e($ruj['critical_high']) ?>"
                                           <?php endif; ?>
                                           style="font-family:var(--mono)">
                                <?php endif; ?>
                            </td>
                            <td class="kecil redup"><?= $e($i['satuan_master'] ?? '') ?></td>
                            <td class="kecil">
                                <?= $e($i['ref_tampil']) ?>
                                <?php if ($ruj !== null && ($ruj['critical_low'] !== null || $ruj['critical_high'] !== null)): ?>
                                    <div class="kecil" style="color:var(--merah)">
                                        Kritis:
                                        <?= $ruj['critical_low'] !== null ? '&le; ' . $e(rtrim(rtrim((string) $ruj['critical_low'], '0'), '.')) : '' ?>
                                        <?= $ruj['critical_high'] !== null ? '&ge; ' . $e(rtrim(rtrim((string) $ruj['critical_high'], '0'), '.')) : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="kecil redup">
                                <?php foreach ($i['sebelumnya'] as $s): ?>
                                    <div>
                                        <?= $e($s['nilai']) ?>
                                        <?php if ($s['flag'] !== '' && $s['flag'] !== 'N'): ?>
                                            <span class="flag <?= $e(Helper::warnaFlag((string) $s['flag'])) ?>"><?= $e(Helper::labelFlag((string) $s['flag'])) ?></span>
                                        <?php endif; ?>
                                        <span style="opacity:.7">(<?= $e(Helper::tanggalPendek($s['created_at'], false)) ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($i['sebelumnya'] === []): ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$terkunci): ?>
                                    <input type="text" name="catatan[<?= (int) $i['order_item_id'] ?>]"
                                           value="<?= $e($i['catatan']) ?>" class="kecil">
                                <?php else: ?>
                                    <span class="kecil redup"><?= $e($i['catatan'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($i['result_id'] === null): ?>
                                    <span class="badge redup">Kosong</span>
                                <?php else: ?>
                                    <span class="badge <?= $e(Helper::warnaStatus((string) $i['status_hasil'])) ?>"><?= $e(Helper::labelStatus((string) $i['status_hasil'])) ?></span>
                                    <?php if ((int) $i['is_manual'] === 0): ?>
                                        <div class="kecil redup"><?= $e($i['nama_alat'] ?? 'alat') ?></div>
                                    <?php endif; ?>
                                    <?php if ((string) $i['delta_check'] === 'flagged'): ?>
                                        <div><span class="badge hati" title="Perubahan <?= $e($i['delta_persen']) ?>% terhadap hasil sebelumnya">Delta <?= $e($i['delta_persen']) ?>%</span></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="kartu">
        <div class="isi">
            <div class="aksi-baris">
                <button class="tombol utama" type="submit">Simpan Hasil</button>
                <button class="tombol sukses" type="submit" name="lanjut" value="verifikasi">Simpan &amp; Lanjut ke Verifikasi</button>
                <a class="tombol" href="<?= $e(Url::to('/worklist')) ?>">Kembali ke Worklist</a>
            </div>
        </div>
    </div>
</form>
