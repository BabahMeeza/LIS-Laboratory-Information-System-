/* =====================================================================
   LIS — skrip antarmuka.
   Ditulis sebagai JavaScript polos tanpa dependensi.
   ===================================================================== */
(function () {
  'use strict';

  var LIS = {};
  window.LIS = LIS;

  // ---------------------------------------------------------------- Util

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  LIS.csrf = function () {
    var el = $('input[name="_token"]');
    return el ? el.value : '';
  };

  LIS.pesan = function (tipe, teks, wadahSel) {
    var wadah = $(wadahSel || '#pesan-dinamis');
    if (!wadah) { return; }
    var div = document.createElement('div');
    div.className = 'notif ' + tipe;
    div.textContent = teks;
    wadah.insertBefore(div, wadah.firstChild);
    setTimeout(function () { div.remove(); }, 8000);
  };

  // ------------------------------------------------- Konfirmasi tindakan

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var tanya = form.getAttribute('data-konfirmasi');
    if (tanya && !window.confirm(tanya)) {
      ev.preventDefault();
    }
  });

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-konfirmasi-tautan]');
    if (el && !window.confirm(el.getAttribute('data-konfirmasi-tautan'))) {
      ev.preventDefault();
    }
  });

  // ------------------------------------------------- Pencarian dalam tabel

  $$('[data-saring]').forEach(function (input) {
    var targetSel = input.getAttribute('data-saring');
    input.addEventListener('input', function () {
      var kata = input.value.toLowerCase().trim();
      $$(targetSel + ' tbody tr').forEach(function (tr) {
        tr.style.display = (kata === '' || tr.textContent.toLowerCase().indexOf(kata) !== -1) ? '' : 'none';
      });
    });
  });

  // ------------------------------------------------- Centang semua

  $$('[data-centang-semua]').forEach(function (master) {
    var targetSel = master.getAttribute('data-centang-semua');
    master.addEventListener('change', function () {
      $$(targetSel).forEach(function (cb) {
        if (!cb.disabled) { cb.checked = master.checked; }
      });
    });
  });

  // ------------------------------------------------- Pencarian pasien

  var cariPasien = $('#cari-pasien');
  if (cariPasien) {
    var hasilBox = $('#hasil-pasien');
    var timer = null;

    cariPasien.addEventListener('input', function () {
      clearTimeout(timer);
      var kata = cariPasien.value.trim();
      if (kata.length < 2) { hasilBox.innerHTML = ''; return; }

      timer = setTimeout(function () {
        fetch(cariPasien.getAttribute('data-url') + '?q=' + encodeURIComponent(kata), {
          headers: { 'Accept': 'application/json' }
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.sukses || !j.data.length) {
              hasilBox.innerHTML = '<div class="kosong kecil">Pasien tidak ditemukan. '
                + 'Gunakan tombol "Pasien Baru" untuk mendaftarkan.</div>';
              return;
            }
            var html = '<table class="tabel rapat"><tbody>';
            j.data.forEach(function (p) {
              html += '<tr style="cursor:pointer" data-pilih-pasien="' + p.id + '"'
                + ' data-nama="' + escapeAttr(p.nama) + '"'
                + ' data-rm="' + escapeAttr(p.no_rm) + '"'
                + ' data-jk="' + escapeAttr(p.jk) + '"'
                + ' data-lahir="' + escapeAttr(p.tgl_lahir || '') + '">'
                + '<td class="mono">' + escapeHtml(p.no_rm) + '</td>'
                + '<td><b>' + escapeHtml(p.nama) + '</b></td>'
                + '<td>' + escapeHtml(p.jk === 'L' ? 'Laki-laki' : (p.jk === 'P' ? 'Perempuan' : '-')) + '</td>'
                + '<td class="kecil redup">' + escapeHtml(p.tgl_lahir || '-') + '</td>'
                + '</tr>';
            });
            hasilBox.innerHTML = html + '</tbody></table>';
          })
          .catch(function () {
            hasilBox.innerHTML = '<div class="notif error">Gagal mencari pasien.</div>';
          });
      }, 280);
    });

    document.addEventListener('click', function (ev) {
      var tr = ev.target.closest('[data-pilih-pasien]');
      if (!tr) { return; }
      $('#patient_id').value = tr.getAttribute('data-pilih-pasien');
      $('#pasien-terpilih').innerHTML =
        '<b>' + escapeHtml(tr.getAttribute('data-nama')) + '</b> &middot; RM '
        + escapeHtml(tr.getAttribute('data-rm')) + ' &middot; '
        + escapeHtml(tr.getAttribute('data-jk') === 'L' ? 'Laki-laki' : 'Perempuan')
        + ' &middot; ' + escapeHtml(tr.getAttribute('data-lahir') || '-');
      $('#pasien-terpilih').style.display = '';
      $('#hasil-pasien').innerHTML = '';
      cariPasien.value = '';
    });
  }

  // ------------------------------------------------- Hitung total order

  function hitungTotalOrder() {
    var total = 0, jumlah = 0;
    $$('input[name="tests[]"]:checked, input[name="panels[]"]:checked').forEach(function (cb) {
      total += parseFloat(cb.getAttribute('data-harga') || '0');
      jumlah += 1;
    });
    var elTotal = $('#total-order');
    var elJml = $('#jumlah-order');
    if (elTotal) { elTotal.textContent = 'Rp ' + total.toLocaleString('id-ID'); }
    if (elJml) { elJml.textContent = jumlah; }
  }

  if ($('#total-order')) {
    document.addEventListener('change', function (ev) {
      if (ev.target.name === 'tests[]' || ev.target.name === 'panels[]') { hitungTotalOrder(); }
    });
    hitungTotalOrder();
  }

  // ------------------------------------------------- Pemindaian barcode

  var inputBarcode = $('#input-barcode');
  if (inputBarcode) {
    inputBarcode.focus();

    inputBarcode.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') { return; }
      ev.preventDefault();

      var kode = inputBarcode.value.trim();
      if (!kode) { return; }
      inputBarcode.value = '';
      inputBarcode.disabled = true;

      var body = new URLSearchParams();
      body.append('barcode', kode);
      body.append('_token', LIS.csrf());
      body.append('kondisi', ($('#kondisi-sampel') || { value: 'baik' }).value);

      fetch(inputBarcode.getAttribute('data-url'), {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          inputBarcode.disabled = false;
          inputBarcode.focus();

          if (!res.j.sukses) {
            LIS.pesan('error', res.j.pesan || 'Gagal menerima spesimen.');
            bunyi(false);
            return;
          }

          var d = res.j.data;
          var baris = document.createElement('tr');
          baris.innerHTML =
            '<td class="mono">' + escapeHtml(d.barcode) + '</td>'
            + '<td class="mono">' + escapeHtml(d.no_lab || '-') + '</td>'
            + '<td><b>' + escapeHtml(d.nama_pasien) + '</b><div class="kecil redup">RM ' + escapeHtml(d.no_rm) + '</div></td>'
            + '<td>' + escapeHtml(d.jenis || '-') + '</td>'
            + '<td>' + (d.prioritas === 'cito' ? '<span class="badge bahaya">CITO</span>' : '<span class="badge netral">Rutin</span>') + '</td>'
            + '<td class="kecil">' + escapeHtml((d.pemeriksaan || []).map(function (t) { return t.nama; }).join(', ')) + '</td>'
            + '<td class="kecil redup">' + escapeHtml(d.diterima_at) + '</td>';
          baris.style.background = '#e6f4ec';

          var tbody = $('#tabel-terima tbody');
          if (tbody) {
            var kosong = $('#tabel-terima tbody tr.baris-kosong');
            if (kosong) { kosong.remove(); }
            tbody.insertBefore(baris, tbody.firstChild);
          }

          LIS.pesan('sukses', 'Diterima: ' + d.nama_pasien + ' (' + d.barcode + ')');
          bunyi(true);
        })
        .catch(function () {
          inputBarcode.disabled = false;
          inputBarcode.focus();
          LIS.pesan('error', 'Gagal menghubungi server.');
        });
    });
  }

  // Nada singkat sebagai umpan balik pemindaian (tanpa berkas audio).
  function bunyi(berhasil) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) { return; }
      var ctx = new Ctx();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.frequency.value = berhasil ? 880 : 220;
      gain.gain.value = 0.05;
      osc.start();
      setTimeout(function () { osc.stop(); ctx.close(); }, berhasil ? 110 : 300);
    } catch (e) { /* diamkan */ }
  }

  // ------------------------------------------------- Penyegaran dashboard

  var dash = $('#dashboard-auto');
  if (dash) {
    setInterval(function () {
      fetch(dash.getAttribute('data-url'), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.sukses) { return; }
          Object.keys(j.data).forEach(function (k) {
            var el = document.querySelector('[data-stat="' + k + '"]');
            if (el && String(el.textContent).trim() !== String(j.data[k])) {
              el.textContent = j.data[k];
              el.style.transition = 'background .4s';
              el.style.background = '#fff3c4';
              setTimeout(function () { el.style.background = ''; }, 900);
            }
          });
        })
        .catch(function () { /* diamkan */ });
    }, 30000);
  }

  // ------------------------------------------------- Navigasi entri hasil

  $$('[data-form-hasil] input, [data-form-hasil] select').forEach(function (el, idx, semua) {
    el.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        var berikut = semua[idx + 1];
        if (berikut) { berikut.focus(); if (berikut.select) { berikut.select(); } }
      }
    });
  });

  // Tandai visual nilai di luar rujukan saat mengetik.
  $$('input[data-low]').forEach(function (input) {
    function nilai() {
      var v = parseFloat(String(input.value).replace(',', '.'));
      if (isNaN(v)) { input.style.color = ''; input.style.fontWeight = ''; return; }
      var low = parseFloat(input.getAttribute('data-low'));
      var high = parseFloat(input.getAttribute('data-high'));
      var cl = parseFloat(input.getAttribute('data-clow'));
      var ch = parseFloat(input.getAttribute('data-chigh'));
      var kritis = (!isNaN(cl) && v <= cl) || (!isNaN(ch) && v >= ch);
      var abnormal = (!isNaN(low) && v < low) || (!isNaN(high) && v > high);
      input.style.color = kritis ? '#fff' : (abnormal ? '#b3261e' : '');
      input.style.background = kritis ? '#b3261e' : '';
      input.style.fontWeight = (kritis || abnormal) ? '700' : '';
    }
    input.addEventListener('input', nilai);
    nilai();
  });

  // ------------------------------------------------- Escaping

  function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function escapeAttr(s) {
    return escapeHtml(s).replace(/"/g, '&quot;');
  }
})();
