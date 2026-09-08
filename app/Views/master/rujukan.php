<?php
/** @var array<string,mixed> $test @var array<int,array<string,mixed>> $rujukan */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e   = static fn ($v) => Helper::e($v);
$thn = static fn ($hari) => $hari === null ? '-' : round(((int) $hari) / 365, 1);
$num = static fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
?>
<div class="kartu">
    <div class="kepala">
        <h2>Nilai Rujukan — <?= $e($test['nama']) ?> <span class="redup kecil mono"><?= $e($test['kode']) ?></span></h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/master/pemeriksaan/' . $test['id'] . '/edit')) ?>">Ubah Pemeriksaan</a>
            <a class="tombol kecil" href="<?= $e(Url::to('/master/pemeriksaan')) ?>">Daftar Pemeriksaan</a>
        </div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Saat hasil masuk, sistem memilih satu baris rujukan berdasarkan jenis kelamin dan umur pasien.
            Baris paling spesifik menang: jenis kelamin eksplisit (L/P) mengalahkan "semua", lalu rentang umur tersempit.
            Isi <b>nilai kritis</b> hanya untuk parameter yang wajib dilaporkan segera ke DPJP —
            hasil di luar ambang itu akan menahan verifikasi sampai pelaporan dicatat.
        </p>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Rujukan Terpasang (<?= count($rujukan) ?>)</h2></div>
    <div class="isi rapat">
        <?php if ($rujukan === []): ?>
            <div class="kosong">
                <div class="besar">Belum ada nilai rujukan</div>
                Tanpa rujukan, hasil tidak akan ditandai normal/abnormal.
            </div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>JK</th><th>Umur (tahun)</th><th class="angka">Batas Bawah</th><th class="angka">Batas Atas</th>
                    <th class="angka">Kritis Bawah</th><th class="angka">Kritis Atas</th>
                    <th>Teks Rujukan</th><th>Nilai Normal (teks)</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rujukan as $r): ?>
                    <tr>
                        <td>
                            <span class="badge <?= $r['jk'] === 'A' ? 'netral' : 'info' ?>">
                                <?= $r['jk'] === 'L' ? 'Laki-laki' : ($r['jk'] === 'P' ? 'Perempuan' : 'Semua') ?>
                            </span>
                        </td>
                        <td class="kecil"><?= $thn($r['umur_min_hari']) ?> – <?= $thn($r['umur_max_hari']) ?></td>
                        <td class="angka mono"><?= $e($num($r['low'])) ?></td>
                        <td class="angka mono"><?= $e($num($r['high'])) ?></td>
                        <td class="angka mono" style="color:var(--merah)"><?= $e($num($r['critical_low'])) ?></td>
                        <td class="angka mono" style="color:var(--merah)"><?= $e($num($r['critical_high'])) ?></td>
                        <td class="kecil"><?= $e($r['teks_rujukan'] ?? '') ?></td>
                        <td class="kecil"><?= $e($r['nilai_normal_teks'] ?? '') ?></td>
                        <td>
                            <?php if (Auth::can('master.kelola')): ?>
                                <form method="post" action="<?= $e(Url::to('/master/rujukan/' . $r['id'] . '/hapus')) ?>"
                                      data-konfirmasi="Hapus baris nilai rujukan ini?">
                                    <?= Csrf::field() ?>
                                    <button class="tombol kecil bahaya" type="submit">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::can('master.kelola')): ?>
<div class="kartu">
    <div class="kepala"><h2>Tambah Nilai Rujukan</h2></div>
    <div class="isi">
        <form method="post" action="<?= $e(Url::to('/master/pemeriksaan/' . $test['id'] . '/rujukan')) ?>">
            <?= Csrf::field() ?>
            <div class="grid k3">
                <div class="form-baris">
                    <label for="jk">Jenis Kelamin</label>
                    <select id="jk" name="jk">
                        <option value="A">Semua</option>
                        <option value="L">Laki-laki</option>
                        <option value="P">Perempuan</option>
                    </select>
                </div>
                <div class="form-baris">
                    <label for="umur_min_tahun">Umur Minimum (tahun)</label>
                    <input type="number" id="umur_min_tahun" name="umur_min_tahun" min="0" max="120" value="0">
                </div>
                <div class="form-baris">
                    <label for="umur_max_tahun">Umur Maksimum (tahun)</label>
                    <input type="number" id="umur_max_tahun" name="umur_max_tahun" min="0" max="120" value="120">
                </div>
            </div>

            <?php if ($test['tipe_hasil'] === 'numerik'): ?>
            <div class="grid k4">
                <div class="form-baris">
                    <label for="low">Batas Bawah Normal</label>
                    <input type="text" id="low" name="low" inputmode="decimal" placeholder="13.2">
                </div>
                <div class="form-baris">
                    <label for="high">Batas Atas Normal</label>
                    <input type="text" id="high" name="high" inputmode="decimal" placeholder="17.3">
                </div>
                <div class="form-baris">
                    <label for="critical_low" style="color:var(--merah)">Nilai Kritis Bawah</label>
                    <input type="text" id="critical_low" name="critical_low" inputmode="decimal" placeholder="7.0">
                </div>
                <div class="form-baris">
                    <label for="critical_high" style="color:var(--merah)">Nilai Kritis Atas</label>
                    <input type="text" id="critical_high" name="critical_high" inputmode="decimal" placeholder="20.0">
                </div>
            </div>
            <?php endif; ?>

            <div class="grid k2">
                <div class="form-baris">
                    <label for="teks_rujukan">Teks Rujukan (dicetak pada lembar hasil)</label>
                    <input type="text" id="teks_rujukan" name="teks_rujukan" placeholder="13.2 - 17.3">
                </div>
                <div class="form-baris">
                    <label for="nilai_normal_teks">Nilai Normal untuk Hasil Kualitatif</label>
                    <input type="text" id="nilai_normal_teks" name="nilai_normal_teks" placeholder="Negatif">
                    <div class="bantuan">Untuk tipe pilihan/teks. Hasil selain ini ditandai abnormal (A).</div>
                </div>
            </div>
            <div class="form-baris">
                <label for="catatan">Catatan</label>
                <input type="text" id="catatan" name="catatan" placeholder="Sumber rujukan, mis. Kemenkes 2023 / insert kit">
            </div>

            <button class="tombol utama" type="submit">Tambah Rujukan</button>
        </form>
    </div>
</div>
<?php endif; ?>
