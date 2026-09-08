<?php
/**
 * @var array<string,mixed>            $ringkasan
 * @var array<int,array<string,mixed>> $nilaiKritis
 * @var array<int,array<string,mixed>> $menungguVerifikasi
 * @var array<int,array<string,mixed>> $alat
 * @var int                            $antrianKhanza
 * @var int                            $menggantung
 * @var array<int,array<string,mixed>> $tren
 * @var bool                           $khanzaAktif
 */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e   = static fn ($v) => Helper::e($v);
$num = static fn ($v) => (int) ($v ?? 0);

$maksTren = 1;
foreach ($tren as $t) {
    $maksTren = max($maksTren, (int) $t['jml']);
}
?>
<div id="pesan-dinamis"></div>
<div id="dashboard-auto" data-url="<?= $e(Url::to('/dashboard/statistik')) ?>"></div>

<div class="grid k6" style="margin-bottom:18px">
    <div class="stat info">
        <div class="label">Order Hari Ini</div>
        <div class="angka"><?= $num($ringkasan['total_order'] ?? 0) ?></div>
        <div class="catatan"><?= $num($ringkasan['cito'] ?? 0) ?> cito</div>
    </div>
    <div class="stat">
        <div class="label">Menunggu Sampel</div>
        <div class="angka"><?= $num($ringkasan['menunggu_sampel'] ?? 0) ?></div>
        <div class="catatan">belum diterima lab</div>
    </div>
    <div class="stat">
        <div class="label">Dikerjakan</div>
        <div class="angka"><?= $num($ringkasan['dikerjakan'] ?? 0) ?></div>
        <div class="catatan">proses analitik</div>
    </div>
    <div class="stat hati">
        <div class="label">Menunggu Verifikasi</div>
        <div class="angka" data-stat="menunggu_verifikasi"><?= count($menungguVerifikasi) ?></div>
        <div class="catatan">butuh validasi dokter</div>
    </div>
    <div class="stat bahaya">
        <div class="label">Nilai Kritis</div>
        <div class="angka" data-stat="nilai_kritis"><?= count($nilaiKritis) ?></div>
        <div class="catatan">belum dilaporkan</div>
    </div>
    <div class="stat sukses">
        <div class="label">Selesai</div>
        <div class="angka"><?= $num($ringkasan['selesai'] ?? 0) ?></div>
        <div class="catatan">terverifikasi / dirilis</div>
    </div>
</div>

