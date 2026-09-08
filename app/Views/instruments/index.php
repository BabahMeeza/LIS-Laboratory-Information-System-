<?php
/** @var array<int,array<string,mixed>> $alat @var array<string,mixed> $middleware @var int $menggantung */
use App\Core\Auth;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);
?>
<div class="grid k4" style="margin-bottom:18px">
    <div class="stat <?= $middleware['ok'] ? 'sukses' : 'bahaya' ?>">
        <div class="label">Middleware Alat</div>
        <div class="angka" style="font-size:17px"><?= $middleware['ok'] ? 'Berjalan' : 'Tidak Aktif' ?></div>
        <div class="catatan"><?= $e(Helper::potong($middleware['pesan'], 46)) ?></div>
    </div>
    <div class="stat info">
        <div class="label">Alat Aktif</div>
        <div class="angka"><?= count(array_filter($alat, static fn ($a) => (int) $a['aktif'] === 1)) ?></div>
        <div class="catatan">dari <?= count($alat) ?> terdaftar</div>
    </div>
    <div class="stat sukses">
        <div class="label">Online</div>
        <div class="angka"><?= count(array_filter($alat, static fn ($a) => $a['status_koneksi'] === 'online')) ?></div>
        <div class="catatan">terhubung saat ini</div>
    </div>
    <div class="stat <?= $menggantung > 0 ? 'bahaya' : '' ?>">
        <div class="label">Hasil Belum Terpetakan</div>
        <div class="angka"><?= $menggantung ?></div>
        <div class="catatan"><a href="<?= $e(Url::to('/alat/menggantung')) ?>">Tangani</a></div>
    </div>
</div>

<div class="kartu">
    <div class="kepala">
        <h2>Alat Laboratorium</h2>
        <div class="kanan">
            <a class="tombol kecil" href="<?= $e(Url::to('/alat/log')) ?>">Log Komunikasi</a>
            <?php if (Auth::can('alat.kelola')): ?>
                <a class="tombol utama" href="<?= $e(Url::to('/alat/baru')) ?>">Tambah Alat</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="isi rapat">
        <?php if ($alat === []): ?>
            <div class="kosong">
                <div class="besar">Belum ada alat terdaftar</div>
                Daftarkan analyzer, lengkapi pemetaan parameter, lalu jalankan middleware.
            </div>
        <?php else: ?>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                <tr><th>Kode</th><th>Alat</th><th>Protokol</th><th>Koneksi</th><th>Mode</th>
                    <th class="angka">Pemetaan</th><th class="angka">Pesan Hari Ini</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($alat as $a): ?>
                    <tr style="<?= (int) $a['aktif'] === 0 ? 'opacity:.55' : '' ?>">
                        <td class="mono"><b><?= $e($a['kode']) ?></b></td>
                        <td>
                            <?= $e($a['nama']) ?>
                            <div class="kecil redup"><?= $e(trim(($a['merk'] ?? '') . ' ' . ($a['model'] ?? ''))) ?><?= $a['kategori'] !== null ? ' · ' . $e($a['kategori']) : '' ?></div>
                        </td>
                        <td><span class="badge netral"><?= $e(strtoupper((string) $a['protokol'])) ?></span></td>
                        <td class="kecil mono">
                            <?php if ($a['transport'] === 'serial'): ?>
                                <?= $e($a['serial_port']) ?> @<?= (int) $a['baud_rate'] ?>
                            <?php else: ?>
                                <?= $e($a['transport'] === 'tcp_server' ? 'listen' : 'connect') ?>
                                <?= $e($a['host']) ?>:<?= (int) $a['port'] ?>
                            <?php endif; ?>
                        </td>
                        <td class="kecil"><?= $a['mode'] === 'bidirectional' ? '<span class="badge info">2 arah</span>' : '<span class="badge redup">1 arah</span>' ?></td>
                        <td class="angka">
                            <?= (int) $a['jml_pemetaan'] ?>
                            <?php if ((int) $a['belum_dipetakan'] > 0): ?>
                                <div><span class="badge hati"><?= (int) $a['belum_dipetakan'] ?> baru</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="angka">
                            <?= (int) $a['pesan_hari_ini'] ?>
                            <?php if ((int) $a['error_hari_ini'] > 0): ?>
                                <div><span class="badge bahaya"><?= (int) $a['error_hari_ini'] ?> error</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $s = (string) $a['status_koneksi']; ?>
                            <span class="badge <?= $s === 'online' ? 'sukses' : ($s === 'error' ? 'bahaya' : 'redup') ?>"><?= $e(ucfirst($s)) ?></span>
                            <div class="kecil redup"><?= $e(Helper::sejak($a['last_seen_at'])) ?></div>
                            <?php if (($a['last_error'] ?? null) !== null): ?>
                                <div class="kecil" style="color:var(--merah)"><?= $e(Helper::potong($a['last_error'], 40)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?php if (Auth::can('alat.pemetaan')): ?>
                                <a class="tombol kecil" href="<?= $e(Url::to('/alat/' . $a['id'] . '/pemetaan')) ?>">Pemetaan</a>
                            <?php endif; ?>
                            <?php if (Auth::can('alat.kelola')): ?>
                                <a class="tombol kecil" href="<?= $e(Url::to('/alat/' . $a['id'] . '/edit')) ?>">Ubah</a>
                            <?php endif; ?>
                            <a class="tombol kecil" href="<?= $e(Url::to('/alat/log?alat=' . $a['id'])) ?>">Log</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Menjalankan Middleware</h2></div>
    <div class="isi">
        <p class="kecil">
            Koneksi ke analyzer ditangani oleh middleware Node.js pada folder <code>middleware/</code>.
            Middleware membaca konfigurasi alat dari LIS melalui API, membuka koneksi TCP atau serial,
            menerjemahkan pesan ASTM/HL7, lalu mengirim hasil kembali ke LIS.
        </p>
        <pre class="raw">cd middleware
npm install
cp .env.example .env      # isi LIS_BASE_URL dan LIS_API_KEY
npm start                 # jalankan gateway

# Uji tanpa hardware — simulator analyzer:
npm run simulate:astm     # kirim contoh hasil hematologi via ASTM
npm run simulate:hl7      # kirim contoh hasil kimia via HL7 MLLP</pre>
    </div>
</div>
