<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Kernel aplikasi: memuat konfigurasi, menjalankan router,
 * dan menerjemahkan exception menjadi response.
 */
final class App
{
    private Router $router;

    public function __construct()
    {
        $this->bootConfig();
        $this->router = new Router();

        $registerRoutes = require BASE_PATH . '/app/routes.php';
        $registerRoutes($this->router);
    }

    private function bootConfig(): void
    {
        $file = BASE_PATH . '/config/config.php';
        if (!is_file($file)) {
            $file = BASE_PATH . '/config/config.example.php';
        }

        /** @var array<string,mixed> $config */
        $config = require $file;
        Config::load($config);

        date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));

        $debug = (bool) Config::get('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    }

    public function run(): void
    {
        $request = new Request();

        try {
            $response = $this->handle($request);
        } catch (HttpException $e) {
            $response = $this->responseError($request, $e->status(), $e->getMessage());
        } catch (\Throwable $e) {
            Logger::exception($e);
            $pesan = Config::get('app.debug', false)
                ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
                : 'Terjadi kesalahan pada server. Detail tercatat di storage/logs.';
            $response = $this->responseError($request, 500, $pesan);
        }

        $response->send();
    }

    private function handle(Request $request): Response
    {
        $route = $this->router->match($request);

        if ($route === null) {
            throw new HttpException(404);
        }
        if ($route['handler'] === '__405__') {
            throw new HttpException(405);
        }

        // Session hanya untuk rute web; API memakai API key tanpa session.
        $isApi = str_starts_with($request->path(), '/api/');
        if (!$isApi) {
            Session::start();
        }

        // Gerbang lisensi, diperiksa sebelum middleware apa pun supaya tidak
        // ada rute yang lolos hanya karena kebetulan tidak butuh login.
        if (Lisensi::terkunci()) {
            return $this->responseTerkunci($request, $isApi);
        }

        foreach ($route['middleware'] as $middleware) {
            $hasil = $this->jalankanMiddleware($middleware, $request);
            if ($hasil instanceof Response) {
                return $hasil;
            }
        }

        if (!$isApi && !Csrf::check($request)) {
            throw new HttpException(403, 'Token keamanan (CSRF) tidak sah atau telah kedaluwarsa. Muat ulang halaman lalu ulangi.');
        }

        return $this->panggilHandler($route['handler'], $route['params'], $request);
    }

    /**
     * Jawaban saat aplikasi terkunci.
     *
     * Sebabnya disebutkan apa adanya, termasuk berkas mana yang berubah dan
     * perintah untuk memulihkannya. Layar terkunci yang hanya berbunyi
     * "lisensi tidak sah" memaksa petugas menelepon vendor di tengah malam
     * untuk sesuatu yang bisa mereka betulkan sendiri dalam satu perintah.
     */
    private function responseTerkunci(Request $request, bool $isApi): Response
    {
        Logger::warning('Aplikasi terkunci: lisensi tidak sah.', [
            'sebab' => Lisensi::sebab(),
            'jalur' => $request->method() . ' ' . $request->path(),
            'ip'    => $request->ip(),
        ]);

        if ($isApi || $request->wantsJson()) {
            return Response::json([
                'sukses'  => false,
                'pesan'   => 'Lisensi aplikasi tidak sah. Seluruh kredensial API dinonaktifkan. ' . Lisensi::sebab(),
                'status'  => 503,
                'rincian' => Lisensi::rincian(),
            ], 503);
        }

        $data = Lisensi::data();
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $baris = '';
        foreach (Lisensi::rincian() as $r) {
            $baris .= $r === '' ? "<br>" : '<div>' . $e($r) . '</div>';
        }

        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Aplikasi Terkunci</title><style>'
            . 'body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#0f172a;color:#e2e8f0;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}'
            . '.k{max-width:640px;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:28px}'
            . 'h1{margin:0 0 4px;font-size:20px;color:#fca5a5}'
            . 'h2{margin:0 0 20px;font-size:14px;font-weight:400;color:#94a3b8}'
            . '.s{background:#0f172a;border-left:3px solid #ef4444;padding:12px 14px;border-radius:6px;margin-bottom:16px}'
            . '.r{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.7;color:#cbd5e1}'
            . '.f{margin-top:20px;padding-top:16px;border-top:1px solid #334155;font-size:12px;color:#64748b}'
            . '</style></head><body><div class="k">'
            . '<h1>Aplikasi terkunci</h1>'
            . '<h2>' . $e((string) ($data['aplikasi'] ?? 'LIS')) . '</h2>'
            . '<div class="s">' . $e(Lisensi::sebab()) . '</div>'
            . '<div class="r">' . $baris . '</div>'
            . '<div class="f">Identitas aplikasi ditandatangani. Mengubahnya tanpa menerbitkan '
            . 'ulang lisensi menonaktifkan aplikasi beserta seluruh kredensial API. '
            . 'Tidak ada data yang dihapus atau diubah oleh penguncian ini.</div>'
            . '</div></body></html>';

        return Response::make($html, 503)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function jalankanMiddleware(string $middleware, Request $request): ?Response
    {
        if ($middleware === 'auth') {
            if (!Auth::check()) {
                if ($request->wantsJson()) {
                    throw new HttpException(401);
                }
                Session::put('_redirect_setelah_login', $request->path());

                return Response::redirect('/login');
            }

            return null;
        }

        if ($middleware === 'tamu') {
            return Auth::check() ? Response::redirect('/') : null;
        }

        if (str_starts_with($middleware, 'izin:')) {
            $permission = substr($middleware, 5);
            if (!Auth::can($permission)) {
                throw new HttpException(403, 'Peran "' . Auth::role() . '" tidak memiliki izin: ' . $permission);
            }

            return null;
        }

        if (str_starts_with($middleware, 'api:')) {
            ApiAuth::wajib($request, substr($middleware, 4));

            return null;
        }

        return null;
    }

    /** @param array<string,string> $params */
    private function panggilHandler(mixed $handler, array $params, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request, $params);
        } else {
            [$class, $method] = is_array($handler) ? $handler : explode('@', (string) $handler);

            if (!class_exists($class)) {
                throw new \RuntimeException('Controller tidak ditemukan: ' . $class);
            }

            $controller = new $class($request);
            if (!method_exists($controller, $method)) {
                throw new \RuntimeException('Aksi tidak ditemukan: ' . $class . '::' . $method);
            }

            $result = $controller->$method($params);
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::make((string) $result);
    }

    private function responseError(Request $request, int $status, string $pesan): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'sukses' => false,
                'pesan'  => $pesan,
                'status' => $status,
            ], $status);
        }

        try {
            Session::start();

            return Response::make(
                View::capture('layouts/app', [
                    'judul'  => 'Kesalahan ' . $status,
                    'konten' => View::capture('errors/umum', ['status' => $status, 'pesan' => $pesan]),
                ]),
                $status
            );
        } catch (\Throwable) {
            return Response::make(
                '<!doctype html><meta charset="utf-8"><title>Kesalahan ' . $status . '</title>'
                . '<div style="font-family:system-ui;max-width:640px;margin:80px auto;padding:24px;border:1px solid #ddd;border-radius:8px">'
                . '<h1 style="margin:0 0 8px">Kesalahan ' . $status . '</h1><p>' . htmlspecialchars($pesan) . '</p></div>',
                $status
            );
        }
    }
}
