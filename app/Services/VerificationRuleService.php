<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Mesin aturan autovalidasi — CLSI AUTO10 dan AUTO15.
 *
 * MENGAPA ATURAN DIPINDAHKAN DARI KODE MENJADI DATA
 *
 * Sebelumnya keputusan autovalidasi tertanam dalam ResultProcessor:
 * "bukan kritis, delta tidak bertanda, flag dalam batas". Aturan itu
 * sendiri masuk akal, tetapi bentuknya bermasalah untuk laboratorium
 * yang diakreditasi:
 *
 *   - Tidak dapat ditunjukkan kepada asesor tanpa membaca kode PHP.
 *   - Tidak berversi. Bila aturan diubah bulan lalu, tidak ada cara
 *     mengetahui aturan mana yang berlaku atas hasil pasien bulan lalu.
 *   - Tidak melalui persetujuan. AUTO15 mensyaratkan aturan divalidasi
 *     dan disetujui SEBELUM dipakai pada hasil pasien.
 *   - Berlaku sama untuk semua parameter. Padahal autovalidasi yang
 *     pantas untuk hemoglobin belum tentu pantas untuk troponin.
 *
 * Kini aturan disimpan di verification_rule_sets / verification_rules,
 * dan setiap keputusan dicatat di verification_decisions lengkap dengan
 * syarat yang diperiksa. Pertanyaan "mengapa hasil ini lolos tanpa mata
 * manusia?" akhirnya punya jawaban yang dapat dicetak.
 *
 * SIFAT KONSERVATIF
 *
 * Bila tidak ada himpunan aturan yang aktif, mesin ini menahan semua
 * hasil. Ketiadaan aturan tidak boleh diartikan sebagai izin.
 */
final class VerificationRuleService
{
    /** @var array<int,array<string,mixed>|null> */
    private static array $singgahanSet = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    private static array $singgahanAturan = [];

    /**
     * Memutuskan apakah satu hasil boleh divalidasi otomatis.
     *
     * @param array{
     *   test_id:int, instrument_id:?int, flag:string, flag_alat:?string,
     *   nilai_num:?float, delta_status:string, delta_persen:?float,
     *   is_kritis:bool, satuan_bentrok:bool,
     *   qc_status:string, lot_sah:bool, lot_alasan?:string,
     *   jk?:string, umur_hari?:?int
     * } $konteks
     *
     * @return array{
     *   validasi:bool, alasan:string,
     *   rule_set_id:?int, rule_id:?int, rincian:array<string,mixed>
     * }
     */
    public static function putuskan(array $konteks): array
    {
        $set = self::himpunanAktif(
            isset($konteks['instrument_id']) ? $konteks['instrument_id'] : null
        );

        if ($set === null) {
            return [
                'validasi'    => false,
                'alasan'      => 'Tidak ada himpunan aturan autovalidasi yang aktif dan disetujui.',
                'rule_set_id' => null,
                'rule_id'     => null,
                'rincian'     => [],
            ];
        }

        $aturan = self::aturan((int) $set['id']);
        if ($aturan === []) {
            return [
                'validasi'    => false,
                'alasan'      => 'Himpunan aturan "' . (string) $set['nama'] . '" tidak berisi aturan aktif.',
                'rule_set_id' => (int) $set['id'],
                'rule_id'     => null,
                'rincian'     => [],
            ];
        }

        foreach ($aturan as $a) {
            if (!self::cakupanCocok($a, $konteks)) {
                continue;
            }

            $periksa = self::periksaSyarat($a, $konteks);

            // Aturan bertindakan "tahan": bila SYARATNYA terpenuhi, hasil
            // ditahan. Dipakai untuk pengecualian yang harus didahulukan,
            // mis. flag alat yang meragukan.
            if ((string) $a['aksi'] === 'tahan') {
                if ($periksa['cocok']) {
                    return [
                        'validasi'    => false,
                        'alasan'      => (string) ($a['alasan_tahan'] ?? $a['nama']),
                        'rule_set_id' => (int) $set['id'],
                        'rule_id'     => (int) $a['id'],
                        'rincian'     => $periksa['rincian'],
                    ];
                }
                continue;
            }

            // Aturan bertindakan "validasi": syarat harus terpenuhi
            // seluruhnya. Yang pertama cocok menentukan hasil; yang gagal
            // menghentikan pencarian, karena aturan berikutnya yang lebih
            // longgar tidak boleh membatalkan penolakan aturan di atasnya.
            if ($periksa['cocok']) {
                return [
                    'validasi'    => true,
                    'alasan'      => 'Memenuhi aturan "' . (string) $a['nama'] . '".',
                    'rule_set_id' => (int) $set['id'],
                    'rule_id'     => (int) $a['id'],
                    'rincian'     => $periksa['rincian'],
                ];
            }

            return [
                'validasi'    => false,
                'alasan'      => $periksa['alasan'] !== ''
                    ? $periksa['alasan']
                    : 'Tidak memenuhi aturan "' . (string) $a['nama'] . '".',
                'rule_set_id' => (int) $set['id'],
                'rule_id'     => (int) $a['id'],
                'rincian'     => $periksa['rincian'],
            ];
        }

        return [
            'validasi'    => false,
            'alasan'      => 'Tidak ada aturan yang mencakup pemeriksaan ini — hasil ditahan untuk ditinjau.',
            'rule_set_id' => (int) $set['id'],
            'rule_id'     => null,
            'rincian'     => [],
        ];
    }

