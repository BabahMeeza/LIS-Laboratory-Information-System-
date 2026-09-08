<?php
/** @var array<int,array<string,mixed>> $users */
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);

$deskripsiPeran = [
    'admin'       => 'Akses penuh termasuk pengaturan dan pengguna',
    'manajer'     => 'Laporan dan pemantauan, tanpa ubah data',
    'verifikator' => 'Memverifikasi dan merilis hasil (dokter PK)',
    'analis'      => 'Entri hasil, worklist, dan alat',
    'sampling'    => 'Pendaftaran order dan penanganan spesimen',
    'viewer'      => 'Hanya membaca',
];
?>
<div class="kartu">
    <div class="kepala">
        <h2>Pengguna <span class="redup kecil">(<?= count($users) ?>)</span></h2>
        <div class="kanan"><a class="tombol utama" href="<?= $e(Url::to('/pengguna/baru')) ?>">Pengguna Baru</a></div>
    </div>
    <div class="isi rapat">
        <table class="tabel">
            <thead><tr><th>Nama Pengguna</th><th>Nama Lengkap</th><th>NIP</th><th>Peran</th><th>Email</th>
                <th>Login Terakhir</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr style="<?= (int) $u['aktif'] === 0 ? 'opacity:.55' : '' ?>">
                    <td class="mono"><b><?= $e($u['username']) ?></b></td>
                    <td><?= $e($u['nama']) ?><?= ($u['gelar'] ?? '') !== '' ? ', ' . $e($u['gelar']) : '' ?></td>
                    <td class="kecil mono"><?= $e($u['nip'] ?? '-') ?></td>
                    <td>
                        <span class="badge info"><?= $e(ucfirst((string) $u['role'])) ?></span>
                        <div class="kecil redup"><?= $e($deskripsiPeran[(string) $u['role']] ?? '') ?></div>
                    </td>
                    <td class="kecil redup"><?= $e($u['email'] ?? '-') ?></td>
                    <td class="kecil redup"><?= $e(Helper::tanggalPendek($u['last_login_at'])) ?></td>
                    <td><span class="badge <?= (int) $u['aktif'] === 1 ? 'sukses' : 'redup' ?>"><?= (int) $u['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td><a class="tombol kecil" href="<?= $e(Url::to('/pengguna/' . $u['id'] . '/edit')) ?>">Ubah</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="kartu">
    <div class="kepala"><h2>Ringkasan Hak Akses</h2></div>
    <div class="isi rapat">
        <table class="tabel rapat">
            <thead><tr><th>Peran</th><th>Kewenangan</th></tr></thead>
            <tbody>
            <tr><td><span class="badge info">Admin</span></td><td class="kecil">Seluruh modul, termasuk pengaturan sistem, kredensial API, master data, dan jejak audit.</td></tr>
            <tr><td><span class="badge info">Manajer</span></td><td class="kecil">Membaca dashboard, order, hasil, dan seluruh laporan. Tidak dapat mengubah data klinis.</td></tr>
            <tr><td><span class="badge info">Verifikator</span></td><td class="kecil">Memverifikasi, merilis, dan mengoreksi hasil; mencatat pelaporan nilai kritis; entri QC.</td></tr>
            <tr><td><span class="badge info">Analis</span></td><td class="kecil">Entri hasil, worklist, penerimaan spesimen, konfigurasi alat, dan pemetaan parameter.</td></tr>
            <tr><td><span class="badge info">Sampling</span></td><td class="kecil">Pendaftaran order, pengambilan dan penerimaan spesimen, cetak label. Tidak dapat mengisi hasil.</td></tr>
            <tr><td><span class="badge info">Viewer</span></td><td class="kecil">Hanya melihat data; tidak dapat mengubah apa pun.</td></tr>
            </tbody>
        </table>
    </div>
</div>
