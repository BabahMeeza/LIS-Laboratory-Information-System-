<?php
/**
 * @var array<int,array<string,mixed>> $kategori
 * @var array<int,array<string,mixed>> $tests
 * @var array<int,array<string,mixed>> $panels
 * @var array<string,mixed>|null       $pasien
 */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);

$perKategori = [];
foreach ($tests as $t) {
    $perKategori[(string) ($t['kategori'] ?? 'Lain-lain')][] = $t;
}
?>
<form method="post" action="<?= $e(Url::to('/order')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="patient_id" id="patient_id" value="<?= $e($pasien['id'] ?? '') ?>">

    <div class="grid k2">
        <div class="kartu">
            <div class="kepala"><h2>1. Pasien</h2></div>
            <div class="isi">
                <div id="pasien-terpilih" class="notif info" style="<?= $pasien === null ? 'display:none' : '' ?>">
                    <?php if ($pasien !== null): ?>
                        <b><?= $e($pasien['nama']) ?></b> &middot; RM <?= $e($pasien['no_rm']) ?>
                        &middot; <?= $e(Helper::jenisKelamin($pasien['jk'])) ?>
                        &middot; <?= $e(Helper::umurTeks($pasien['tgl_lahir'])) ?>
                    <?php endif; ?>
                </div>

                <div class="form-baris">
                    <label for="cari-pasien">Cari pasien</label>
                    <input type="search" id="cari-pasien" data-url="<?= $e(Url::to('/pasien/cari')) ?>"
                           placeholder="Ketik nama, No. RM, atau NIK (minimal 2 huruf)">
                    <div class="bantuan">Belum terdaftar? <a href="<?= $e(Url::to('/pasien/baru')) ?>">Daftarkan pasien baru</a>.</div>
                </div>
                <div id="hasil-pasien"></div>
            </div>
        </div>

        <div class="kartu">
            <div class="kepala"><h2>2. Data Permintaan</h2></div>
            <div class="isi">
                <div class="grid k2">
                    <div class="form-baris">
                        <label for="asal">Asal Pasien</label>
                        <select id="asal" name="asal">
                            <option value="ralan">Rawat Jalan</option>
                            <option value="ranap">Rawat Inap</option>
                            <option value="igd">IGD</option>
                            <option value="luar">Rujukan Luar</option>
                            <option value="mcu">MCU</option>
                        </select>
                    </div>
                    <div class="form-baris">
                        <label for="prioritas">Prioritas</label>
                        <select id="prioritas" name="prioritas">
                            <option value="rutin">Rutin</option>
                            <option value="cito">CITO</option>
                        </select>
                    </div>
                </div>
                <div class="grid k2">
                    <div class="form-baris">
                        <label for="nama_ruang">Ruang / Poli</label>
                        <input type="text" id="nama_ruang" name="nama_ruang">
                    </div>
                    <div class="form-baris">
                        <label for="nama_carabayar">Cara Bayar</label>
                        <input type="text" id="nama_carabayar" name="nama_carabayar" placeholder="Umum / BPJS / Asuransi">
                    </div>
                </div>
                <div class="form-baris">
                    <label for="dokter_perujuk">Dokter Perujuk</label>
                    <input type="text" id="dokter_perujuk" name="dokter_perujuk">
                </div>
                <div class="form-baris">
                    <label for="diagnosa_klinis">Diagnosa Klinis</label>
                    <input type="text" id="diagnosa_klinis" name="diagnosa_klinis">
                </div>
                <div class="form-baris">
                    <label for="catatan">Catatan</label>
                    <textarea id="catatan" name="catatan" rows="2"></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala">
            <h2>3. Pilih Pemeriksaan</h2>
            <div class="kanan">
                <input type="search" data-saring="#tabel-pemeriksaan" placeholder="Saring pemeriksaan…" style="width:220px">
                <span class="badge info"><span id="jumlah-order">0</span> item</span>
                <span class="badge sukses" id="total-order">Rp 0</span>
            </div>
        </div>

        <div class="isi">
            <h3 style="font-size:13px;margin:0 0 8px">Paket Pemeriksaan</h3>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
                <?php foreach ($panels as $p): ?>
                    <label class="cek" style="border:1px solid var(--garis-tebal);border-radius:5px;padding:6px 11px;background:#fff">
                        <input type="checkbox" name="panels[]" value="<?= (int) $p['id'] ?>" data-harga="<?= (float) $p['harga'] ?>">
                        <span>
                            <b><?= $e($p['nama']) ?></b>
                            <span class="kecil redup">(<?= (int) $p['jml_item'] ?> item)</span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="isi rapat">
            <div class="tabel-bungkus" style="max-height:520px;overflow-y:auto">
                <table class="tabel rapat" id="tabel-pemeriksaan">
                    <thead>
                    <tr><th style="width:34px"></th><th>Kode</th><th>Pemeriksaan</th><th>Kategori</th><th>Spesimen</th><th>Satuan</th><th class="angka">Tarif</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($perKategori as $namaKategori => $daftar): ?>
                        <?php foreach ($daftar as $t): ?>
                            <tr>
                                <td><input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>" data-harga="<?= (float) $t['harga'] ?>"></td>
                                <td class="mono kecil"><?= $e($t['kode']) ?></td>
                                <td><?= $e($t['nama']) ?></td>
                                <td class="kecil redup"><?= $e($namaKategori) ?></td>
                                <td class="kecil redup"><?= $e($t['spesimen'] ?? '-') ?></td>
                                <td class="kecil redup"><?= $e($t['satuan'] ?? '') ?></td>
                                <td class="angka kecil"><?= $e(number_format((float) $t['harga'], 0, ',', '.')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="isi" style="border-top:1px solid var(--garis)">
            <div class="aksi-baris">
                <button class="tombol utama" type="submit">Simpan Order &amp; Buat Barcode</button>
                <a class="tombol" href="<?= $e(Url::to('/order')) ?>">Batal</a>
                <span class="kecil redup">Sistem membuat satu spesimen per jenis tabung yang diperlukan, lengkap dengan barcode.</span>
            </div>
        </div>
    </div>
</form>
