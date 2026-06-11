<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        if (isset($_GET['route']) && is_string($_GET['route']) && $_GET['route'] !== '') {
            $path = $_GET['route'];
        } else {
            $path = self::stripBasePath($path);
        }

        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_SERVER
        );
    }

    private static function stripBasePath(string $path): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = dirname($script);
        if ($base === '/' || $base === '.' || $base === '\\') {
            return $path;
        }

        $base = rtrim($base, '/');
        if ($path === $base) {
            return '/';
        }

        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base)) ?: '/';
        }

        return $path;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }
}