<?php if ($nilaiKritis !== []): ?>
<div class="kartu" style="border-color:#f5c6c2">
    <div class="kepala" style="background:var(--merah-muda)">
        <h2 style="color:var(--merah)">Nilai Kritis Belum Dilaporkan</h2>
        <div class="kanan kecil redup">Wajib dilaporkan ke DPJP dan dicatat waktunya</div>
    </div>
    <div class="isi rapat">
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr>
                    <th>Waktu</th><th>Pasien</th><th>No. Lab</th><th>Pemeriksaan</th>
                    <th class="angka">Hasil</th><th>Flag</th><th>Catat Pelaporan</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($nilaiKritis as $k): ?>
                    <tr class="cito">
                        <td class="kecil nowrap"><?= $e(Helper::tanggalPendek($k['created_at'])) ?></td>
                        <td>
                            <a href="<?= $e(Url::to('/order/' . $k['order_id'])) ?>"><b><?= $e($k['nama_pasien']) ?></b></a>
                            <div class="kecil redup">RM <?= $e($k['no_rm']) ?></div>
                        </td>
                        <td class="mono"><?= $e($k['no_lab']) ?></td>
                        <td><?= $e($k['nama_test']) ?></td>
                        <td class="angka"><span class="nilai-kritis"><?= $e($k['nilai']) ?></span> <?= $e($k['satuan']) ?></td>
                        <td><span class="flag kritis"><?= $e(Helper::labelFlag((string) $k['flag'])) ?></span></td>
                        <td>
                            <form method="post" action="<?= $e(Url::to('/hasil/' . $k['id'] . '/lapor-kritis')) ?>"
                                  style="display:flex;gap:5px">
                                <?= Csrf::field() ?>
                                <input type="text" name="dilapor_ke" placeholder="Dilaporkan kepada…" required
                                       style="width:170px" class="kecil">
                                <button class="tombol kecil bahaya" type="submit">Catat</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid k2">
    <div class="kartu">
        <div class="kepala">
            <h2>Antrian Verifikasi</h2>
            <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/verifikasi')) ?>">Lihat semua</a></div>
        </div>
        <div class="isi rapat">
            <?php if ($menungguVerifikasi === []): ?>
                <div class="kosong">
                    <div class="besar">Tidak ada antrian</div>
                    Semua hasil sudah diverifikasi.
                </div>
            <?php else: ?>
            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead>
                    <tr><th>No. Lab</th><th>Pasien</th><th class="angka">Hasil</th><th class="angka">Abnormal</th><th>Umur</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($menungguVerifikasi as $v): ?>
                        <tr class="<?= $v['prioritas'] === 'cito' ? 'cito' : '' ?>">
                            <td class="mono"><?= $e($v['no_lab']) ?>
                                <?php if ($v['prioritas'] === 'cito'): ?><span class="badge bahaya">CITO</span><?php endif; ?>
                            </td>
                            <td><b><?= $e($v['nama_pasien']) ?></b><div class="kecil redup">RM <?= $e($v['no_rm']) ?> &middot; <?= $e(ucfirst((string) $v['asal'])) ?></div></td>
                            <td class="angka"><?= $num($v['jml_hasil']) ?></td>
                            <td class="angka">
                                <?php if ($num($v['jml_abnormal']) > 0): ?>
                                    <span class="badge hati"><?= $num($v['jml_abnormal']) ?></span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td class="kecil <?= $num($v['umur_menit']) > 240 ? 'nilai-abnormal' : 'redup' ?>">
                                <?= $e(Helper::durasi($num($v['umur_menit']))) ?>
                            </td>
                            <td><a class="tombol kecil utama" href="<?= $e(Url::to('/verifikasi/' . $v['id'])) ?>">Verifikasi</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="kartu">
            <div class="kepala">
                <h2>Status Alat</h2>
                <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/alat')) ?>">Kelola</a></div>
            </div>
            <div class="isi rapat">
                <?php if ($alat === []): ?>
                    <div class="kosong">
                        <div class="besar">Belum ada alat aktif</div>
                        Tambahkan alat lalu jalankan middleware.
                    </div>
                <?php else: ?>
                <table class="tabel rapat">
                    <tbody>
                    <?php foreach ($alat as $a): ?>
                        <tr>
                            <td>
                                <b><?= $e($a['nama']) ?></b>
                                <div class="kecil redup"><?= $e($a['kode']) ?> &middot; <?= $e(strtoupper((string) $a['protokol'])) ?> / <?= $e($a['transport']) ?></div>
                            </td>
                            <td class="kanan kecil redup"><?= $num($a['pesan_hari_ini']) ?> pesan</td>
                            <td class="kanan">
                                <?php
                                $s = (string) $a['status_koneksi'];
                                $w = $s === 'online' ? 'sukses' : ($s === 'error' ? 'bahaya' : 'redup');
                                ?>
                                <span class="badge <?= $w ?>"><?= $e(ucfirst($s)) ?></span>
                                <div class="kecil redup"><?= $e(Helper::sejak($a['last_seen_at'])) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="kartu">
            <div class="kepala"><h2>Perlu Perhatian</h2></div>
            <div class="isi">
                <div class="grid k2">
                    <div>
                        <div class="label kecil redup">Hasil belum terpetakan</div>
                        <div style="font-size:22px;font-weight:650" data-stat="menggantung"><?= $menggantung ?></div>
                        <?php if ($menggantung > 0): ?>
                            <a class="tombol kecil mt8" href="<?= $e(Url::to('/alat/menggantung')) ?>">Tangani</a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="label kecil redup">Antrian kirim ke Khanza</div>
                        <div style="font-size:22px;font-weight:650" data-stat="antrian_khanza"><?= $antrianKhanza ?></div>
                        <?php if (!$khanzaAktif): ?>
                            <div class="kecil redup">Integrasi nonaktif</div>
                        <?php elseif ($antrianKhanza > 0): ?>
                            <a class="tombol kecil mt8" href="<?= $e(Url::to('/integrasi')) ?>">Proses</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($tren !== []): ?>
                    <div class="mt16">
                        <div class="label kecil redup" style="margin-bottom:6px">Volume order 7 hari terakhir</div>
                        <div class="sparkline">
                            <?php foreach ($tren as $t): ?>
                                <i style="height:<?= max(4, (int) round(((int) $t['jml'] / $maksTren) * 100)) ?>%"
                                   title="<?= $e($t['tgl']) ?>: <?= $num($t['jml']) ?> order"></i>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
