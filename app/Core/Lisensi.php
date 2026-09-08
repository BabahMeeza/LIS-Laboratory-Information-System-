<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Penjaga keutuhan identitas aplikasi.
 *
 * Isi halaman Tentang Aplikasi — nama aplikasi, instansi, pengembang,
 * nomor lisensi — disimpan di config/lisensi.php bersama satu tanda tangan
 * RSA-SHA256. Tanda tangan itu meliputi isi datanya DAN sidik sha256
 * berkas-berkas yang menampilkannya. Begitu salah satunya diubah tanpa
 * ditandatangani ulang, tanda tangannya tidak lagi cocok, aplikasi masuk
 * keadaan terkunci, dan seluruh kredensial API berhenti melayani.
 *
 * TANDA TANGAN ASIMETRIS — KUNCI PENANDATANGAN TIDAK ADA DI SERVER
 *
 * Berkas ini hanya memuat kunci PUBLIK. Kunci publik cukup untuk MEMERIKSA
 * tanda tangan, dan tidak dapat dipakai MEMBUAT tanda tangan baru.
 * Menerbitkan lisensi menuntut kunci privat, yang disimpan pemegang lisensi
 * di luar server ini dan tidak pernah ikut terpasang.
 *
 * Itulah bedanya dengan HMAC. Pada HMAC kunci pemeriksa dan kunci
 * penandatangan adalah satu benda yang sama, sehingga siapa pun yang dapat
 * membaca berkas konfigurasi di server dapat menandatangani ulang apa pun
 * sesukanya. Di sini tidak: seluruh isi server boleh terbaca dan tanda
 * tangan yang sah tetap tidak dapat dibuat.
 *
 * SEJAUH MANA INI MENGUNCI — dan sejauh mana tidak
 *
 * Yang tersisa bagi orang yang punya akses berkas bukan lagi memalsukan
 * tanda tangan, melainkan menyunting kode PHP ini sendiri: membuang
 * pemeriksaannya, atau menukar kunci publik di bawah dengan miliknya.
 * Keduanya perbuatan yang jauh berbeda sifatnya dari sekadar mengetik ulang
 * satu baris di halaman Tentang — dan keduanya meninggalkan berkas kode
 * yang berbeda dari rilis resmi, yang dapat dibuktikan dengan membandingkan
 * sidiknya.
 *
 * Menutup celah terakhir itu tidak dapat dilakukan dari dalam PHP yang
 * sumbernya ikut terpasang; jalannya adalah penyandian kode (ionCube,
 * SourceGuardian) atau ekstensi terkompilasi. Menjanjikan "tidak bisa
 * dibongkar" tanpa itu tidak jujur.
 *
 * KEADAAN TERKUNCI SENGAJA DAPAT DIPULIHKAN
 *
 * Ini sistem laboratorium rumah sakit. Kunci yang hanya bisa dibuka oleh
 * pembuatnya adalah bahaya tersendiri: bila mekanisme ini salah menyala
 * pada dini hari, laboratorium berhenti bekerja. Karena itu penguncian
 * TIDAK menulis apa pun ke database, tidak menonaktifkan baris kredensial,
 * dan tidak menghapus apa pun. Ia hanya menolak melayani, menyebutkan
 * persis apa yang berubah, dan menyebutkan satu perintah untuk memulihkan.
 */
final class Lisensi
{
    private const BERKAS = 'config/lisensi.php';

    /** @var array{terkunci:bool,sebab:string,rincian:array<int,string>,data:array<string,string>}|null */
    private static ?array $keadaan = null;

    /** Berkas yang sidiknya ikut ditandatangani. */
    public const DIAWASI = [
        'app/Views/about.php',
        'app/Core/Lisensi.php',
    ];

