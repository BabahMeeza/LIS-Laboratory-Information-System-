<?php
/** @var array<string,mixed> $row */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Helper;
use App\Core\Url;

$e = static fn ($v) => Helper::e($v);

// Tampilkan karakter kendali ASTM/HL7 agar terbaca manusia.
$tampilkanRaw = static function (?string $raw): string {
    if ($raw === null || $raw === '') {
        return '(tidak disimpan)';
    }
    $peta = [
        "\x02" => '<STX>', "\x03" => '<ETX>', "\x04" => '<EOT>', "\x05" => '<ENQ>',
        "\x06" => '<ACK>', "\x15" => '<NAK>', "\x17" => '<ETB>', "\x0B" => '<VT>',
        "\x1C" => '<FS>',  "\x0D" => "<CR>\n", "\x0A" => '<LF>',
    ];

    return htmlspecialchars(strtr($raw, $peta), ENT_QUOTES);
};

$parsed = json_decode((string) ($row['parsed'] ?? ''), true);
?>
<div class="kartu">
    <div class="kepala">
        <h2>Pesan Alat #<?= (int) $row['id'] ?></h2>
        <div class="kanan">
            <?php if (Auth::can('alat.kelola') && $row['parsed'] !== null): ?>
                <form method="post" action="<?= $e(Url::to('/alat/log/' . $row['id'] . '/proses-ulang')) ?>" style="display:inline"
                      data-konfirmasi="Proses ulang pesan ini? Hasil yang sudah diverifikasi tidak akan ditimpa.">
                    <?= Csrf::field() ?>
                    <button class="tombol kecil utama" type="submit">Proses Ulang</button>
                </form>
            <?php endif; ?>
            <a class="tombol kecil" href="<?= $e(Url::to('/alat/log')) ?>">Kembali</a>
        </div>
    </div>
    <div class="isi">
        <div class="grid k3">
            <dl class="rincian">
                <dt>Alat</dt><dd><?= $e($row['nama_alat'] ?? $row['kode_alat'] ?? '?') ?></dd>
                <dt>Waktu terima</dt><dd><?= $e(Helper::tanggal($row['created_at'], true)) ?></dd>
                <dt>Diproses</dt><dd><?= $e(Helper::tanggal($row['processed_at'], true)) ?></dd>
            </dl>
            <dl class="rincian">
                <dt>Arah</dt><dd><?= $e($row['arah']) ?></dd>
                <dt>Protokol</dt><dd><?= $e(strtoupper((string) ($row['protokol'] ?? '-'))) ?></dd>
                <dt>Sample ID</dt><dd class="mono"><?= $e($row['sample_id'] ?? '-') ?></dd>
            </dl>
            <dl class="rincian">
                <dt>Status</dt><dd><span class="badge netral"><?= $e(ucfirst(str_replace('_', ' ', (string) $row['status']))) ?></span></dd>
                <dt>Hasil</dt><dd><?= (int) $row['jml_tersimpan'] ?> tersimpan dari <?= (int) $row['jml_hasil'] ?></dd>
                <dt>Error</dt><dd style="color:var(--merah)"><?= $e($row['pesan_error'] ?? '-') ?></dd>
            </dl>
        </div>
    </div>
</div>

<div class="grid k2">
    <div class="kartu">
        <div class="kepala"><h2>Pesan Mentah</h2></div>
        <div class="isi">
            <pre class="raw"><?= $tampilkanRaw($row['raw']) ?></pre>
        </div>
    </div>

    <div class="kartu">
        <div class="kepala"><h2>Hasil Parsing</h2></div>
        <div class="isi">
            <?php if (is_array($parsed)): ?>
                <?php foreach (($parsed['samples'] ?? []) as $s): ?>
                    <h3 style="font-size:13px;margin:0 0 6px">Sample <span class="mono"><?= $e($s['sample_id'] ?? '?') ?></span></h3>
                    <table class="tabel rapat" style="margin-bottom:14px">
                        <thead><tr><th>Kode</th><th class="angka">Nilai</th><th>Satuan</th><th>Flag</th><th>Waktu</th></tr></thead>
                        <tbody>
                        <?php foreach (($s['results'] ?? []) as $r): ?>
                            <tr>
                                <td class="mono"><?= $e($r['code'] ?? '') ?></td>
                                <td class="angka mono"><?= $e($r['value'] ?? '') ?></td>
                                <td class="kecil redup"><?= $e($r['unit'] ?? '') ?></td>
                                <td class="kecil"><?= $e($r['flags'] ?? '') ?></td>
                                <td class="kecil redup"><?= $e($r['completed_at'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="kosong">Tidak ada payload terparse untuk pesan ini.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