    /** Menyimpan jejak keputusan agar dapat ditelusuri kemudian. */
    public static function catat(int $resultId, array $keputusan): void
    {
        try {
            Database::insert('verification_decisions', [
                'result_id'   => $resultId,
                'rule_set_id' => $keputusan['rule_set_id'] ?? null,
                'rule_id'     => $keputusan['rule_id'] ?? null,
                'keputusan'   => ($keputusan['validasi'] ?? false) ? 'validasi' : 'tahan',
                'alasan'      => mb_substr((string) ($keputusan['alasan'] ?? ''), 0, 255),
                'rincian'     => json_encode(
                    $keputusan['rincian'] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);
        } catch (\Throwable $e) {
            // Jejak yang gagal disimpan tidak boleh membatalkan hasil.
            Logger::warning('Gagal mencatat keputusan autovalidasi: ' . $e->getMessage());
        }
    }

    /**
     * Himpunan aturan yang berlaku: milik alat bila ditetapkan, jika
     * tidak maka himpunan berstatus aktif yang masa berlakunya sedang
     * berjalan.
     *
     * @return array<string,mixed>|null
     */
    public static function himpunanAktif(?int $instrumentId): ?array
    {
        $kunci = $instrumentId ?? 0;
        if (array_key_exists($kunci, self::$singgahanSet)) {
            return self::$singgahanSet[$kunci];
        }

        $set = null;

        if ($instrumentId !== null) {
            $set = Database::selectOne(
                'SELECT vs.* FROM instruments i
                   JOIN verification_rule_sets vs ON vs.id = i.rule_set_id
                  WHERE i.id = ?
                    AND vs.status = \'aktif\'
                    AND (vs.berlaku_dari  IS NULL OR vs.berlaku_dari  <= NOW())
                    AND (vs.berlaku_sampai IS NULL OR vs.berlaku_sampai >= NOW())
                  LIMIT 1',
                [$instrumentId]
            );
        }

        if ($set === null) {
            $set = Database::selectOne(
                'SELECT * FROM verification_rule_sets
                  WHERE status = \'aktif\'
                    AND (berlaku_dari  IS NULL OR berlaku_dari  <= NOW())
                    AND (berlaku_sampai IS NULL OR berlaku_sampai >= NOW())
                  ORDER BY berlaku_dari DESC, id DESC
                  LIMIT 1'
            );
        }

        return self::$singgahanSet[$kunci] = $set;
    }

    /** @return array<int,array<string,mixed>> */
    private static function aturan(int $ruleSetId): array
    {
        if (isset(self::$singgahanAturan[$ruleSetId])) {
            return self::$singgahanAturan[$ruleSetId];
        }

        return self::$singgahanAturan[$ruleSetId] = Database::select(
            'SELECT * FROM verification_rules
              WHERE rule_set_id = ? AND aktif = 1
              ORDER BY urut ASC, id ASC',
            [$ruleSetId]
        );
    }

    /** Apakah aturan ini berlaku atas hasil tersebut. */
    private static function cakupanCocok(array $a, array $k): bool
    {
        if ($a['test_id'] !== null && (int) $a['test_id'] !== (int) $k['test_id']) {
            return false;
        }

        if ($a['instrument_id'] !== null
            && (int) $a['instrument_id'] !== (int) ($k['instrument_id'] ?? 0)) {
            return false;
        }

        $jk = (string) ($a['jk'] ?? 'A');
        if ($jk !== 'A' && isset($k['jk']) && $k['jk'] !== '' && $jk !== $k['jk']) {
            return false;
        }

        $umur = $k['umur_hari'] ?? null;
        if ($umur !== null) {
            if ($a['umur_min_hari'] !== null && $umur < (int) $a['umur_min_hari']) {
                return false;
            }
            if ($a['umur_max_hari'] !== null && $umur > (int) $a['umur_max_hari']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{cocok:bool, alasan:string, rincian:array<string,mixed>}
     */
    private static function periksaSyarat(array $a, array $k): array
    {
        $rincian = [];
        $gagal   = '';

        $catat = static function (string $nama, bool $lulus, $nilai) use (&$rincian): bool {
            $rincian[$nama] = ['lulus' => $lulus, 'nilai' => $nilai];
            return $lulus;
        };

        if ((int) $a['tolak_jika_kritis'] === 1) {
            if (!$catat('kritis', !$k['is_kritis'], $k['is_kritis'])) {
                $gagal = 'Nilai kritis wajib divalidasi manusia.';
            }
        }

        if ($gagal === '' && (int) $a['tolak_jika_satuan_bentrok'] === 1) {
            if (!$catat('satuan', !$k['satuan_bentrok'], $k['satuan_bentrok'])) {
                $gagal = 'Satuan alat dan master berbeda dan konversinya tidak pasti.';
            }
        }

        if ($gagal === '' && (int) $a['tolak_jika_qc_gagal'] === 1) {
            $qc = (string) ($k['qc_status'] ?? 'tidak_berlaku');
            if (!$catat('qc_gagal', !in_array($qc, ['gagal', 'peringatan'], true), $qc)) {
                $gagal = $qc === 'gagal'
                    ? 'Kontrol kualitas alat sedang gagal.'
                    : 'Kontrol kualitas alat berstatus peringatan.';
            }
        }

        if ($gagal === '' && (int) $a['tolak_jika_qc_belum'] === 1) {
            $qc = (string) ($k['qc_status'] ?? 'tidak_berlaku');
            if (!$catat('qc_belum', $qc !== 'belum', $qc)) {
                $gagal = 'Kontrol kualitas belum dijalankan untuk parameter ini.';
            }
        }

        if ($gagal === '' && (int) $a['tolak_jika_lot_kadaluarsa'] === 1) {
            if (!$catat('lot', $k['lot_sah'] ?? true, $k['lot_alasan'] ?? '')) {
                $gagal = (string) ($k['lot_alasan'] ?? 'Lot reagen tidak sah.');
            }
        }

        if ($gagal === '' && $a['flag_maks'] !== null) {
            $ok = self::flagDalamBatas((string) $k['flag'], (string) $a['flag_maks']);
            if (!$catat('flag', $ok, $k['flag'])) {
                $gagal = sprintf(
                    'Flag hasil "%s" melebihi batas autovalidasi "%s".',
                    (string) $k['flag'] !== '' ? (string) $k['flag'] : '-',
                    (string) $a['flag_maks']
                );
            }
        }

        if ($gagal === '' && $a['delta_maks_persen'] !== null) {
            $status = (string) ($k['delta_status'] ?? 'tidak_ada_data');
            $persen = $k['delta_persen'] ?? null;

            $ok = $status !== 'flagged'
                && ($persen === null || abs((float) $persen) <= (float) $a['delta_maks_persen']);

            if (!$catat('delta', $ok, $persen)) {
                $gagal = 'Perubahan terhadap hasil sebelumnya melampaui ambang delta check.';
            }
        }

        if ($gagal === '' && ($a['nilai_min'] !== null || $a['nilai_maks'] !== null)) {
            $n  = $k['nilai_num'] ?? null;
            $ok = $n !== null
                && ($a['nilai_min']  === null || (float) $n >= (float) $a['nilai_min'])
                && ($a['nilai_maks'] === null || (float) $n <= (float) $a['nilai_maks']);

            if (!$catat('rentang_nilai', $ok, $n)) {
                $gagal = 'Nilai berada di luar rentang yang boleh diautovalidasi.';
            }
        }

        if ($gagal === '' && $a['tolak_jika_flag_alat'] !== null
            && trim((string) $a['tolak_jika_flag_alat']) !== '') {

            $flagAlat = trim((string) ($k['flag_alat'] ?? ''));
            $daftar   = array_filter(array_map('trim', explode(',', (string) $a['tolak_jika_flag_alat'])));

            $tersangkut = false;
            foreach ($daftar as $tanda) {
                if ($tanda !== '' && $flagAlat !== '' && stripos($flagAlat, $tanda) !== false) {
                    $tersangkut = true;
                    break;
                }
            }

            // Untuk aturan bertindakan "tahan", tersangkutnya flag berarti
            // syarat TERPENUHI; untuk "validasi", berarti gagal.
            if ((string) $a['aksi'] === 'tahan') {
                $catat('flag_alat', $tersangkut, $flagAlat);
                return [
                    'cocok'   => $tersangkut,
                    'alasan'  => '',
                    'rincian' => $rincian,
                ];
            }

            if (!$catat('flag_alat', !$tersangkut, $flagAlat)) {
                $gagal = sprintf('Alat menandai hasil dengan "%s".', $flagAlat);
            }
        } elseif ((string) $a['aksi'] === 'tahan') {
            // Aturan "tahan" tanpa syarat flag alat: seluruh syarat lain
            // yang terisi harus terpenuhi agar hasil ditahan.
            return [
                'cocok'   => $gagal === '' && $rincian !== [],
                'alasan'  => '',
                'rincian' => $rincian,
            ];
        }

        return [
            'cocok'   => $gagal === '',
            'alasan'  => $gagal,
            'rincian' => $rincian,
        ];
    }

    /**
     * Urutan keparahan flag. "" (belum dinilai) diperlakukan seketat N
     * bukan lebih longgar — nilai yang tidak dapat dinilai bukan nilai
     * yang aman.
     */
    private static function flagDalamBatas(string $flag, string $batas): bool
    {
        $peringkat = ['' => 1, 'N' => 0, 'L' => 1, 'H' => 1, 'A' => 2, 'LL' => 3, 'HH' => 3];

        $f = $peringkat[$flag]  ?? 3;
        $b = $peringkat[$batas] ?? 0;

        // "" hanya lolos bila batasnya sendiri mengizinkan tingkat 1,
        // dan flag itu bukan flag yang dikenali.
        if ($flag === '') {
            return false;
        }

        return $f <= $b;
    }

    /** Membersihkan singgahan — dipakai pengujian dan proses batch. */
    public static function lupakan(): void
    {
        self::$singgahanSet    = [];
        self::$singgahanAturan = [];
    }
}