    /**
     * Kunci publik pemegang lisensi.
     *
     * Hanya untuk memeriksa, tidak dapat dipakai menandatangani. Aman
     * terbaca siapa pun. Diisi oleh: php bin/lisensi.php --buat-kunci
     *
     * Selama masih kosong, aplikasi terkunci — memeriksa tanda tangan
     * dengan kunci yang tidak ada bukanlah pemeriksaan.
     */
    public const KUNCI_PUBLIK = <<<'PEM'
  -----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAlAhWhnUCtg/u4/r2J9Zd
VIuyI4dVjT0IXmNVUvfPGYDL5aUmrIknrE9CRy7o5jGdcF5wcMMeVSE/ht8JRAY7
4Q3Gl7rve2W/a+xTHfGM/k8jlmUjM5cIHbXSvIKp4dGGA+ugCrZqfUMTzlytHIXn
iMewfWVIu5ROjRlEYWK8pkUiXx9iMgNFqBUBVMGeJTDcigChiKhWEjL07ezSHJ+m
LtdjsbH9WK5X5Ouw1nwp2VMJKkz91LmsKgRhRiGsqT6XwU+mcWYN4chrIZszKsnr
Ea6rLvMsvK/WCXplZVkLqYsVmOWNU1FJ8JaeKivu3M7gbd0GxPY9QMnuB6BFfZyi
MKshuGwqIiKpzDS3KjgozY5rOObvCfdSaNHhkBa7v1Kiz5gQE+neqNI6b83UTFmQ
jcycNYbkG5CUibU+YGuE32+4UQD526smbT1vh04Y4wDtgV0OiouzEK/ud/zNxXbH
5eG5nQgw1jLFxFfdIcgDYQhfliABIKDrdpMv8Nbf2btxAgMBAAE=
-----END PUBLIC KEY-----
PEM;

    /** @return array{terkunci:bool,sebab:string,rincian:array<int,string>,data:array<string,string>} */
    public static function keadaan(): array
    {
        if (self::$keadaan !== null) {
            return self::$keadaan;
        }

        return self::$keadaan = self::periksa();
    }

    public static function terkunci(): bool
    {
        return self::keadaan()['terkunci'];
    }

    /** @return array<string,string> */
    public static function data(): array
    {
        return self::keadaan()['data'];
    }

    public static function sebab(): string
    {
        return self::keadaan()['sebab'];
    }

    /** @return array<int,string> */
    public static function rincian(): array
    {
        return self::keadaan()['rincian'];
    }

