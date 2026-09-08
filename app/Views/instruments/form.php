<?php
/** @var array<string,mixed>|null $alat @var array<int,array<string,mixed>> $kategori */
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Helper;
use App\Core\Url;

$e     = static fn ($v) => Helper::e($v);
$nilai = static fn (string $k, mixed $d = '') => Helper::e(Flash::old($k, $alat[$k] ?? $d));
$pil   = static fn (string $k, string $v, mixed $d = '') => ((string) Flash::old($k, $alat[$k] ?? $d) === $v ? 'selected' : '');
$cek   = static fn (string $k, mixed $d = 0) => ((int) Flash::old($k, $alat[$k] ?? $d) === 1 ? 'checked' : '');
$aksi  = $alat === null ? Url::to('/alat') : Url::to('/alat/' . $alat['id']);
?>
<form method="post" action="<?= $e($aksi) ?>">
    <?= Csrf::field() ?>

    <div class="grid k2">
        <div class="kartu">
            <div class="kepala"><h2>Identitas Alat</h2></div>
            <div class="isi">
                <div class="grid k2">
                    <div class="form-baris">
                        <label for="kode">Kode Alat <span style="color:var(--merah)">*</span></label>
                        <input type="text" id="kode" name="kode" required value="<?= $nilai('kode') ?>" placeholder="HEMA-01">
                        <div class="bantuan">Nilai ini dikirim middleware sebagai <code>instrument_code</code>.</div>
                    </div>
                    <div class="form-baris">
                        <label for="category_id">Kategori Pemeriksaan</label>
                        <select id="category_id" name="category_id">
                            <option value="">—</option>
                            <?php foreach ($kategori as $k): ?>
                                <option value="<?= (int) $k['id'] ?>" <?= $pil('category_id', (string) $k['id']) ?>><?= $e($k['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-baris">
                    <label for="nama">Nama Alat <span style="color:var(--merah)">*</span></label>
                    <input type="text" id="nama" name="nama" required value="<?= $nilai('nama') ?>">
                </div>
                <div class="grid k3">
                    <div class="form-baris"><label for="merk">Merk</label><input type="text" id="merk" name="merk" value="<?= $nilai('merk') ?>"></div>
                    <div class="form-baris"><label for="model">Model</label><input type="text" id="model" name="model" value="<?= $nilai('model') ?>"></div>
                    <div class="form-baris"><label for="serial_number">No. Seri</label><input type="text" id="serial_number" name="serial_number" value="<?= $nilai('serial_number') ?>"></div>
                </div>
                <label class="cek"><input type="checkbox" name="aktif" value="1" <?= $alat === null ? 'checked' : $cek('aktif') ?>> Alat aktif (dibuka oleh middleware)</label>
            </div>
        </div>

        <div class="kartu">
            <div class="kepala"><h2>Protokol &amp; Koneksi</h2></div>
            <div class="isi">
                <div class="grid k3">
                    <div class="form-baris">
                        <label for="protokol">Protokol</label>
                        <select id="protokol" name="protokol">
                            <option value="astm" <?= $pil('protokol', 'astm', 'astm') ?>>ASTM E1381/E1394</option>
                            <option value="hl7"  <?= $pil('protokol', 'hl7') ?>>HL7 v2.x (MLLP)</option>
                            <option value="raw"  <?= $pil('protokol', 'raw') ?>>Raw / baris teks</option>
                        </select>
                    </div>
                    <div class="form-baris">
                        <label for="transport">Transport</label>
                        <select id="transport" name="transport" onchange="aturTransport()">
                            <option value="tcp_server" <?= $pil('transport', 'tcp_server', 'tcp_server') ?>>TCP Server (LIS mendengarkan)</option>
                            <option value="tcp_client" <?= $pil('transport', 'tcp_client') ?>>TCP Client (LIS menyambung)</option>
                            <option value="serial"     <?= $pil('transport', 'serial') ?>>Serial RS232</option>
                        </select>
                    </div>
                    <div class="form-baris">
                        <label for="mode">Mode</label>
                        <select id="mode" name="mode">
                            <option value="unidirectional" <?= $pil('mode', 'unidirectional', 'unidirectional') ?>>Satu arah (hasil saja)</option>
                            <option value="bidirectional"  <?= $pil('mode', 'bidirectional') ?>>Dua arah (worklist + hasil)</option>
                        </select>
                    </div>
                </div>

                <div id="blok-tcp">
                    <div class="grid k2">
                        <div class="form-baris">
                            <label for="host">Host / Bind Address</label>
                            <input type="text" id="host" name="host" value="<?= $nilai('host', '0.0.0.0') ?>">
                            <div class="bantuan">TCP Server: 0.0.0.0. TCP Client: alamat IP alat.</div>
                        </div>
                        <div class="form-baris">
                            <label for="port">Port</label>
                            <input type="number" id="port" name="port" value="<?= $nilai('port') ?>" placeholder="5100">
                        </div>
                    </div>
                </div>

                <div id="blok-serial" style="display:none">
                    <div class="form-baris">
                        <label for="serial_port">Port Serial</label>
                        <input type="text" id="serial_port" name="serial_port" value="<?= $nilai('serial_port') ?>"
                               placeholder="COM3 (Windows) / /dev/tty.usbserial-1410 (macOS)">
                    </div>
                    <div class="grid k4">
                        <div class="form-baris">
                            <label for="baud_rate">Baud</label>
                            <select id="baud_rate" name="baud_rate">
                                <?php foreach ([1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200] as $b): ?>
                                    <option value="<?= $b ?>" <?= $pil('baud_rate', (string) $b, '9600') ?>><?= $b ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-baris">
                            <label for="data_bits">Data bits</label>
                            <select id="data_bits" name="data_bits">
                                <option value="8" <?= $pil('data_bits', '8', '8') ?>>8</option>
                                <option value="7" <?= $pil('data_bits', '7') ?>>7</option>
                            </select>
                        </div>
                        <div class="form-baris">
                            <label for="parity">Parity</label>
                            <select id="parity" name="parity">
                                <?php foreach (['none', 'even', 'odd', 'mark', 'space'] as $p): ?>
                                    <option value="<?= $p ?>" <?= $pil('parity', $p, 'none') ?>><?= ucfirst($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-baris">
                            <label for="stop_bits">Stop bits</label>
                            <select id="stop_bits" name="stop_bits">
                                <option value="1" <?= $pil('stop_bits', '1', '1') ?>>1</option>
                                <option value="2" <?= $pil('stop_bits', '2') ?>>2</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-baris">
                        <label for="flow_control">Flow control</label>
                        <select id="flow_control" name="flow_control">
                            <option value="none"    <?= $pil('flow_control', 'none', 'none') ?>>Tidak ada</option>
                            <option value="rtscts"  <?= $pil('flow_control', 'rtscts') ?>>RTS/CTS (hardware)</option>
                            <option value="xonxoff" <?= $pil('flow_control', 'xonxoff') ?>>XON/XOFF (software)</option>
                        </select>
                    </div>
                </div>

                <div class="grid k2">
                    <div class="form-baris">
                        <label for="encoding">Encoding</label>
                        <select id="encoding" name="encoding">
                            <option value="latin1" <?= $pil('encoding', 'latin1', 'latin1') ?>>latin1 (umum untuk ASTM)</option>
                            <option value="utf8"   <?= $pil('encoding', 'utf8') ?>>utf8</option>
                            <option value="ascii"  <?= $pil('encoding', 'ascii') ?>>ascii</option>
                        </select>
                    </div>
                    <div class="form-baris">
                        <label for="auto_verify_max_flag">Batas autovalidasi</label>
                        <select id="auto_verify_max_flag" name="auto_verify_max_flag">
                            <option value="N" <?= $pil('auto_verify_max_flag', 'N', 'N') ?>>Hanya hasil normal</option>
                            <option value="H" <?= $pil('auto_verify_max_flag', 'H') ?>>Termasuk L/H (bukan kritis)</option>
                        </select>
                    </div>
                </div>

                <label class="cek"><input type="checkbox" name="auto_verify" value="1" <?= $cek('auto_verify') ?>> Autovalidasi hasil dari alat ini</label>
                <label class="cek mt8"><input type="checkbox" name="simpan_raw" value="1" <?= $alat === null ? 'checked' : $cek('simpan_raw', 1) ?>> Simpan pesan mentah (untuk penelusuran)</label>

                <div class="notif info mt16 kecil">
                    Autovalidasi tetap ditahan untuk nilai kritis dan hasil yang ditandai delta check,
                    serta hanya berjalan bila diaktifkan pula pada Pengaturan Sistem.
                </div>
            </div>
        </div>
    </div>

    <div class="kartu">
        <div class="isi">
            <div class="aksi-baris">
                <button class="tombol utama" type="submit">Simpan Alat</button>
                <a class="tombol" href="<?= $e(Url::to('/alat')) ?>">Batal</a>
                <span class="kecil redup">Setelah menyimpan, jalankan ulang middleware agar koneksi diterapkan.</span>
            </div>
        </div>
    </div>
</form>

<script>
function aturTransport() {
  var t = document.getElementById('transport').value;
  document.getElementById('blok-tcp').style.display    = (t === 'serial') ? 'none' : '';
  document.getElementById('blok-serial').style.display = (t === 'serial') ? '' : 'none';
}
aturTransport();
</script>
