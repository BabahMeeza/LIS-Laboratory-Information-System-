<?php
/**
 * Lembar hasil pasien — halaman cetak mandiri (tanpa layout aplikasi).
 *
 * @var array<string,mixed> $order
 * @var array<string,array<int,array<string,mixed>>> $perKategori
 * @var array<string,mixed>|null $verifikator
 * @var bool   $draft
 * @var string $barcodeSvg
 * @var array<string,string> $identitas
 * @var int|null $tat
 */
use App\Core\Helper;

$e = static fn ($v) => Helper::e($v);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Hasil Laboratorium — <?= $e($order['nama_pasien']) ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: "Times New Roman", Times, serif;
    font-size: 11pt; margin: 0; padding: 14mm; color: #000; background: #fff;
  }
  .bar { margin-bottom: 10mm; font-family: Arial, sans-serif; }
  .bar button { padding: 6px 14px; font-size: 13px; cursor: pointer; }

  .kop { display: flex; align-items: flex-start; gap: 12mm; border-bottom: 2.5px solid #000; padding-bottom: 3mm; }
  .kop .teks { flex: 1; }
  .kop h1 { margin: 0; font-size: 15pt; letter-spacing: .5px; }
  .kop h2 { margin: 1mm 0 0; font-size: 12pt; font-weight: normal; }
  .kop .alamat { font-size: 9pt; margin-top: 1mm; }
  .kop .barcode { text-align: right; }
  .kop img.logo { height: 18mm; }

  .judul { text-align: center; font-size: 13pt; font-weight: bold;
           text-decoration: underline; margin: 5mm 0 4mm; letter-spacing: 1px; }

  table.identitas { width: 100%; font-size: 10pt; border-collapse: collapse; margin-bottom: 4mm; }
  table.identitas td { padding: .6mm 0; vertical-align: top; }
  table.identitas td.k { width: 30mm; }
  table.identitas td.p { width: 3mm; }

  table.hasil { width: 100%; border-collapse: collapse; font-size: 10pt; }
  table.hasil th {
    border-top: 1.5px solid #000; border-bottom: 1.5px solid #000;
    padding: 1.6mm 2mm; text-align: left; font-size: 9.5pt; text-transform: uppercase;
  }
  table.hasil td { padding: 1.2mm 2mm; vertical-align: top; }
  table.hasil tr.kategori td {
    font-weight: bold; padding-top: 3mm; text-decoration: underline; font-size: 10.5pt;
  }
  table.hasil td.nilai { text-align: right; font-weight: bold; white-space: nowrap; }
  table.hasil td.flag  { text-align: center; font-weight: bold; width: 12mm; }
  .abn { color: #000; }
  .krit { background: #000; color: #fff; padding: 0 1.5mm; }

  .catatan { font-size: 9pt; margin-top: 4mm; }
  .ttd { margin-top: 10mm; display: flex; justify-content: flex-end; }
  .ttd .kotak { width: 62mm; text-align: center; font-size: 10pt; }
  .ttd .ruang { height: 18mm; }
  .ttd .nama { border-top: 1px solid #000; padding-top: 1mm; font-weight: bold; }

  .kaki { margin-top: 6mm; border-top: 1px solid #999; padding-top: 2mm;
          font-size: 8pt; color: #444; display: flex; justify-content: space-between; }

  .draft-cap {
    position: fixed; top: 42%; left: 50%; transform: translate(-50%,-50%) rotate(-28deg);
    font-size: 62pt; color: rgba(200,0,0,.12); font-weight: bold; letter-spacing: 8px;
    pointer-events: none; z-index: 0;
  }

  @media print { .bar { display: none; } body { padding: 0; } @page { margin: 14mm; size: A4; } }
</style>
</head>
<body>

<div class="bar">
  <button onclick="window.print()">Cetak</button>
  <button onclick="window.close()">Tutup</button>
  <?php if ($draft): ?><span style="color:#b3261e;margin-left:10px">Mode draft — memuat hasil yang belum terverifikasi.</span><?php endif; ?>
</div>

<?php if ($draft): ?><div class="draft-cap">DRAFT</div><?php endif; ?>

<div class="kop">
  <?php if ($identitas['logo'] !== ''): ?>
    <img class="logo" src="<?= $e($identitas['logo']) ?>" alt="">
  <?php endif; ?>
  <div class="teks">
    <h1><?= $e($identitas['faskes']) ?></h1>
    <h2><?= $e($identitas['lab']) ?></h2>
    <div class="alamat">
      <?= $e($identitas['alamat']) ?>
      <?= $identitas['telepon'] !== '' ? ' &middot; Telp. ' . $e($identitas['telepon']) : '' ?>
    </div>
  </div>
  <div class="barcode"><?= $barcodeSvg ?></div>
</div>

<div class="judul">HASIL PEMERIKSAAN LABORATORIUM</div>

<table class="identitas">
  <tr>
    <td class="k">Nama</td><td class="p">:</td><td><b><?= $e($order['nama_pasien']) ?></b></td>
    <td class="k">No. Lab</td><td class="p">:</td><td><b><?= $e($order['no_lab'] ?? $order['no_order']) ?></b></td>
  </tr>
  <tr>
    <td class="k">No. RM</td><td class="p">:</td><td><?= $e($order['no_rm']) ?></td>
    <td class="k">Tgl Order</td><td class="p">:</td><td><?= $e(Helper::tanggal($order['tgl_order'], true)) ?></td>
  </tr>
  <tr>
    <td class="k">Tgl Lahir / Umur</td><td class="p">:</td>
    <td><?= $e(Helper::tanggal($order['tgl_lahir'])) ?> (<?= $e(Helper::umurTeks($order['tgl_lahir'])) ?>)</td>
    <td class="k">Tgl Selesai</td><td class="p">:</td>
    <td><?= $e(Helper::tanggal($order['tgl_selesai'] ?? date('Y-m-d H:i:s'), true)) ?></td>
  </tr>
  <tr>
    <td class="k">Jenis Kelamin</td><td class="p">:</td><td><?= $e(Helper::jenisKelamin($order['jk'])) ?></td>
    <td class="k">Ruang / Asal</td><td class="p">:</td>
    <td><?= $e($order['nama_ruang'] ?? ucfirst((string) $order['asal'])) ?></td>
  </tr>
  <tr>
    <td class="k">Dokter Perujuk</td><td class="p">:</td><td><?= $e($order['dokter_perujuk'] ?? '-') ?></td>
    <td class="k">Cara Bayar</td><td class="p">:</td><td><?= $e($order['nama_carabayar'] ?? '-') ?></td>
  </tr>
  <?php if (($order['diagnosa_klinis'] ?? '') !== ''): ?>
  <tr>
    <td class="k">Diagnosa Klinis</td><td class="p">:</td><td colspan="4"><?= $e($order['diagnosa_klinis']) ?></td>
  </tr>
  <?php endif; ?>
</table>

<table class="hasil">
  <thead>
  <tr>
    <th style="width:38%">Pemeriksaan</th>
    <th style="width:16%;text-align:right">Hasil</th>
    <th style="width:6%;text-align:center">Flag</th>
    <th style="width:12%">Satuan</th>
    <th style="width:28%">Nilai Rujukan</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($perKategori as $namaKategori => $daftar): ?>
    <tr class="kategori"><td colspan="5"><?= $e(strtoupper($namaKategori)) ?></td></tr>
    <?php foreach ($daftar as $i): ?>
      <?php
      $flag  = (string) ($i['flag'] ?? '');
      $kelas = $flag === 'LL' || $flag === 'HH' ? 'krit' : (($flag === 'L' || $flag === 'H' || $flag === 'A') ? 'abn' : '');
      ?>
      <tr>
        <td>
          <?= $e($i['nama_singkat'] ?? $i['nama_test']) ?>
          <?php if (($i['metode'] ?? '') !== ''): ?>
            <div style="font-size:8pt;color:#555"><?= $e($i['metode']) ?></div>
          <?php endif; ?>
        </td>
        <td class="nilai"><span class="<?= $kelas ?>"><?= $e($i['nilai']) ?></span></td>
        <td class="flag"><?= $e(Helper::labelFlag($flag)) ?></td>
        <td><?= $e($i['satuan'] ?? $i['satuan_master'] ?? '') ?></td>
        <td><?= $e($i['ref_teks'] ?? '') ?></td>
      </tr>
      <?php if (($i['catatan'] ?? '') !== ''): ?>
        <tr><td colspan="5" style="font-size:8.5pt;padding-left:6mm;color:#333">Catatan: <?= $e($i['catatan']) ?></td></tr>
      <?php endif; ?>
      <?php if ((string) $i['status_hasil'] === 'corrected'): ?>
        <tr><td colspan="5" style="font-size:8.5pt;padding-left:6mm;color:#b3261e">
          *Hasil ini merupakan koreksi dan menggantikan hasil yang diterbitkan sebelumnya.
        </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="catatan">
  Keterangan flag: <b>L</b> = di bawah nilai rujukan, <b>H</b> = di atas nilai rujukan,
  <b>L!</b>/<b>H!</b> = nilai kritis (telah dilaporkan kepada dokter penanggung jawab),
  <b>A</b> = abnormal.
  <?php if (($order['catatan'] ?? '') !== ''): ?>
    <br>Catatan order: <?= $e($order['catatan']) ?>
  <?php endif; ?>
</div>

<div class="ttd">
  <div class="kotak">
    <?= $e(Helper::tanggal(date('Y-m-d'))) ?><br>
    Dokter Penanggung Jawab Laboratorium
    <div class="ruang"></div>
    <div class="nama">
      <?= $e(($verifikator['nama'] ?? '.....................................')) ?>
      <?= ($verifikator['gelar'] ?? '') !== '' ? ', ' . $e($verifikator['gelar']) : '' ?>
    </div>
    <?php if (($verifikator['nip'] ?? '') !== ''): ?>
      <div style="font-size:9pt">NIP. <?= $e($verifikator['nip']) ?></div>
    <?php endif; ?>
  </div>
</div>

<div class="kaki">
  <span>
    Dicetak <?= $e(Helper::tanggal(date('Y-m-d H:i:s'), true)) ?>
    <?php if ($tat !== null): ?> &middot; TAT <?= $e(Helper::durasi($tat)) ?><?php endif; ?>
  </span>
  <span>Hasil hanya berlaku untuk spesimen yang diperiksa. Konsultasikan hasil dengan dokter Anda.</span>
</div>

</body>
</html>
