<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router sederhana berbasis pola segmen.
 *
 * Pola parameter memakai kurung kurawal: /orders/{id}/edit
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:mixed,name:?string,middleware:array<int,string>}> */
    private array $routes = [];

    /** @var array<int,string> Middleware yang berlaku untuk grup saat ini */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    public function get(string $pattern, mixed $handler, array $middleware = []): self
    {
        return $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, mixed $handler, array $middleware = []): self
    {
        return $this->add('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, mixed $handler, array $middleware = []): self
    {
        return $this->add('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, mixed $handler, array $middleware = []): self
    {
        return $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /** Rute yang menerima GET sekaligus POST. */
    public function any(string $pattern, mixed $handler, array $middleware = []): self
    {
        $this->add('GET', $pattern, $handler, $middleware);

        return $this->add('POST', $pattern, $handler, $middleware);
    }

    public function add(string $method, string $pattern, mixed $handler, array $middleware = []): self
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->normalize($this->groupPrefix . $pattern),
            'handler'    => $handler,
            'name'       => null,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];

        return $this;
    }

    /**
     * Kelompokkan rute dengan prefix dan middleware bersama.
     *
     * @param callable(self):void $callback
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    private function normalize(string $pattern): string
    {
        $pattern = '/' . trim($pattern, '/');

        return $pattern === '/' ? '/' : rtrim($pattern, '/');
    }

    /**
     * Cocokkan request dengan daftar rute.
     *
     * @return array{handler:mixed,params:array<string,string>,middleware:array<int,string>}|null
     */
    public function match(Request $request): ?array
    {
        $path         = $request->path();
        $method       = $request->method();
        $pathMatched  = false;

        foreach ($this->routes as $route) {
            $params = $this->matchPattern($route['pattern'], $path);
            if ($params === null) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            return [
                'handler'    => $route['handler'],
                'params'     => $params,
                'middleware' => $route['middleware'],
            ];
        }

        // Path cocok tetapi method tidak — 405.
        if ($pathMatched) {
            return ['handler' => '__405__', 'params' => [], 'middleware' => []];
        }

        return null;
    }

    /** @return array<string,string>|null */
    private function matchPattern(string $pattern, string $path): ?array
    {
        if (!str_contains($pattern, '{')) {
            return $pattern === $path ? [] : null;
        }

        $patternSegments = explode('/', trim($pattern, '/'));
        $pathSegments    = explode('/', trim($path, '/'));

        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];
        foreach ($patternSegments as $index => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $name          = trim($segment, '{}');
                $params[$name] = rawurldecode($pathSegments[$index]);
                continue;
            }
            if ($segment !== $pathSegments[$index]) {
                return null;
            }
        }

        return $params;
    }
}
