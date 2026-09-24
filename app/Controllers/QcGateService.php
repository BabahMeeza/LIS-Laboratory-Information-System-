<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Penjaga mutu: memutuskan apakah hasil pasien boleh keluar berdasarkan
 * keadaan kontrol kualitas alat pada saat hasil itu dibuat.
 *
 * MENGAPA INI ADA
 *
 * Sebelum kelas ini, tabel qc_lots dan qc_results sudah ada — tetapi tidak
 * ada satu baris kode pun yang membacanya sebelum hasil dirilis. Artinya
 * QC hanyalah catatan; alat boleh gagal kontrol pagi hari dan hasil pasien
 * tetap keluar sepanjang siang tanpa satu pun peringatan.
 *
 * Itu adalah celah keselamatan pasien yang paling serius dalam rancangan
 * ini, dan LIS mana pun yang sudah dipakai di dunia nyata menutupnya:
 *
 *   - OpenELIS Global menolak validasi hasil bila QC batch gagal.
 *   - SENAITE menyematkan QC pada worksheet; sampel pasien dan kontrol
 *     dinilai bersama, dan worksheet gagal tidak bisa diterbitkan.
 *   - ISO 15189:2022 7.3.7.3 mensyaratkan laboratorium mencegah keluarnya
 *     hasil ketika kontrol mutu tidak memenuhi kriteria keberterimaan,
 *     sampai masalahnya diperbaiki.
 *
 * BAGAIMANA KEPUTUSAN DIAMBIL
 *
 * Untuk satu pemeriksaan pada satu alat, dicari titik QC terakhir yang
 * masih berlaku (dalam batas jam yang ditetapkan pada lot). Hasilnya:
 *
 *   lolos     — QC terakhir masuk batas, hasil boleh keluar
 *   peringatan— melanggar aturan peringatan (mis. 1-2s), boleh keluar
 *               tetapi tidak boleh divalidasi otomatis
 *   gagal     — melanggar aturan penolakan, hasil DITAHAN
 *   belum     — tidak ada QC berlaku; menahan atau tidak bergantung
 *               pengaturan mutu.qc_belum_menahan
 *   tidak_berlaku — parameter ini memang tidak punya lot QC aktif
 *
 * Keadaan "tidak_berlaku" sengaja dibedakan dari "belum". Pemeriksaan
 * makroskopis feses tidak punya bahan kontrol; menahannya karena tidak ada
 * QC akan menghentikan laboratorium tanpa menambah keamanan sedikit pun.
 */
final class QcGateService
{
    /** Aturan Westgard yang menolak run, bukan sekadar memperingatkan. */
    private const ATURAN_TOLAK = ['1-3s', '2-2s', 'R-4s', '4-1s', '10x', '2of3-2s', '3-1s', '6x', '7T', '9x'];

    /** @var array<string,array<string,mixed>> singgahan per permintaan */
    private static array $singgahan = [];

