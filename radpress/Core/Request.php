<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
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
        }

        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_SERVER
        );
    }
}

