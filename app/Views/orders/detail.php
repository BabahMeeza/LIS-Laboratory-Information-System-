<?php
/**
 * @var array<string,mixed>            $order
 * @var array<int,array<string,mixed>> $items
 * @var array<int,array<string,mixed>> $spesimen
 * @var array<int,array<string,mixed>> $sync
 * @var int|null                       $tat
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Order <?= $e($order['no_order']) ?>
            <?php if ($order['prioritas'] === 'cito'): ?><span class="badge bahaya">CITO</span><?php endif; ?>
            <span class="badge <?= $e(Helper::warnaStatus((string) $order['status'])) ?>"><?= $e(Helper::labelStatus((string) $order['status'])) ?></span>
        </h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/spesimen/label-batch?order_id=' . $order['id'])) ?>" target="_blank">Cetak Label</a>
            <?php if (Auth::can('hasil.entri') && !in_array((string) $order['status'], ['cancelled', 'released'], true)): ?>
                <a class="tombol kecil" href="<?= $e(Url::to('/hasil/' . $order['id'] . '/entri')) ?>">Entri Hasil</a>
            <?php endif; ?>
            <?php if (Auth::can('hasil.verifikasi') && in_array((string) $order['status'], ['resulted', 'in_progress'], true)): ?>
                <a class="tombol kecil utama" href="<?= $e(Url::to('/verifikasi/' . $order['id'])) ?>">Verifikasi</a>
            <?php endif; ?>
            <?php if (in_array((string) $order['status'], ['verified', 'released'], true)): ?>
                <a class="tombol kecil sukses" href="<?= $e(Url::to('/laporan/hasil/' . $order['id'])) ?>" target="_blank">Lembar Hasil</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="isi">
        <div class="grid k3">
            <dl class="rincian">
                <dt>Pasien</dt><dd><a href="<?= $e(Url::to('/pasien/' . $order['patient_id'])) ?>"><b><?= $e($order['nama_pasien']) ?></b></a></dd>
                <dt>No. RM</dt><dd class="mono"><?= $e($order['no_rm']) ?></dd>
                <dt>JK / Umur</dt><dd><?= $e(Helper::jenisKelamin($order['jk'])) ?> &middot; <?= $e(Helper::umurTeks($order['tgl_lahir'])) ?></dd>
                <dt>Alamat</dt><dd class="kecil"><?= $e($order['alamat'] ?? '-') ?></dd>
            </dl>
            <dl class="rincian">
                <dt>No. Lab</dt><dd class="mono"><b><?= $e($order['no_lab'] ?? '-') ?></b></dd>
                <dt>Waktu order</dt><dd><?= $e(Helper::tanggal($order['tgl_order'], true)) ?></dd>
                <dt>Asal</dt><dd><?= $e(ucfirst((string) $order['asal'])) ?> <?= $order['nama_ruang'] !== null ? '— ' . $e($order['nama_ruang']) : '' ?></dd>
                <dt>Cara bayar</dt><dd><?= $e($order['nama_carabayar'] ?? '-') ?></dd>
                <dt>Perujuk</dt><dd><?= $e($order['dokter_perujuk'] ?? '-') ?></dd>
            </dl>
            <dl class="rincian">
                <dt>Diagnosa</dt><dd><?= $e($order['diagnosa_klinis'] ?? '-') ?></dd>
                <dt>Sumber</dt><dd>
                    <span class="badge <?= $order['sumber'] === 'khanza' ? 'ungu' : 'netral' ?>"><?= $e(ucfirst((string) $order['sumber'])) ?></span>
                    <?php if ($order['khanza_noorder'] !== null): ?>
                        <div class="kecil mono redup"><?= $e($order['khanza_noorder']) ?></div>
                    <?php endif; ?>
                </dd>
                <dt>No. Rawat</dt><dd class="mono kecil"><?= $e($order['khanza_no_rawat'] ?? '-') ?></dd>
                <dt>TAT berjalan</dt><dd><?= $e(Helper::durasi($tat)) ?></dd>
                <dt>Total tarif</dt><dd><?= $e(Helper::rupiah($order['total_harga'])) ?></dd>
            </dl>
        </div>

        <?php if (($order['catatan'] ?? null) !== null && $order['catatan'] !== ''): ?>
            <div class="notif info mt16">Catatan: <?= $e($order['catatan']) ?></div>
        <?php endif; ?>
        <?php if (($order['alasan_batal'] ?? null) !== null && $order['alasan_batal'] !== ''): ?>
            <div class="notif error mt16">Dibatalkan: <?= $e($order['alasan_batal']) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Spesimen</h2></div>
    <div class="isi rapat">
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead><tr><th>Barcode</th><th>Jenis</th><th>Wadah</th><th>Status</th><th>Kondisi</th><th>Diambil</th><th>Diterima</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($spesimen as $s): ?>
                    <tr>
                        <td class="mono"><b><?= $e($s['barcode']) ?></b></td>
                        <td><?= $e($s['jenis_spesimen'] ?? '-') ?></td>
                        <td class="kecil redup"><?= $e($s['container'] ?? '-') ?><?= $s['warna'] !== null ? ' (' . $e($s['warna']) . ')' : '' ?></td>
                        <td><span class="badge <?= $e(Helper::warnaStatus((string) $s['status'])) ?>"><?= $e(Helper::labelStatus((string) $s['status'])) ?></span></td>
                        <td class="kecil"><?= $e(ucfirst(str_replace('_', ' ', (string) $s['kondisi']))) ?>
                            <?php if (($s['alasan_tolak'] ?? null) !== null): ?>
                                <div class="kecil nilai-abnormal"><?= $e($s['alasan_tolak']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($s['collected_at'])) ?><div><?= $e($s['diambil_oleh'] ?? '') ?></div></td>
                        <td class="kecil redup"><?= $e(Helper::tanggalPendek($s['received_at'])) ?><div><?= $e($s['diterima_oleh'] ?? '') ?></div></td>
                        <td class="nowrap">
                            <a class="tombol kecil" href="<?= $e(Url::to('/spesimen/' . $s['id'] . '/label')) ?>" target="_blank">Label</a>
                            <?php if (Auth::can('spesimen.terima') && (string) $s['status'] !== 'received'): ?>
                                <form method="post" action="<?= $e(Url::to('/spesimen/' . $s['id'] . '/terima')) ?>" style="display:inline">
                                    <?= Csrf::field() ?>
                                    <button class="tombol kecil utama" type="submit">Terima</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Pemeriksaan &amp; Hasil (<?= count($items) ?>)</h2></div>
    <div class="isi rapat">
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr>
                    <th>Kategori</th><th>Pemeriksaan</th><th class="angka">Hasil</th><th>Satuan</th>
                    <th>Flag</th><th>Rujukan</th><th>Status</th><th>Sumber</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td class="kecil redup"><?= $e($i['kategori'] ?? '-') ?></td>
                        <td><?= $e($i['nama_test']) ?><div class="kecil redup mono"><?= $e($i['kode_test']) ?></div></td>
                        <td class="angka">
                            <?php $kelas = Helper::warnaFlag((string) ($i['flag'] ?? '')); ?>
                            <span class="<?= $kelas === 'kritis' ? 'nilai-kritis' : ($kelas === 'abnormal' ? 'nilai-abnormal' : 'tebal') ?>">
                                <?= $e($i['nilai'] ?? '—') ?></span>
                        </td>
                        <td class="kecil redup"><?= $e($i['satuan'] ?? $i['satuan_master'] ?? '') ?></td>
                        <td><?php if (($i['flag'] ?? '') !== '' && $i['flag'] !== 'N'): ?>
                            <span class="flag <?= $e($kelas) ?>"><?= $e(Helper::labelFlag((string) $i['flag'])) ?></span>
                        <?php endif; ?></td>
                        <td class="kecil redup"><?= $e($i['ref_teks'] ?? '') ?></td>
                        <td>
                            <?php if ($i['result_id'] === null): ?>
                                <span class="badge redup">Belum ada</span>
                            <?php else: ?>
                                <span class="badge <?= $e(Helper::warnaStatus((string) $i['status_hasil'])) ?>"><?= $e(Helper::labelStatus((string) $i['status_hasil'])) ?></span>
                                <?php if ((string) $i['delta_check'] === 'flagged'): ?>
                                    <span class="badge hati" title="Perubahan <?= $e($i['delta_persen']) ?>% dari hasil sebelumnya">Δ</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="kecil redup">
                            <?php if ($i['result_id'] === null): ?>—
                            <?php elseif ((int) $i['is_manual'] === 1): ?>Manual<div><?= $e($i['diperiksa_oleh'] ?? '') ?></div>
                            <?php else: ?><?= $e($i['nama_alat'] ?? 'Alat') ?><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($i['result_id'] !== null): ?>
                                <a class="tombol kecil" href="<?= $e(Url::to('/hasil/' . $i['result_id'] . '/riwayat')) ?>">Riwayat</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid k2">
    <?php if (Auth::can('order.buat') && !in_array((string) $order['status'], ['cancelled', 'released'], true)): ?>
    <div class="kartu">
        <div class="kepala"><h2>Batalkan Order</h2></div>
        <div class="isi">
            <form method="post" action="<?= $e(Url::to('/order/' . $order['id'] . '/batal')) ?>"
                  data-konfirmasi="Batalkan order ini? Tindakan tercatat pada jejak audit.">
                <?= Csrf::field() ?>
                <div class="form-baris">
                    <label for="alasan">Alasan pembatalan</label>
                    <input type="text" id="alasan" name="alasan" required placeholder="Contoh: permintaan dibatalkan dokter">
                </div>
                <button class="tombol bahaya" type="submit">Batalkan Order</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($sync !== []): ?>
    <div class="kartu">
        <div class="kepala"><h2>Riwayat Sinkronisasi Khanza</h2></div>
        <div class="isi rapat">
            <table class="tabel rapat">
                <thead><tr><th>Waktu</th><th>Arah</th><th>Jenis</th><th>Status</th><th>Pesan</th></tr></thead>
                <tbody>
                <?php foreach ($sync as $s): ?>
                    <tr>
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($s['created_at'])) ?></td>
                        <td class="kecil"><?= $e($s['arah']) ?></td>
                        <td class="kecil"><?= $e($s['jenis']) ?></td>
                        <td><span class="badge <?= $s['status'] === 'sukses' ? 'sukses' : ($s['status'] === 'antri' ? 'hati' : 'bahaya') ?>"><?= $e($s['status']) ?></span></td>
                        <td class="kecil redup"><?= $e(Helper::potong($s['pesan'], 70)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
