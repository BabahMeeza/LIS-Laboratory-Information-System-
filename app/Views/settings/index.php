<?php
/** @var array<string,array<int,array<string,mixed>>> $settings */
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);

$judulGrup = [
    'identitas'  => 'Identitas Fasilitas (dicetak pada lembar hasil)',
    'umum'       => 'Umum',
    'barcode'    => 'Barcode Spesimen',
    'penomoran'  => 'Penomoran Order & Laboratorium',
    'validasi'   => 'Validasi Hasil',
    'khanza'     => 'Integrasi SIMRS Khanza',
    'alat'       => 'Middleware Alat',
];

$boolean = ['khanza.aktif', 'hasil.auto_verify', 'khanza.auto_buat_pasien'];
$pilihan = [
    'barcode.format'          => ['YMD-SEQ' => 'Tanggal + urutan harian', 'SEQ' => 'Urutan global'],
    'nolab.reset'             => ['harian' => 'Reset harian', 'bulanan' => 'Reset bulanan', 'tahunan' => 'Reset tahunan'],
    'khanza.mode_order'       => ['push' => 'Push — Khanza mengirim ke LIS', 'pull' => 'Pull — LIS menarik berkala'],
    'khanza.kirim_hasil_saat' => ['verifikasi' => 'Saat hasil diverifikasi', 'rilis' => 'Saat hasil dirilis'],
];
$rahasia = ['khanza.api_secret'];
?>
<form method="post" action="<?= $e(Url::to('/pengaturan')) ?>">
    <?= Csrf::field() ?>

    <div class="kartu">
        <div class="kepala">
            <h2>Pengaturan Sistem</h2>
            <div class="kanan">
                <a class="tombol kecil" href="<?= $e(Url::to('/pengaturan/api')) ?>">Kredensial API</a>
                <a class="tombol kecil" href="<?= $e(Url::to('/pengaturan/audit')) ?>">Jejak Audit</a>
                <button class="tombol utama" type="submit">Simpan Perubahan</button>
            </div>
        </div>
        <div class="isi">
            <p class="kecil redup mt0 mb0">
                Pengaturan di sini dapat diubah kapan saja dan berlaku langsung.
                Parameter yang lebih mendasar — koneksi database dan kunci aplikasi —
                berada pada berkas <span class="mono">config/config.php</span>.
            </p>
        </div>
    </div>

    <?php foreach ($settings as $grup => $daftar): ?>
    <div class="kartu">
        <div class="kepala"><h2><?= $e($judulGrup[$grup] ?? ucfirst((string) $grup)) ?></h2></div>
        <div class="isi">
            <?php foreach ($daftar as $s): ?>
                <?php $key = (string) $s['key']; ?>
                <div class="form-baris">
                    <label for="set-<?= $e($key) ?>"><?= $e($key) ?></label>

                    <?php if (in_array($key, $boolean, true)): ?>
                        <label class="cek">
                            <input type="checkbox" id="set-<?= $e($key) ?>" name="setting[<?= $e($key) ?>]" value="1"
                                   <?= (string) $s['value'] === '1' ? 'checked' : '' ?>>
                            Aktif
                        </label>

                    <?php elseif (isset($pilihan[$key])): ?>
                        <select id="set-<?= $e($key) ?>" name="setting[<?= $e($key) ?>]">
                            <?php foreach ($pilihan[$key] as $v => $label): ?>
                                <option value="<?= $e($v) ?>" <?= (string) $s['value'] === (string) $v ? 'selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif (in_array($key, $rahasia, true)): ?>
                        <input type="password" id="set-<?= $e($key) ?>" name="setting[<?= $e($key) ?>]"
                               value="<?= $e($s['value']) ?>" autocomplete="off">

                    <?php else: ?>
                        <input type="text" id="set-<?= $e($key) ?>" name="setting[<?= $e($key) ?>]" value="<?= $e($s['value']) ?>">
                    <?php endif; ?>

                    <?php if (($s['keterangan'] ?? '') !== ''): ?>
                        <div class="bantuan"><?= $e($s['keterangan']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="kartu">
        <div class="isi">
            <button class="tombol utama" type="submit">Simpan Perubahan</button>
        </div>
    </div>
</form>
