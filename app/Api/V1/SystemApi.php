<?php
declare(strict_types=1);

namespace App\Api\V1;

use App\Core\Config;
use App\Core\Database;
use App\Core\Response;

final class SystemApi extends ApiController
{
    /**
     * Endpoint kesehatan tanpa autentikasi — dipakai middleware dan
     * pemantauan untuk memastikan LIS hidup dan database terjangkau.
     */
    public function ping(): Response
    {
        $db = true;
        try {
            Database::scalar('SELECT 1');
        } catch (\Throwable) {
            $db = false;
        }

        return $this->sukses([
            'aplikasi' => 'LIS',
            'versi'    => defined('LIS_VERSI') ? LIS_VERSI : '1.0.0',
            'database' => $db ? 'terhubung' : 'gagal',
            'zona'     => (string) Config::get('app.timezone', 'Asia/Jakarta'),
        ], $db ? 'LIS siap' : 'LIS berjalan namun database tidak terjangkau', $db ? 200 : 503);
    }
}
