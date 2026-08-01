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
        public readonly array $server,
        public readonly string $rawBody = ''
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
            $_SERVER,
            (string)file_get_contents('php://input')
        );
    }

    private static function stripBasePath(string $path): string
    {
        $base = BasePath::detect($_SERVER);
        if ($base === '') {
            return $path;
        }
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

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', trim($name)));
        if (strtolower($name) === 'content-type') {
            $key = 'CONTENT_TYPE';
        }
        if (strtolower($name) === 'content-length') {
            $key = 'CONTENT_LENGTH';
        }
        $value = $this->server[$key] ?? (strtolower($name) === 'authorization' ? ($this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? $default) : $default);
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function json(): ?array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : null;
    }
}
