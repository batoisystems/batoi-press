<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;

final class FileStore
{
    public function read(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('File not found: ' . $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read file: ' . $path);
        }

        return $contents;
    }

    public function readJson(string $path): array
    {
        $decoded = json_decode($this->read($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: ' . $path);
        }

        return $decoded;
    }

    public function write(string $path, string $contents, bool $append = false): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $flags = LOCK_EX | ($append ? FILE_APPEND : 0);
        if (file_put_contents($path, $contents, $flags) === false) {
            throw new RuntimeException('Unable to write file: ' . $path);
        }
    }

    public function writeJson(string $path, array $data): void
    {
        $this->write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function exists(string $path): bool
    {
        return is_file($path) || is_dir($path);
    }
}
