<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Paths
{
    public function __construct(
        private readonly string $root,
        private readonly array $paths = []
    ) {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function get(string $key): string
    {
        $relative = (string)($this->paths[$key] ?? $key);
        if (str_starts_with($relative, '/')) {
            return $relative;
        }

        return $this->root . '/' . trim($relative, '/');
    }

    public function publicPath(string $path = ''): string
    {
        return $this->get('public_root') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function configPath(string $path = ''): string
    {
        return $this->get('config') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function contentPath(string $path = ''): string
    {
        return $this->get('content') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->get('storage') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function themesPath(string $path = ''): string
    {
        return $this->get('themes') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

