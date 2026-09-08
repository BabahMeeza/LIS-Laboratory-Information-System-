<?php
declare(strict_types=1);

namespace App\Api\V1;

use App\Core\Request;
use App\Core\Response;

/**
 * Basis controller API: format response seragam.
 *
 * Semua endpoint mengembalikan:
 *   { "sukses": bool, "pesan": string, "data": mixed, "waktu": ISO-8601 }
 */
abstract class ApiController
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    protected function sukses(mixed $data = null, string $pesan = 'OK', int $status = 200): Response
    {
        return Response::json([
            'sukses' => true,
            'pesan'  => $pesan,
            'data'   => $data,
            'waktu'  => date('c'),
        ], $status);
    }

    protected function gagal(string $pesan, int $status = 400, mixed $data = null): Response
    {
        return Response::json([
            'sukses' => false,
            'pesan'  => $pesan,
            'data'   => $data,
            'waktu'  => date('c'),
        ], $status);
    }

    /** @return array<string,mixed> */
    protected function body(): array
    {
        $all = $this->request->all();

        return is_array($all) ? $all : [];
    }
}
