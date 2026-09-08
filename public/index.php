<?php
declare(strict_types=1);

/**
 * LIS — Laboratory Information System
 * Front controller. Seluruh request web dan API masuk lewat berkas ini.
 *
 * DocumentRoot / Alias harus menunjuk ke folder public/ ini.
 */

define('BASE_PATH', dirname(__DIR__));
define('LIS_VERSI', '1.0.0');

require BASE_PATH . '/app/bootstrap.php';

(new App\Core\App())->run();
