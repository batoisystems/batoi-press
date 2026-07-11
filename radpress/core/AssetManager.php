<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final class AssetManager
{
    private const TYPES = [
        'jpg' => 'images',
        'jpeg' => 'images',
        'png' => 'images',
        'gif' => 'images',
        'webp' => 'images',
        'css' => 'styles',
        'js' => 'scripts',
        'mjs' => 'scripts',
        'mp3' => 'audio',
        'wav' => 'audio',
        'ogg' => 'audio',
        'm4a' => 'audio',
        'mp4' => 'video',
        'webm' => 'video',
        'mov' => 'video',
    ];

    public function __construct(private readonly Paths $paths)
    {
    }

    public function relativeUploadPath(string $safeName, ?DateTimeInterface $date = null): string
    {
        $date ??= new DateTimeImmutable();
        $type = self::typeForName($safeName);

        return match ($type) {
            'styles' => 'styles/custom/' . $safeName,
            'scripts' => 'scripts/custom/' . $safeName,
            'audio', 'video' => 'multimedia/' . $type . '/' . $date->format('Y/m') . '/' . $safeName,
            'images' => 'images/' . $date->format('Y/m') . '/' . $safeName,
            default => 'documents/' . $date->format('Y/m') . '/' . $safeName,
        };
    }

    public function prepareTarget(string $relative): string
    {
        $relative = self::validateRelativePath($relative);
        $target = $this->paths->contentPath('assets/' . $relative);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to prepare asset storage.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Asset storage is not writable.');
        }

        return $target;
    }

    public function all(): array
    {
        $records = [];
        $assetRoot = $this->paths->contentPath('assets');
        foreach ($this->recursiveFiles($assetRoot) as $file) {
            $relative = ltrim(substr($file, strlen($assetRoot)), '/');
            if ($relative === '' || str_starts_with($relative, 'libraries/') || basename($relative) === '.gitkeep') {
                continue;
            }
            $records[] = $this->record('assets', $relative, $file);
        }

        $legacyRoot = $this->paths->contentPath('media');
        foreach (glob($legacyRoot . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep') {
                $records[] = $this->record('media', basename($file), $file);
            }
        }

        usort($records, static fn (array $a, array $b): int => strcasecmp((string)$a['relative'], (string)$b['relative']));
        return $records;
    }

    public function resolveAsset(string $relative): ?string
    {
        try {
            $relative = self::validateRelativePath($relative);
        } catch (RuntimeException) {
            return null;
        }

        $root = $this->paths->contentPath('assets');
        $file = $root . '/' . $relative;
        if (!is_file($file)) {
            return null;
        }

        $realRoot = realpath($root);
        $realFile = realpath($file);
        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    public function delete(string $storage, string $relative): bool
    {
        if ($storage === 'media') {
            $name = basename($relative);
            if ($name === '' || $name !== $relative || str_contains($name, '..')) {
                return false;
            }
            $file = $this->paths->contentPath('media/' . $name);
        } elseif ($storage === 'assets') {
            $file = $this->resolveAsset($relative);
            if ($file === null || str_starts_with(self::validateRelativePath($relative), 'libraries/')) {
                return false;
            }
        } else {
            return false;
        }

        return is_file($file) && unlink($file);
    }

    public static function typeForName(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return self::TYPES[$extension] ?? 'documents';
    }

    public static function kindForName(string $name): string
    {
        return match (self::typeForName($name)) {
            'images' => 'Image asset',
            'styles' => 'Stylesheet asset',
            'scripts' => 'Script asset',
            'audio' => 'Audio asset',
            'video' => 'Video asset',
            default => 'Document asset',
        };
    }

    public static function mimeType(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'application/javascript; charset=UTF-8',
            'json', 'map' => 'application/json; charset=UTF-8',
            'txt', 'md' => 'text/plain; charset=UTF-8',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            default => 'application/octet-stream',
        };
    }

    public static function validateRelativePath(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
            throw new RuntimeException('Invalid asset path.');
        }
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Invalid asset path.');
            }
        }

        return implode('/', $segments);
    }

    private function record(string $storage, string $relative, string $file): array
    {
        $url = '/' . $storage . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
        return [
            'storage' => $storage,
            'relative' => $relative,
            'name' => basename($relative),
            'path' => $file,
            'url' => $url,
            'type' => self::typeForName($relative),
            'kind' => self::kindForName($relative),
            'size' => filesize($file) ?: 0,
            'modified' => filemtime($file) ?: 0,
        ];
    }

    private function recursiveFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $files = array_merge($files, $this->recursiveFiles($path));
            } elseif (is_file($path)) {
                $files[] = $path;
            }
        }
        return $files;
    }
}
