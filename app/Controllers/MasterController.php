<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Response;

/**
 * Master data: pemeriksaan, nilai rujukan, dan paket.
 */
final class MasterController extends Controller
{
    // -----------------------------------------------------------------
    // Pemeriksaan
    // -----------------------------------------------------------------

    public function tests(): Response
    {
        $cari       = $this->request->str('q');
        $kategoriId = $this->request->int('kategori');

        $where = ['1=1'];
        $bind  = [];

        if ($cari !== '') {
            $where[] = '(t.kode LIKE ? OR t.nama LIKE ? OR t.loinc LIKE ?)';
            $like    = '%' . $cari . '%';
            array_push($bind, $like, $like, $like);
        }
        if ($kategoriId > 0) {
            $where[] = 't.category_id = ?';
            $bind[]  = $kategoriId;
        }

        $sqlWhere = implode(' AND ', $where);

        $tests = Database::select(
            "SELECT t.*, tc.nama AS kategori, st.nama AS spesimen,
                    (SELECT COUNT(*) FROM reference_ranges rr WHERE rr.test_id = t.id) AS jml_rujukan
             FROM tests t
             LEFT JOIN test_categories tc ON tc.id = t.category_id
             LEFT JOIN specimen_types st ON st.id = t.specimen_type_id
             WHERE $sqlWhere
             ORDER BY tc.urut, t.urut, t.nama
             LIMIT 500",
            $bind
        );

        $kategori = Database::select('SELECT * FROM test_categories ORDER BY urut');

        return $this->view('master/tests', [
            'tests'    => $tests,
            'kategori' => $kategori,
            'filter'   => compact('cari', 'kategoriId'),
        ], 'Master Pemeriksaan');
    }

    /** @param array<string,string> $params */
    public function formTest(array $params = []): Response
    {
        $test = null;
        if (isset($params['id'])) {
            $test = Database::selectOne('SELECT * FROM tests WHERE id = ?', [(int) $params['id']]);
            if ($test === null) {
                throw new HttpException(404, 'Pemeriksaan tidak ditemukan.');
            }
        }

        return $this->view('master/test_form', [
            'test'     => $test,
            'kategori' => Database::select('SELECT * FROM test_categories ORDER BY urut'),
            'spesimen' => Database::select('SELECT * FROM specimen_types ORDER BY nama'),
        ], $test === null ? 'Pemeriksaan Baru' : 'Ubah: ' . $test['nama']);
    }

    /** @param array<string,string> $params */
    public function simpanTest(array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        $data = $this->validasi([
            'kode'       => 'required|max:30',
            'nama'       => 'required|max:120',
            'tipe_hasil' => 'required|in:numerik,teks,pilihan,narasi',
            'desimal'    => 'integer|min_num:0|max_num:6',
        ], ['kode' => 'Kode pemeriksaan', 'nama' => 'Nama pemeriksaan']);

        if ($data === null) {
            return $this->redirect($id > 0 ? "/master/pemeriksaan/$id/edit" : '/master/pemeriksaan/baru');
        }

        $simpan = [
            'kode'                => $this->request->str('kode'),
            'nama'                => $this->request->str('nama'),
            'nama_singkat'        => $this->request->str('nama_singkat') ?: null,
            'category_id'         => $this->request->int('category_id') ?: null,
            'specimen_type_id'    => $this->request->int('specimen_type_id') ?: null,
            'loinc'               => $this->request->str('loinc') ?: null,
            'satuan'              => $this->request->str('satuan') ?: null,
            'metode'              => $this->request->str('metode') ?: null,
            'tipe_hasil'          => $this->request->str('tipe_hasil', 'numerik'),
            'pilihan'             => $this->request->str('pilihan') ?: null,
            'desimal'             => $this->request->int('desimal', 2),
            'harga'               => (float) ($this->request->float('harga', 0.0) ?? 0.0),
            'tat_menit'           => $this->request->int('tat_menit', 120),
            'urut'                => $this->request->int('urut', 0),
            'is_kritis'           => $this->request->bool('is_kritis') ? 1 : 0,
            'khanza_kd_jenis_prw' => $this->request->str('khanza_kd_jenis_prw') ?: null,
            'khanza_id_template'  => $this->request->int('khanza_id_template') ?: null,
            'aktif'               => $this->request->bool('aktif') ? 1 : 0,
        ];

        $bentrok = Database::selectOne('SELECT id FROM tests WHERE kode = ? AND id <> ?', [$simpan['kode'], $id]);
        if ($bentrok !== null) {
            Flash::error('Kode pemeriksaan "' . $simpan['kode'] . '" sudah dipakai.');

            return $this->redirect($id > 0 ? "/master/pemeriksaan/$id/edit" : '/master/pemeriksaan/baru');
        }

        if ($id > 0) {
            Database::update('tests', $simpan, 'id = ?', [$id]);
            Audit::log('ubah_pemeriksaan', 'test', (string) $id, $simpan['nama']);
            Flash::sukses('Pemeriksaan diperbarui.');
        } else {
            $id = Database::insert('tests', $simpan);
            Audit::log('tambah_pemeriksaan', 'test', (string) $id, $simpan['nama']);
            Flash::sukses('Pemeriksaan ditambahkan. Lengkapi nilai rujukannya.');
        }

        Flash::bersihkanInput();

        return $this->redirect('/master/pemeriksaan/' . $id . '/rujukan');
    }

