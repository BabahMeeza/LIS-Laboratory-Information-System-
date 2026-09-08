<?php
/**
 * @var array<string,mixed>            $order
 * @var array<int,array<string,mixed>> $items
 * @var int|null                       $umur
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);

$perKategori = [];
$adaKritisBelumLapor = false;
foreach ($items as $i) {
    $perKategori[(string) ($i['kategori'] ?? 'Lain-lain')][] = $i;
    if ((int) ($i['is_kritis'] ?? 0) === 1 && ($i['kritis_dilapor_at'] ?? null) === null) {
        $adaKritisBelumLapor = true;
    }
}
?>
<div class="kartu">
    <div class="kepala">
        <h2>Verifikasi — <?= $e($order['no_lab'] ?? $order['no_order']) ?>
            <?php if ($order['prioritas'] === 'cito'): ?><span class="badge bahaya">CITO</span><?php endif; ?>
        </h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/laporan/hasil/' . $order['id'] . '?draft=1')) ?>" target="_blank">Pratinjau Lembar Hasil</a>
            <a class="tombol kecil" href="<?= $e(Url::to('/hasil/' . $order['id'] . '/entri')) ?>">Ubah Hasil</a>
        </div>
    </div>
    <div class="isi">
        <div class="grid k3">
            <dl class="rincian">
                <dt>Pasien</dt><dd><b><?= $e($order['nama_pasien']) ?></b></dd>
                <dt>No. RM</dt><dd class="mono"><?= $e($order['no_rm']) ?></dd>
                <dt>JK / Umur</dt><dd><?= $e(Helper::jenisKelamin($order['jk'])) ?> &middot; <?= $e(Helper::umurTeks($order['tgl_lahir'])) ?></dd>
            </dl>
            <dl class="rincian">
                <dt>Asal</dt><dd><?= $e(ucfirst((string) $order['asal'])) ?> <?= $order['nama_ruang'] !== null ? '— ' . $e($order['nama_ruang']) : '' ?></dd>
                <dt>Perujuk</dt><dd><?= $e($order['dokter_perujuk'] ?? '-') ?></dd>
                <dt>Diagnosa</dt><dd><?= $e($order['diagnosa_klinis'] ?? '-') ?></dd>
            </dl>
            <dl class="rincian">
                <dt>Waktu order</dt><dd><?= $e(Helper::tanggal($order['tgl_order'], true)) ?></dd>
                <dt>Status</dt><dd><span class="badge <?= $e(Helper::warnaStatus((string) $order['status'])) ?>"><?= $e(Helper::labelStatus((string) $order['status'])) ?></span></dd>
                <dt>Sumber</dt><dd><?= $e(ucfirst((string) $order['sumber'])) ?></dd>
            </dl>
        </div>
    </div>
</div>

<?php if ($adaKritisBelumLapor): ?>
<div class="notif error">
    <b>Nilai kritis belum tercatat pelaporannya.</b>
    Verifikasi akan ditahan sampai pelaporan ke DPJP dicatat. Gunakan tombol "Catat Pelaporan" di baris terkait
    (atau di Dashboard) terlebih dahulu.
</div>
<?php endif; ?>

<form method="post" action="<?= $e(Url::to('/verifikasi/' . $order['id'])) ?>">
    <?= Csrf::field() ?>

    <?php foreach ($perKategori as $namaKategori => $daftar): ?>
    <div class="kartu">
        <div class="kepala"><h2><?= $e($namaKategori) ?></h2></div>
        <div class="isi rapat">
            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" data-centang-semua='input[name="verifikasi[]"]' checked></th>
                        <th>Pemeriksaan</th><th class="angka">Hasil</th><th>Satuan</th><th>Flag</th>
                        <th>Rujukan</th><th>Sebelumnya</th><th>Sumber</th><th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daftar as $i): ?>
                        <?php
                        $sudah  = in_array((string) $i['status_hasil'], ['verified', 'corrected'], true);
                        $kelas  = Helper::warnaFlag((string) ($i['flag'] ?? ''));
                        $kritis = (int) ($i['is_kritis'] ?? 0) === 1;
                        ?>
                        <tr class="<?= $kritis ? 'cito' : '' ?>">
                            <td>
                                <?php if ($i['result_id'] !== null && !$sudah): ?>
                                    <input type="checkbox" name="verifikasi[]" value="<?= (int) $i['result_id'] ?>" checked>
                                <?php endif; ?>
                            </td>
                            <td>
                                <b><?= $e($i['nama_test']) ?></b>
                                <div class="kecil redup mono"><?= $e($i['kode_test']) ?></div>
                            </td>
                            <td class="angka">
                                <span class="<?= $kelas === 'kritis' ? 'nilai-kritis' : ($kelas === 'abnormal' ? 'nilai-abnormal' : 'tebal') ?>">
                                    <?= $e($i['nilai'] ?? '—') ?></span>
                            </td>
                            <td class="kecil redup"><?= $e($i['satuan'] ?? $i['satuan_master'] ?? '') ?></td>
                            <td>
                                <?php if (($i['flag'] ?? '') !== '' && $i['flag'] !== 'N'): ?>
                                    <span class="flag <?= $e($kelas) ?>"><?= $e(Helper::labelFlag((string) $i['flag'])) ?></span>
                                <?php endif; ?>
                                <?php if (($i['flag_alat'] ?? null) !== null && $i['flag_alat'] !== ''): ?>
                                    <div class="kecil redup" title="Flag dari alat"><?= $e($i['flag_alat']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="kecil redup"><?= $e($i['ref_teks'] ?? '') ?></td>
                            <td class="kecil redup">
                                <?php foreach ($i['sebelumnya'] as $s): ?>
                                    <div><?= $e($s['nilai']) ?> <span style="opacity:.7">(<?= $e(Helper::tanggalPendek($s['created_at'], false)) ?>)</span></div>
                                <?php endforeach; ?>
                                <?php if ((string) $i['delta_check'] === 'flagged'): ?>
                                    <span class="badge hati">Δ <?= $e($i['delta_persen']) ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="kecil redup">
                                <?php if ($i['result_id'] === null): ?>—
                                <?php elseif ((int) $i['is_manual'] === 1): ?>Manual
                                <?php else: ?><?= $e($i['nama_alat'] ?? 'Alat') ?><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($i['result_id'] === null): ?>
                                    <span class="badge redup">Belum ada</span>
                                <?php else: ?>
                                    <span class="badge <?= $e(Helper::warnaStatus((string) $i['status_hasil'])) ?>"><?= $e(Helper::labelStatus((string) $i['status_hasil'])) ?></span>
                                <?php endif; ?>
                                <?php if ($kritis && ($i['kritis_dilapor_at'] ?? null) === null): ?>
                                    <div class="kecil" style="color:var(--merah)">Kritis — belum dilapor</div>
                                <?php elseif ($kritis): ?>
                                    <div class="kecil redup">Dilapor <?= $e(Helper::tanggalPendek($i['kritis_dilapor_at'])) ?></div>
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
                <?php if (Auth::can('hasil.verifikasi')): ?>
                    <button class="tombol sukses" type="submit">Verifikasi Hasil Terpilih</button>
                    <button class="tombol" type="submit" name="semua" value="1">Verifikasi Semua</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<div class="grid k2">
    <?php if (Auth::can('hasil.rilis')): ?>
    <div class="kartu">
        <div class="kepala"><h2>Rilis Hasil</h2></div>
        <div class="isi">
            <p class="kecil redup">
                Rilis menandai hasil sah untuk dipakai klinis, menghentikan hitungan TAT,
                dan (bila diatur) mengirim hasil ke SIMRS Khanza.
            </p>
            <form method="post" action="<?= $e(Url::to('/verifikasi/' . $order['id'] . '/rilis')) ?>"
                  data-konfirmasi="Rilis hasil order ini?">
                <?= Csrf::field() ?>
                <label class="cek" style="margin-bottom:10px">
                    <input type="checkbox" name="rilis_sebagian" value="1">
                    Rilis sebagian (izinkan meski ada pemeriksaan belum diverifikasi)
                </label>
                <button class="tombol utama" type="submit">Rilis Hasil</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (Auth::can('hasil.batal_verifikasi')): ?>
    <div class="kartu">
        <div class="kepala"><h2>Batalkan Verifikasi</h2></div>
        <div class="isi">
            <p class="kecil redup">
                Hanya berlaku sebelum hasil dirilis. Setelah dirilis, gunakan koreksi hasil.
            </p>
            <form method="post" action="<?= $e(Url::to('/verifikasi/' . $order['id'] . '/batal')) ?>"
                  data-konfirmasi="Batalkan verifikasi seluruh hasil pada order ini?">
                <?= Csrf::field() ?>
                <div class="form-baris">
                    <label for="alasan_batal">Alasan</label>
                    <input type="text" id="alasan_batal" name="alasan" required>
                </div>
                <button class="tombol bahaya" type="submit">Batalkan Verifikasi</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