    /**
     * Menilai keadaan QC untuk satu pemeriksaan pada satu alat.
     *
     * @return array{
     *   status:string, menahan:bool, boleh_auto:bool,
     *   alasan:string, qc_result_id:?int, tgl_uji:?string,
     *   westgard:?string, umur_jam:?float
     * }
     */
    public static function nilai(int $testId, ?int $instrumentId, ?string $pada = null): array
    {
        $kunci = $testId . '|' . ($instrumentId ?? 0) . '|' . ($pada ?? '');
        if (isset(self::$singgahan[$kunci])) {
            return self::$singgahan[$kunci];
        }

        $waktu = $pada ?? date('Y-m-d H:i:s');

        // Lot QC yang aktif untuk parameter ini. Bila alat disebutkan,
        // lot khusus alat itu didahulukan; lot lintas alat (instrument_id
        // NULL) dipakai sebagai cadangan.
        $lots = Database::select(
            'SELECT id, instrument_id, level, menahan_rilis, berlaku_jam, aturan_westgard,
                    tgl_kadaluarsa
               FROM qc_lots
              WHERE test_id = ?
                AND aktif = 1
                AND (instrument_id IS NULL OR instrument_id = ?)
              ORDER BY instrument_id IS NULL ASC, level ASC',
            [$testId, $instrumentId ?? 0]
        );

        if ($lots === []) {
            return self::$singgahan[$kunci] = self::hasil(
                'tidak_berlaku', false, true,
                'Parameter ini tidak memiliki lot QC aktif.'
            );
        }

        $berlakuGlobalJam = Config::settingInt('mutu.qc_berlaku_jam', 24);
        $menahanAktif     = Config::settingBool('mutu.qc_menahan_rilis', true);
        $belumMenahan     = Config::settingBool('mutu.qc_belum_menahan', false);

        $terburuk    = null;   // baris QC yang menentukan keputusan
        $adaBerlaku  = false;
        $adaMenahan  = false;

        foreach ($lots as $lot) {
            if ((int) $lot['menahan_rilis'] === 1) {
                $adaMenahan = true;
            }

            $berlakuJam = (int) $lot['berlaku_jam'] > 0
                ? (int) $lot['berlaku_jam']
                : $berlakuGlobalJam;

            // Titik QC terakhir untuk lot ini yang tidak lebih tua dari
            // masa berlakunya, dan tidak lebih baru dari waktu hasil.
            $qc = Database::selectOne(
                'SELECT id, nilai, z_score, westgard, status, tgl_uji
                   FROM qc_results
                  WHERE qc_lot_id = ?
                    AND tgl_uji <= ?
                    AND tgl_uji >= DATE_SUB(?, INTERVAL ? HOUR)
                  ORDER BY tgl_uji DESC
                  LIMIT 1',
                [(int) $lot['id'], $waktu, $waktu, $berlakuJam]
            );

            if ($qc === null) {
                continue;
            }

            $adaBerlaku = true;

            $bobot = self::bobot((string) $qc['status'], (string) ($qc['westgard'] ?? ''));
            if ($terburuk === null || $bobot > $terburuk['bobot']) {
                $terburuk = [
                    'bobot'   => $bobot,
                    'qc'      => $qc,
                    'lot'     => $lot,
                ];
            }
        }

        // Tidak ada satu pun titik QC yang masih berlaku.
        if (!$adaBerlaku) {
            $menahan = $menahanAktif && $belumMenahan && $adaMenahan;
            return self::$singgahan[$kunci] = self::hasil(
                'belum', $menahan, false,
                $menahan
                    ? 'QC belum dijalankan atau sudah kedaluwarsa untuk parameter ini.'
                    : 'QC belum dijalankan; hasil boleh keluar tetapi tidak divalidasi otomatis.'
            );
        }

        $qc     = $terburuk['qc'];
        $lot    = $terburuk['lot'];
        $bobot  = $terburuk['bobot'];
        $umur   = (strtotime($waktu) - strtotime((string) $qc['tgl_uji'])) / 3600.0;

        if ($bobot >= 2) {
            $menahan = $menahanAktif && (int) $lot['menahan_rilis'] === 1;
            return self::$singgahan[$kunci] = self::hasil(
                'gagal', $menahan, false,
                sprintf(
                    'QC %s pada %s melanggar aturan %s.',
                    'level ' . (string) $lot['level'],
                    (string) $qc['tgl_uji'],
                    (string) ($qc['westgard'] ?? 'penolakan')
                ),
                (int) $qc['id'], (string) $qc['tgl_uji'],
                (string) ($qc['westgard'] ?? ''), $umur
            );
        }

        if ($bobot === 1) {
            return self::$singgahan[$kunci] = self::hasil(
                'peringatan', false, false,
                sprintf(
                    'QC level %s pada %s berstatus peringatan (%s) — perlu ditinjau verifikator.',
                    (string) $lot['level'], (string) $qc['tgl_uji'],
                    (string) ($qc['westgard'] ?? '1-2s')
                ),
                (int) $qc['id'], (string) $qc['tgl_uji'],
                (string) ($qc['westgard'] ?? ''), $umur
            );
        }

        return self::$singgahan[$kunci] = self::hasil(
            'lolos', false, true,
            sprintf('QC terakhir %s masuk batas.', (string) $qc['tgl_uji']),
            (int) $qc['id'], (string) $qc['tgl_uji'], null, $umur
        );
    }

