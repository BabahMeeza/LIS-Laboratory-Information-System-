<?php
/**
 * Tentang Aplikasi.
 *
 * BERKAS INI DITANDATANGANI.
 *
 * Sidik sha256 berkas ini tercatat di config/lisensi.php dan ikut dilindungi
 * tanda tangan HMAC lisensi. Setiap perubahan — satu spasi pun — membuat
 * sidiknya tidak lagi cocok, aplikasi masuk keadaan terkunci, dan seluruh
 * kredensial API berhenti melayani sampai lisensinya diterbitkan ulang:
 *
 *     php bin/lisensi.php --terbitkan
 *
 * Bila Anda memang berhak mengubah isinya, ubah dulu datanya di
 * config/lisensi.php, lalu terbitkan ulang. Jangan menyunting nilainya
 * langsung di sini: yang tampil di layar berasal dari berkas lisensi,
 * bukan dari berkas ini.
 *
 * @var array<string,string> $lisensi
 * @var array<string,string> $sistem
 */
use App\Core\Helper;

$e = static fn ($v) => Helper::e($v);

$urut = [
    'aplikasi'    => 'Nama Aplikasi',
    'versi'       => 'Versi',
    'instansi'    => 'Instansi',
    'unit'        => 'Unit',
    'pengembang'  => 'Pengembang',
    'kontak'      => 'Kontak',
    'lisensi'     => 'Jenis Lisensi',
    'no_lisensi'  => 'Nomor Lisensi',
    'diterbitkan' => 'Diterbitkan',
    'berlaku'     => 'Masa Berlaku',
];

// Medan donasi dikeluarkan dari daftar umum karena punya tampilan sendiri
// di bawah, lengkap dengan tombol salin.
$medanDonasi = ['donasi_bank', 'donasi_rekening', 'donasi_atas_nama', 'donasi_catatan', 'donasi_qris'];

$adaDonasi = ($lisensi['donasi_rekening'] ?? '') !== '';
?>
<div class="kartu" style="max-width:760px">
    <div class="kepala">
        <h2>Tentang Aplikasi</h2>
    </div>
    <div class="isi">
        <dl class="rincian">
            <?php foreach ($urut as $kunci => $label): ?>
                <?php if (($lisensi[$kunci] ?? '') === '') { continue; } ?>
                <dt><?= $e($label) ?></dt>
                <dd><?= $e($lisensi[$kunci]) ?></dd>
            <?php endforeach; ?>
        </dl>

        <?php
        // Medan tambahan yang ditulis pemilik lisensi tetap ditampilkan,
        // supaya menambah keterangan tidak perlu mengubah berkas ini —
        // yang justru akan mengunci aplikasi.
        $sisa = array_diff_key($lisensi, $urut, array_flip($medanDonasi));
        ?>
        <?php if ($sisa !== []): ?>
            <dl class="rincian">
                <?php foreach ($sisa as $kunci => $nilai): ?>
                    <?php if ((string) $nilai === '') { continue; } ?>
                    <dt><?= $e(ucwords(str_replace('_', ' ', (string) $kunci))) ?></dt>
                    <dd><?= $e((string) $nilai) ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </div>
</div>

<?php if ($adaDonasi): ?>
<div class="kartu" style="max-width:760px">
    <div class="kepala">
        <h2>Dukungan Pengembangan</h2>
    </div>
    <div class="isi">
        <p class="kecil redup mt0">
            Aplikasi ini dikembangkan dan dirawat secara mandiri. Bila terasa membantu
            pekerjaan laboratorium, dukungan sukarela membantu kelangsungan perawatannya.
            Sepenuhnya sukarela — tidak ada bagian aplikasi yang dibatasi karenanya.
        </p>

        <dl class="rincian">
            <?php if (($lisensi['donasi_bank'] ?? '') !== ''): ?>
                <dt>Bank</dt>
                <dd><?= $e($lisensi['donasi_bank']) ?></dd>
            <?php endif; ?>

            <dt>Nomor Rekening</dt>
            <dd>
                <span class="mono" id="norek" style="font-size:15px;letter-spacing:.5px"><?= $e($lisensi['donasi_rekening']) ?></span>
                <button type="button" class="tombol kecil" id="salin-norek" style="margin-left:8px">Salin</button>
            </dd>

            <?php if (($lisensi['donasi_atas_nama'] ?? '') !== ''): ?>
                <dt>Atas Nama</dt>
                <dd><?= $e($lisensi['donasi_atas_nama']) ?></dd>
            <?php endif; ?>

            <?php if (($lisensi['donasi_qris'] ?? '') !== ''): ?>
                <dt>QRIS</dt>
                <dd class="kecil"><?= $e($lisensi['donasi_qris']) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if (($lisensi['donasi_catatan'] ?? '') !== ''): ?>
            <p class="kecil redup mb0"><?= $e($lisensi['donasi_catatan']) ?></p>
        <?php endif; ?>

        
    </div>
</div>

<script>
// Nomor rekening panjang dan mudah salah ketik; menyalinnya mengurangi
// risiko dana nyasar ke rekening orang lain karena satu angka tertukar.
(function () {
    var tombol = document.getElementById('salin-norek');
    var teks   = document.getElementById('norek');
    if (!tombol || !teks) { return; }

    tombol.addEventListener('click', function () {
        var nilai = (teks.textContent || '').trim();
        var sudah = function () {
            var asli = tombol.textContent;
            tombol.textContent = 'Tersalin';
            setTimeout(function () { tombol.textContent = asli; }, 1500);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(nilai).then(sudah, function () { pilihSaja(); });
        } else {
            pilihSaja();
        }

        // Tanpa HTTPS, clipboard API tidak tersedia. Daripada tombol yang
        // diam tak berbuat apa-apa, teksnya diblok supaya tinggal Ctrl+C.
        function pilihSaja() {
            var r = document.createRange();
            r.selectNodeContents(teks);
            var s = window.getSelection();
            s.removeAllRanges();
            s.addRange(r);
            try { document.execCommand('copy'); sudah(); } catch (e) { tombol.textContent = 'Tekan Ctrl+C'; }
        }
    });
})();
</script>
<?php endif; ?>

<div class="kartu" style="max-width:760px">
    <div class="kepala">
        <h2>Keutuhan Aplikasi</h2>
    </div>
    <div class="isi">
        <p class="kecil redup mt0">
            Identitas aplikasi di atas ditandatangani secara kriptografis.
        </p>
        <dl class="rincian">
            <dt>Status Lisensi</dt>
            <dd><span class="badge sukses">Sah</span></dd>
            <dt>Sidik Lisensi</dt>
            <dd class="mono kecil"><?= $e($sistem['sidik'] ?? '') ?></dd>
        </dl>
    </div>
</div>

<div class="kartu" style="max-width:760px">
    <div class="kepala">
        <h2>Lingkungan</h2>
    </div>
    <div class="isi">
        <dl class="rincian">
            <dt>Versi Runtime</dt>
            <dd class="mono kecil"><?= $e($sistem['versi_lis'] ?? '') ?></dd>
            <dt>PHP</dt>
            <dd class="mono kecil"><?= $e($sistem['php'] ?? '') ?></dd>
            <dt>Basis Data</dt>
            <dd class="mono kecil"><?= $e($sistem['db'] ?? '') ?></dd>
            <dt>Zona Waktu</dt>
            <dd class="mono kecil"><?= $e($sistem['zona'] ?? '') ?></dd>
        </dl>
    </div>
</div>