    // -----------------------------------------------------------------
    // Nilai rujukan
    // -----------------------------------------------------------------

    /** @param array<string,string> $params */
    public function rujukan(array $params): Response
    {
        $id   = (int) $params['id'];
        $test = Database::selectOne('SELECT * FROM tests WHERE id = ?', [$id]);

        if ($test === null) {
            throw new HttpException(404, 'Pemeriksaan tidak ditemukan.');
        }

        $rujukan = Database::select(
            'SELECT * FROM reference_ranges WHERE test_id = ? ORDER BY jk, umur_min_hari',
            [$id]
        );

        return $this->view('master/rujukan', [
            'test'    => $test,
            'rujukan' => $rujukan,
        ], 'Nilai Rujukan — ' . $test['nama']);
    }

    /** @param array<string,string> $params */
    public function simpanRujukan(array $params): Response
    {
        $testId = (int) $params['id'];

        $test = Database::selectOne('SELECT * FROM tests WHERE id = ?', [$testId]);
        if ($test === null) {
            throw new HttpException(404, 'Pemeriksaan tidak ditemukan.');
        }

        $umurMin = $this->request->int('umur_min_tahun', 0);
        $umurMax = $this->request->int('umur_max_tahun', 120);

        if ($umurMin > $umurMax) {
            Flash::error('Umur minimum tidak boleh lebih besar dari umur maksimum.');

            return $this->redirect('/master/pemeriksaan/' . $testId . '/rujukan');
        }

        $low  = $this->request->float('low');
        $high = $this->request->float('high');

        if ($low !== null && $high !== null && $low > $high) {
            Flash::error('Batas bawah tidak boleh lebih besar dari batas atas.');

            return $this->redirect('/master/pemeriksaan/' . $testId . '/rujukan');
        }

        Database::insert('reference_ranges', [
            'test_id'           => $testId,
            'jk'                => $this->request->str('jk', 'A'),
            'umur_min_hari'     => $umurMin * 365,
            'umur_max_hari'     => $umurMax * 365,
            'low'               => $low,
            'high'              => $high,
            'critical_low'      => $this->request->float('critical_low'),
            'critical_high'     => $this->request->float('critical_high'),
            'teks_rujukan'      => $this->request->str('teks_rujukan') ?: null,
            'nilai_normal_teks' => $this->request->str('nilai_normal_teks') ?: null,
            'catatan'           => $this->request->str('catatan') ?: null,
        ]);

        Audit::log('tambah_rujukan', 'test', (string) $testId, $test['nama']);
        Flash::sukses('Nilai rujukan ditambahkan.');

        return $this->redirect('/master/pemeriksaan/' . $testId . '/rujukan');
    }

    /** @param array<string,string> $params */
    public function hapusRujukan(array $params): Response
    {
        $id  = (int) $params['id'];
        $row = Database::selectOne('SELECT * FROM reference_ranges WHERE id = ?', [$id]);

        if ($row === null) {
            throw new HttpException(404, 'Nilai rujukan tidak ditemukan.');
        }

        Database::execute('DELETE FROM reference_ranges WHERE id = ?', [$id]);
        Audit::log('hapus_rujukan', 'reference_range', (string) $id, 'test_id ' . $row['test_id']);
        Flash::sukses('Nilai rujukan dihapus.');

        return $this->redirect('/master/pemeriksaan/' . $row['test_id'] . '/rujukan');
    }

