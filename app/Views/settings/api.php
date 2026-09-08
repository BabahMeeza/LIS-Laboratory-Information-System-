<?php
/** @var array<int,array<string,mixed>> $clients @var array<string,string>|null $secret */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<?php if (is_array($secret)): ?>
<div class="kartu" style="border-color:#bfe3ce">
    <div class="kepala" style="background:var(--hijau-muda)">
        <h2 style="color:var(--hijau)">Kredensial Baru — Salin Sekarang</h2>
    </div>
    <div class="isi">
        <p class="kecil">
            Secret hanya ditampilkan satu kali. Setelah halaman ini ditinggalkan, nilainya
            tidak dapat ditampilkan lagi. Simpan di berkas <span class="mono">.env</span> middleware
            atau <span class="mono">config.php</span> konektor Khanza.
        </p>
        <dl class="rincian">
            <dt>Nama</dt><dd><?= $e($secret['nama']) ?></dd>
            <dt>API Key</dt><dd class="mono" style="user-select:all"><?= $e($secret['api_key']) ?></dd>
            <dt>Secret</dt><dd class="mono" style="user-select:all"><?= $e($secret['secret']) ?></dd>
        </dl>
        <pre class="raw" style="margin-top:12px">LIS_API_KEY=<?= $e($secret['api_key']) ?>

LIS_API_SECRET=<?= $e($secret['secret']) ?></pre>
    </div>
</div>
<?php endif; ?>

<div class="kartu">
    <div class="kepala">
        <h2>Kredensial API</h2>
        <div class="kanan"><a class="tombol kecil" href="<?= $e(Url::to('/pengaturan')) ?>">Pengaturan</a></div>
    </div>
    <div class="isi">
        <p class="kecil redup mt0 mb0">
            Kredensial dipakai oleh middleware alat dan konektor SIMRS Khanza untuk memanggil
            <span class="mono">/api/v1/*</span>. Setiap pemanggil sebaiknya memiliki kredensialnya sendiri
            dengan cakupan seminimal mungkin, dan dibatasi ke alamat IP servernya.
        </p>
        <p class="kecil redup mb0">
            Batas IP menerima alamat tunggal, CIDR (<span class="mono">192.168.0.0/24</span>),
            dan kata kunci <span class="mono">lokal</span> untuk seluruh loopback. IPv4 dan IPv6
            keduanya berlaku, dan <span class="mono">127.0.0.1</span> serta <span class="mono">::1</span>
            dianggap mesin yang sama. Kosongkan untuk mengizinkan semua.
            <br>
            Permintaan ini datang dari <b class="mono"><?= $e($ipAnda ?? '?') ?></b> —
            alamat inilah yang dilihat LIS, bukan alamat yang Anda ketik di peramban.
            Pemanggil di mesin yang sama biasanya muncul sebagai <span class="mono">::1</span>.
        </p>
    </div>
    <div class="isi rapat">
        <table class="tabel">
            <thead><tr><th>Nama</th><th>API Key</th><th>Cakupan</th><th>Batas IP</th><th>Terakhir Dipakai</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr style="<?= (int) $c['aktif'] === 0 ? 'opacity:.55' : '' ?>">
                    <td><b><?= $e($c['nama']) ?></b></td>
                    <td class="mono kecil"><?= $e(substr((string) $c['api_key'], 0, 14)) ?>…</td>
                    <td class="kecil"><?php foreach (explode(',', (string) $c['scopes']) as $s): ?>
                        <span class="pil"><?= $e(trim($s)) ?></span>
                    <?php endforeach; ?></td>
                    <td class="kecil">
                        <form method="post" action="<?= $e(Url::to('/pengaturan/api/' . $c['id'] . '/batas-ip')) ?>"
                              style="display:flex; gap:4px; align-items:center">
                            <?= Csrf::field() ?>
                            <input type="text" name="ip_whitelist" class="mono kecil" style="width:190px"
                                   value="<?= $e((string) ($c['ip_whitelist'] ?? '')) ?>"
                                   placeholder="semua alamat">
                            <button class="tombol kecil" type="submit">Simpan</button>
                        </form>
                    </td>
                    <td class="kecil redup"><?= $e(Helper::tanggalPendek($c['last_used_at'])) ?></td>
                    <td><span class="badge <?= (int) $c['aktif'] === 1 ? 'sukses' : 'redup' ?>"><?= (int) $c['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td>
                        <?php if ((int) $c['aktif'] === 1): ?>
                            <form method="post" action="<?= $e(Url::to('/pengaturan/api/' . $c['id'] . '/hapus')) ?>"
                                  data-konfirmasi="Nonaktifkan kredensial ini? Pemanggil yang memakainya akan langsung ditolak.">
                                <?= Csrf::field() ?>
                                <button class="tombol kecil bahaya" type="submit">Nonaktifkan</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="kartu" style="max-width:680px">
    <div class="kepala"><h2>Buat Kredensial Baru</h2></div>
    <div class="isi">
        <form method="post" action="<?= $e(Url::to('/pengaturan/api')) ?>">
            <?= Csrf::field() ?>
            <div class="form-baris">
                <label for="nama">Nama</label>
                <input type="text" id="nama" name="nama" required placeholder="Middleware Alat Lab Lantai 2">
            </div>
            <div class="grid k2">
                <div class="form-baris">
                    <label for="scopes">Cakupan</label>
                    <select id="scopes" name="scopes">
                        <option value="instrument">instrument — middleware alat</option>
                        <option value="khanza">khanza — konektor SIMRS</option>
                        <option value="instrument,khanza">instrument, khanza</option>
                    </select>
                </div>
                <div class="form-baris">
                    <label for="ip_whitelist">Batas IP (opsional)</label>
                    <input type="text" id="ip_whitelist" name="ip_whitelist" placeholder="127.0.0.1, 192.168.1.0/24">
                    <div class="bantuan">Pisahkan dengan koma. Kosongkan untuk mengizinkan semua.</div>
                </div>
            </div>
            <button class="tombol utama" type="submit">Buat Kredensial</button>
        </form>
    </div>
</div>
