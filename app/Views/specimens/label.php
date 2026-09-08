<?php
/**
 * Halaman label barcode siap cetak (tanpa layout aplikasi).
 *
 * @var array<int,array<string,mixed>> $labels
 * @var string                         $lab
 */
use App\Core\Helper;

$e = static fn ($v) => Helper::e($v);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Label Spesimen</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; margin: 8mm; background: #fff; color: #000; }
  .bar { margin-bottom: 10mm; }
  .label {
    width: 55mm; min-height: 28mm; padding: 2mm; border: 1px dashed #bbb;
    display: inline-block; vertical-align: top; margin: 0 2mm 2mm 0;
    font-size: 8pt; line-height: 1.22; page-break-inside: avoid;
  }
  .label .lab { font-size: 6.5pt; letter-spacing: .3px; text-transform: uppercase; color: #444; }
  .label .nama { font-weight: 700; font-size: 9.5pt; }
  .label .meta { font-size: 7.5pt; }
  .label svg { display: block; margin: 0.8mm 0; }
  .label .tests { font-size: 6.5pt; color: #333; margin-top: .6mm; }
  .cito { border: 2px solid #b3261e !important; }
  .cito .nama::after { content: " • CITO"; color: #b3261e; font-weight: 700; }
  @media print { .bar { display: none; } .label { border: 0; } @page { margin: 6mm; } }
</style>
</head>
<body>

<div class="bar">
  <button onclick="window.print()">Cetak <?= count($labels) ?> label</button>
  <button onclick="window.close()">Tutup</button>
  <span style="font-size:12px;color:#666;margin-left:8px">
    Ukuran label 55 × 28 mm. Sesuaikan skala pada dialog cetak bila memakai printer label khusus.
  </span>
</div>

<?php foreach ($labels as $l): ?>
  <div class="label <?= $l['prioritas'] === 'cito' ? 'cito' : '' ?>">
    <div class="lab"><?= $e($lab) ?></div>
    <div class="nama"><?= $e(Helper::potong((string) $l['nama_pasien'], 26)) ?></div>
    <div class="meta">
      RM <?= $e($l['no_rm']) ?> &middot; <?= $e($l['jk']) ?> &middot;
      <?= $e(Helper::tanggalPendek($l['tgl_lahir'], false)) ?>
    </div>
    <?= $l['barcode_svg'] ?>
    <div class="meta">
      <b><?= $e($l['barcode']) ?></b>
      &nbsp;|&nbsp; Lab <?= $e($l['no_lab'] ?? '-') ?>
    </div>
    <div class="meta"><?= $e($l['jenis_spesimen'] ?? '-') ?> — <?= $e($l['container'] ?? '') ?></div>
    <?php if (!empty($l['pemeriksaan'])): ?>
      <div class="tests"><?= $e(Helper::potong(implode(', ', array_filter($l['pemeriksaan'])), 78)) ?></div>
    <?php endif; ?>
    <div class="meta" style="color:#666"><?= $e(Helper::tanggalPendek($l['tgl_order'])) ?></div>
  </div>
<?php endforeach; ?>

<?php if ($labels === []): ?>
  <p>Tidak ada label untuk dicetak.</p>
<?php endif; ?>

</body>
</html>
