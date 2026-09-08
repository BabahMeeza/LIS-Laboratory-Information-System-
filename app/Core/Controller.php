<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Kelas dasar controller web.
 */
abstract class Controller
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], string $judul = ''): Response
    {
        return View::render($template, $data, $judul);
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    protected function back(string $fallback = '/'): Response
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        // $fallback diteruskan sebagai jalur aplikasi apa adanya.
        //
        // Response::redirect() sudah menjalankan Url::to(), jadi memanggil
        // Url::to() di sini membuat awalan instalasi terpasang dua kali —
        // "/LIS/public/LIS/public/order/baru". Bug itu tersembunyi di
        // peramban karena header Referer hampir selalu ada sehingga cabang
        // fallback jarang dipakai, dan baru muncul ketika Referer tidak
        // terkirim (kiriman lintas-asal, pengaturan privasi, atau pengujian
        // lewat curl).
        //
        // Referer yang sudah berupa URL utuh dilewatkan Url::to() tanpa
        // diubah, sehingga aman dikirim lewat jalur yang sama.
        return Response::redirect($referer !== '' ? $referer : $fallback);
    }

    /** @param array<mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * Hentikan aksi bila pengguna tidak memiliki izin.
     *
     * @throws HttpException
     */
    protected function otorisasi(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, 'Anda tidak memiliki hak akses untuk tindakan ini.');
        }
    }

    /**
     * Validasi input; bila gagal kembalikan null setelah menyiapkan flash.
     *
     * @param  array<string,string> $rules
     * @param  array<string,string> $labels
     * @return array<string,mixed>|null
     */
    protected function validasi(array $rules, array $labels = []): ?array
    {
        $validator = new Validator($this->request->all(), $rules, $labels);

        if ($validator->gagal()) {
            Flash::simpanError($validator->errors());
            Flash::simpanInput($this->request->all());
            Flash::error($validator->pesanPertama());

            return null;
        }

        return $this->request->all();
    }

    protected function halaman(): int
    {
        return max(1, $this->request->int('hal', 1));
    }

    protected function perHalaman(int $default = 25): int
    {
        $n = $this->request->int('per', $default);

        return max(10, min(200, $n));
    }
}