    /**
     * Menilai seluruh hasil pada satu order sebelum dirilis.
     *
     * Dipakai VerificationController: rilis order ditahan bila ada satu
     * saja parameter yang QC-nya gagal. Melepaskannya adalah keputusan
     * sadar seorang penanggung jawab, bukan kelalaian sistem.
     *
     * @return array{boleh:bool, penahan:array<int,array<string,mixed>>}
     */
    public static function nilaiOrder(int $orderId): array
    {
        $baris = Database::select(
            'SELECT r.id, r.test_id, r.instrument_id, r.entered_at,
                    t.kode AS kode_test, t.nama AS nama_test
               FROM results r
               JOIN tests t ON t.id = r.test_id
              WHERE r.order_id = ?
                AND r.status NOT IN (\'rejected\',\'pending\')',
            [$orderId]
        );

        $penahan = [];
        foreach ($baris as $b) {
            $nilai = self::nilai(
                (int) $b['test_id'],
                $b['instrument_id'] !== null ? (int) $b['instrument_id'] : null,
                $b['entered_at'] !== null ? (string) $b['entered_at'] : null
            );

            if ($nilai['menahan']) {
                $penahan[] = [
                    'result_id'  => (int) $b['id'],
                    'kode_test'  => (string) $b['kode_test'],
                    'nama_test'  => (string) $b['nama_test'],
                    'status_qc'  => $nilai['status'],
                    'alasan'     => $nilai['alasan'],
                ];
            }
        }

        return ['boleh' => $penahan === [], 'penahan' => $penahan];
    }

    /**
     * Hasil pasien yang perlu ditinjau ulang setelah QC dinyatakan gagal.
     *
     * MENGAPA INI ADA — dan mengapa nilai() saja tidak cukup
     *
     * nilai() menjawab pertanyaan ke belakang: "bagaimana keadaan QC saat
     * hasil ini dibuat?". Itu menjaga hasil yang BELUM keluar.
     *
     * Tetapi kegagalan QC hampir selalu ditemukan SESUDAH kejadiannya.
     * Alat menyimpang pukul 09.00, tiga puluh sampel pasien dikerjakan,
     * lalu QC siang pukul 13.00 gagal. Ketiga puluh hasil itu sudah keluar
     * dengan QC pagi yang masih baik — dan tidak ada satu pun mekanisme ke
     * belakang yang akan menyentuhnya.
     *
     * ISO 15189:2022 7.3.7.3 justru mensyaratkan hal ini secara khusus:
     * laboratorium harus mengevaluasi hasil pemeriksaan pasien yang
     * dikerjakan SEJAK kejadian QC yang masih dapat diterima terakhir.
     *
     * Fungsi ini mengumpulkan rentang itu — dari titik QC baik terakhir
     * sampai QC yang gagal — agar penanggung jawab dapat memutuskan mana
     * yang perlu diulang dan mana yang perlu ditarik.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function hasilPerluDitinjau(int $qcResultGagalId): array
    {
        $gagal = Database::selectOne(
            'SELECT qr.id, qr.qc_lot_id, qr.tgl_uji, qr.westgard,
                    ql.test_id, ql.instrument_id
               FROM qc_results qr
               JOIN qc_lots    ql ON ql.id = qr.qc_lot_id
              WHERE qr.id = ? LIMIT 1',
            [$qcResultGagalId]
        );

        if ($gagal === null) {
            return [];
        }

        // Titik QC yang masih dapat diterima terakhir SEBELUM kegagalan.
        // Bila tidak ada, seluruh riwayat pada lot ini masuk rentang —
        // keadaan itu sendiri sudah menandakan masalah pencatatan.
        $baik = Database::selectOne(
            "SELECT tgl_uji FROM qc_results
              WHERE qc_lot_id = ?
                AND tgl_uji < ?
                AND status = 'in'
                AND (westgard IS NULL OR westgard = '')
              ORDER BY tgl_uji DESC LIMIT 1",
            [(int) $gagal['qc_lot_id'], (string) $gagal['tgl_uji']]
        );

        $sejak = $baik !== null
            ? (string) $baik['tgl_uji']
            : '1970-01-01 00:00:00';

        $params = [(int) $gagal['test_id'], $sejak, (string) $gagal['tgl_uji']];
        $filterAlat = '';

        // Lot QC yang terikat satu alat hanya membicarakan alat itu. Lot
        // lintas alat (instrument_id NULL) membicarakan semuanya.
        if ($gagal['instrument_id'] !== null) {
            $filterAlat = ' AND r.instrument_id = ?';
            $params[]   = (int) $gagal['instrument_id'];
        }

        return Database::select(
            'SELECT r.id AS result_id, r.nilai, r.satuan, r.flag, r.status,
                    r.entered_at, r.is_kritis,
                    o.id AS order_id, o.no_order, o.no_lab, o.status AS status_order,
                    p.no_rm, p.nama AS nama_pasien,
                    t.kode AS kode_test, t.nama AS nama_test
               FROM results  r
               JOIN orders   o ON o.id = r.order_id
               JOIN patients p ON p.id = o.patient_id
               JOIN tests    t ON t.id = r.test_id
              WHERE r.test_id = ?
                AND r.entered_at >  ?
                AND r.entered_at <= ?
                AND r.status NOT IN (\'rejected\',\'pending\')'
            . $filterAlat .
            ' ORDER BY r.entered_at ASC',
            $params
        );
    }

    /**
     * Apakah lot reagen yang dipakai masih sah pada waktu tertentu.
     *
     * Lot yang kedaluwarsa atau ditarik tidak membatalkan hasil yang
     * sudah ada — hasil itu tetap fakta yang terjadi — tetapi menghalangi
     * validasi otomatis, karena keputusan seperti itu memerlukan manusia.
     *
     * @return array{sah:bool, alasan:string}
     */
    public static function periksaLot(?int $reagentLotId, ?string $pada = null): array
    {
        if ($reagentLotId === null) {
            return ['sah' => true, 'alasan' => ''];
        }

        $lot = Database::selectOne(
            'SELECT nama, lot, status, tgl_buka, masa_buka_hari, tgl_kadaluarsa
               FROM reagent_lots WHERE id = ? LIMIT 1',
            [$reagentLotId]
        );

        if ($lot === null) {
            return ['sah' => true, 'alasan' => ''];
        }

        $waktu = $pada ?? date('Y-m-d H:i:s');
        $label = (string) $lot['nama'] . ' lot ' . (string) $lot['lot'];

        if ((string) $lot['status'] === 'ditarik') {
            return ['sah' => false, 'alasan' => $label . ' telah ditarik pabrikan.'];
        }

        if ($lot['tgl_kadaluarsa'] !== null
            && strtotime((string) $lot['tgl_kadaluarsa'] . ' 23:59:59') < strtotime($waktu)) {
            return ['sah' => false, 'alasan' => $label . ' kedaluwarsa pada ' . (string) $lot['tgl_kadaluarsa'] . '.'];
        }

        // Masa pakai setelah botol dibuka sering jauh lebih pendek
        // daripada tanggal kedaluwarsa yang tercetak.
        if ($lot['tgl_buka'] !== null && $lot['masa_buka_hari'] !== null) {
            $batas = strtotime((string) $lot['tgl_buka'] . ' +' . (int) $lot['masa_buka_hari'] . ' days');
            if ($batas !== false && $batas < strtotime($waktu)) {
                return [
                    'sah'    => false,
                    'alasan' => $label . ' melewati masa pakai '
                        . (int) $lot['masa_buka_hari'] . ' hari sejak dibuka.',
                ];
            }
        }

        return ['sah' => true, 'alasan' => ''];
    }

    /**
     * Bobot keparahan satu titik QC.
     *   0 = masuk batas, 1 = peringatan, 2 = ditolak
     */
    private static function bobot(string $status, string $westgard): int
    {
        if ($status === 'out') {
            return 2;
        }

        // Status "warning" pun dapat menyembunyikan aturan penolakan bila
        // penilaiannya dilakukan alat, bukan LIS. Karena itu aturan yang
        // tercatat tetap diperiksa.
        foreach (self::ATURAN_TOLAK as $aturan) {
            if ($westgard !== '' && stripos($westgard, $aturan) !== false) {
                return 2;
            }
        }

        return $status === 'warning' ? 1 : 0;
    }

    /**
     * @return array{status:string,menahan:bool,boleh_auto:bool,alasan:string,qc_result_id:?int,tgl_uji:?string,westgard:?string,umur_jam:?float}
     */
    private static function hasil(
        string $status,
        bool $menahan,
        bool $bolehAuto,
        string $alasan,
        ?int $qcResultId = null,
        ?string $tglUji = null,
        ?string $westgard = null,
        ?float $umurJam = null
    ): array {
        return [
            'status'       => $status,
            'menahan'      => $menahan,
            'boleh_auto'   => $bolehAuto,
            'alasan'       => $alasan,
            'qc_result_id' => $qcResultId,
            'tgl_uji'      => $tglUji,
            'westgard'     => $westgard,
            'umur_jam'     => $umurJam !== null ? round($umurJam, 2) : null,
        ];
    }

    /** Membersihkan singgahan — dipakai pengujian dan proses batch panjang. */
    public static function lupakan(): void
    {
        self::$singgahan = [];
    }
}