    /**
     * Bahan yang ditandatangani.
     *
     * Serialisasi kanonik: kunci diurutkan supaya susunan penulisan di
     * berkas tidak ikut mengubah hasilnya.
     *
     * @param array<string,mixed> $manifes
     */
    public static function bahanTandaTangan(array $manifes): string
    {
        $data   = $manifes['data']   ?? [];
        $berkas = $manifes['berkas'] ?? [];

        if (is_array($data)) {
            ksort($data);
        }
        if (is_array($berkas)) {
            ksort($berkas);
        }

        return (string) json_encode(
            ['data' => $data, 'berkas' => $berkas],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /** Kunci publik yang terpasang, sudah dirapikan. */
    public static function kunciPublik(): string
    {
        return trim(self::KUNCI_PUBLIK);
    }

    public static function sidikBerkas(string $relatif): ?string
    {
        $path = BASE_PATH . '/' . ltrim($relatif, '/');

        if (!is_file($path)) {
            return null;
        }

        // Akhiran baris disamakan lebih dulu. Berkas yang hanya berpindah
        // antara CRLF dan LF tidak berubah maknanya, dan mengunci aplikasi
        // karena editor yang berbeda hanyalah gangguan.
        $isi = (string) file_get_contents($path);
        $isi = str_replace("\r\n", "\n", $isi);

        return hash('sha256', $isi);
    }

    /** @return array{terkunci:bool,sebab:string,rincian:array<int,string>,data:array<string,string>} */
    private static function periksa(): array
    {
        $kosong = [];

        $publik = self::kunciPublik();
        if ($publik === '') {
            return [
                'terkunci' => true,
                'sebab'    => 'Kunci publik pemegang lisensi belum terpasang.',
                'rincian'  => [
                    'Konstanta KUNCI_PUBLIK pada app/Core/Lisensi.php masih kosong.',
                    'Buat sepasang kunci di komputer pemegang lisensi:',
                    '  php bin/lisensi.php --buat-kunci=/jalur/aman/lis-privat.pem',
                ],
                'data'     => $kosong,
            ];
        }

        $sumber = @openssl_pkey_get_public($publik);
        if ($sumber === false) {
            return [
                'terkunci' => true,
                'sebab'    => 'Kunci publik pemegang lisensi tidak dapat dibaca.',
                'rincian'  => ['Isi KUNCI_PUBLIK pada app/Core/Lisensi.php bukan kunci publik PEM yang sah.'],
                'data'     => $kosong,
            ];
        }

        $path = BASE_PATH . '/' . self::BERKAS;
        if (!is_file($path)) {
            return [
                'terkunci' => true,
                'sebab'    => 'Berkas lisensi tidak ditemukan.',
                'rincian'  => [
                    self::BERKAS . ' tidak ada.',
                    'Lisensi diterbitkan oleh pemegang kunci privat, lalu berkasnya dipasang di sini.',
                ],
                'data'     => $kosong,
            ];
        }

        $manifes = @include $path;
        if (!is_array($manifes) || !isset($manifes['data'], $manifes['tanda_tangan'])) {
            return [
                'terkunci' => true,
                'sebab'    => 'Berkas lisensi rusak atau tidak lengkap.',
                'rincian'  => [self::BERKAS . ' tidak memuat data dan tanda_tangan yang sah.'],
                'data'     => $kosong,
            ];
        }

        /** @var array<string,string> $data */
        $data = is_array($manifes['data']) ? $manifes['data'] : [];

        $tanda = base64_decode((string) $manifes['tanda_tangan'], true);

        $sah = $tanda !== false && openssl_verify(
            self::bahanTandaTangan($manifes),
            $tanda,
            $sumber,
            OPENSSL_ALGO_SHA256
        ) === 1;

        if (!$sah) {
            return [
                'terkunci' => true,
                'sebab'    => 'Tanda tangan lisensi tidak sah.',
                'rincian'  => [
                    'Isi lisensi diubah tanpa diterbitkan ulang oleh pemegang kunci privat.',
                    'Tanda tangan yang sah hanya dapat dibuat dengan kunci privat pemegang lisensi,',
                    'dan kunci itu tidak tersimpan di server ini.',
                    '',
                    'Kembalikan berkas lisensi yang sah, atau minta lisensi baru diterbitkan.',
                ],
                'data'     => $data,
            ];
        }

        // Sidik berkas yang diawasi.
        $rincian = [];
        $berkas  = is_array($manifes['berkas'] ?? null) ? $manifes['berkas'] : [];

        foreach ($berkas as $relatif => $sidikTercatat) {
            $sekarang = self::sidikBerkas((string) $relatif);

            if ($sekarang === null) {
                $rincian[] = 'Berkas hilang: ' . $relatif;
                continue;
            }
            if (!hash_equals((string) $sidikTercatat, $sekarang)) {
                $rincian[] = 'Berkas berubah: ' . $relatif;
            }
        }

        if ($rincian !== []) {
            $rincian[] = '';
            $rincian[] = 'Kembalikan berkas di atas ke isi rilis resminya. Bila perubahan itu';
            $rincian[] = 'memang sah, lisensi harus diterbitkan ulang oleh pemegang kunci privat.';

            return [
                'terkunci' => true,
                'sebab'    => 'Berkas yang menampilkan identitas aplikasi telah diubah.',
                'rincian'  => $rincian,
                'data'     => $data,
            ];
        }

        return ['terkunci' => false, 'sebab' => '', 'rincian' => [], 'data' => $data];
    }

    /** Dipakai bin/lisensi.php sesudah menerbitkan ulang. */
    public static function lupakan(): void
    {
        self::$keadaan = null;
    }
}