    // -----------------------------------------------------------------
    // Paket pemeriksaan
    // -----------------------------------------------------------------

    public function panels(): Response
    {
        $panels = Database::select(
            'SELECT p.*, tc.nama AS kategori,
                    (SELECT COUNT(*) FROM test_panel_items i WHERE i.panel_id = p.id) AS jml_item,
                    (SELECT COALESCE(SUM(t.harga),0) FROM test_panel_items i JOIN tests t ON t.id = i.test_id WHERE i.panel_id = p.id) AS harga_item
             FROM test_panels p
             LEFT JOIN test_categories tc ON tc.id = p.category_id
             ORDER BY p.nama'
        );

        return $this->view('master/panels', ['panels' => $panels], 'Paket Pemeriksaan');
    }

    /** @param array<string,string> $params */
    public function formPanel(array $params = []): Response
    {
        $panel = null;
        $items = [];

        if (isset($params['id'])) {
            $panel = Database::selectOne('SELECT * FROM test_panels WHERE id = ?', [(int) $params['id']]);
            if ($panel === null) {
                throw new HttpException(404, 'Paket tidak ditemukan.');
            }
            $items = array_column(
                Database::select('SELECT test_id FROM test_panel_items WHERE panel_id = ?', [(int) $params['id']]),
                'test_id'
            );
        }

        $tests = Database::select(
            'SELECT t.id, t.kode, t.nama, t.harga, tc.nama AS kategori
             FROM tests t LEFT JOIN test_categories tc ON tc.id = t.category_id
             WHERE t.aktif = 1 ORDER BY tc.urut, t.urut, t.nama'
        );

        return $this->view('master/panel_form', [
            'panel'    => $panel,
            'items'    => array_map('intval', $items),
            'tests'    => $tests,
            'kategori' => Database::select('SELECT * FROM test_categories ORDER BY urut'),
        ], $panel === null ? 'Paket Baru' : 'Ubah Paket: ' . $panel['nama']);
    }

    /** @param array<string,string> $params */
    public function simpanPanel(array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        $data = $this->validasi([
            'kode' => 'required|max:30',
            'nama' => 'required|max:120',
        ], ['kode' => 'Kode paket', 'nama' => 'Nama paket']);

        if ($data === null) {
            return $this->redirect($id > 0 ? "/master/paket/$id/edit" : '/master/paket/baru');
        }

        $testIds = array_map('intval', $this->request->arr('tests'));
        if ($testIds === []) {
            Flash::error('Paket harus memuat minimal satu pemeriksaan.');

            return $this->redirect($id > 0 ? "/master/paket/$id/edit" : '/master/paket/baru');
        }

        $simpan = [
            'kode'                => $this->request->str('kode'),
            'nama'                => $this->request->str('nama'),
            'category_id'         => $this->request->int('category_id') ?: null,
            'harga'               => (float) ($this->request->float('harga', 0.0) ?? 0.0),
            'khanza_kd_jenis_prw' => $this->request->str('khanza_kd_jenis_prw') ?: null,
            'aktif'               => $this->request->bool('aktif') ? 1 : 0,
        ];

        Database::transaction(static function () use (&$id, $simpan, $testIds): void {
            if ($id > 0) {
                Database::update('test_panels', $simpan, 'id = ?', [$id]);
            } else {
                $id = Database::insert('test_panels', $simpan);
            }

            Database::execute('DELETE FROM test_panel_items WHERE panel_id = ?', [$id]);
            foreach (array_values(array_unique($testIds)) as $urut => $testId) {
                Database::insert('test_panel_items', [
                    'panel_id' => $id,
                    'test_id'  => $testId,
                    'urut'     => $urut + 1,
                ]);
            }
        });

        Audit::log('simpan_paket', 'test_panel', (string) $id, $simpan['nama']);
        Flash::sukses('Paket pemeriksaan disimpan (' . count($testIds) . ' item).');
        Flash::bersihkanInput();

        return $this->redirect('/master/paket');
    }
}
