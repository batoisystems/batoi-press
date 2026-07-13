<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final class AssetManager
{
    public const DEFAULT_MAX_BYTES = 26214400;
    public const MAX_EDITABLE_TEXT_BYTES = 2097152;
    public const EDITABLE_TEXT_EXTENSIONS = ['css', 'js', 'mjs', 'txt', 'md'];
    public const DEFAULT_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md',
        'css', 'js', 'mjs', 'mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm', 'mov',
    ];

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

    public function find(string $storage, string $relative): ?array
    {
        $file = $this->resolveStoredFile($storage, $relative);
        return $file !== null ? $this->record($storage, $relative, $file) : null;
    }

    public function readEditableText(string $storage, string $relative): string
    {
        if (!self::isTextEditable($relative)) {
            throw new RuntimeException('This asset type does not support source editing.');
        }
        $file = $this->resolveStoredFile($storage, $relative);
        if ($file === null || filesize($file) > self::MAX_EDITABLE_TEXT_BYTES) {
            throw new RuntimeException('Editable asset was not found or exceeds the source editor limit.');
        }
        $contents = file_get_contents($file);
        if (!is_string($contents) || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('Editable assets must contain valid UTF-8 text.');
        }
        return $contents;
    }

    public function updateText(string $storage, string $relative, string $contents): void
    {
        if (!self::isTextEditable($relative)) {
            throw new RuntimeException('This asset type does not support source editing.');
        }
        if (strlen($contents) > self::MAX_EDITABLE_TEXT_BYTES || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('Source must be valid UTF-8 text within the 2 MB editor limit.');
        }
        $target = $this->resolveStoredFile($storage, $relative);
        if ($target === null) {
            throw new RuntimeException('Asset was not found.');
        }
        $stage = $this->stagingPath($target);
        if (file_put_contents($stage, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to stage the edited asset.');
        }
        $this->publishReplacement($storage, $relative, $target, $stage);
    }

    public function replace(string $storage, string $relative, string $source, bool $uploaded = true): void
    {
        $target = $this->resolveStoredFile($storage, $relative);
        if ($target === null || !is_file($source)) {
            throw new RuntimeException('Asset or replacement file was not found.');
        }
        $stage = $this->stagingPath($target);
        $saved = $uploaded ? move_uploaded_file($source, $stage) : copy($source, $stage);
        if (!$saved) {
            throw new RuntimeException('Unable to stage the replacement asset.');
        }
        $this->publishReplacement($storage, $relative, $target, $stage);
    }

    public static function isTextEditable(string $relative): bool
    {
        return in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::EDITABLE_TEXT_EXTENSIONS, true);
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

    private function resolveStoredFile(string $storage, string $relative): ?string
    {
        if ($storage === 'media') {
            $name = basename($relative);
            if ($name === '' || $name !== $relative || str_contains($name, '..')) {
                return null;
            }
            $file = $this->paths->contentPath('media/' . $name);
            return is_file($file) ? $file : null;
        }
        if ($storage !== 'assets') {
            return null;
        }
        try {
            $validated = self::validateRelativePath($relative);
        } catch (RuntimeException) {
            return null;
        }
        if (str_starts_with($validated, 'libraries/')) {
            return null;
        }
        return $this->resolveAsset($validated);
    }

    private function stagingPath(string $target): string
    {
        return dirname($target) . '/.bp-replace-' . bin2hex(random_bytes(6));
    }

    private function publishReplacement(string $storage, string $relative, string $target, string $stage): void
    {
        $previous = dirname($target) . '/.bp-previous-' . bin2hex(random_bytes(6));
        try {
            $this->snapshot($storage, $relative, $target);
            if (!rename($target, $previous)) {
                throw new RuntimeException('Unable to prepare the existing asset for replacement.');
            }
            if (!rename($stage, $target)) {
                rename($previous, $target);
                throw new RuntimeException('Unable to publish the replacement asset.');
            }
            chmod($target, 0664);
            unlink($previous);
        } finally {
            if (is_file($stage)) {
                unlink($stage);
            }
        }
    }

    private function snapshot(string $storage, string $relative, string $target): void
    {
        $directory = $this->paths->dataPath('versions/assets/' . substr(hash('sha256', $storage . ':' . $relative), 0, 20));
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to prepare asset version storage.');
        }
        $snapshot = $directory . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . basename($relative);
        if (!copy($target, $snapshot)) {
            throw new RuntimeException('Unable to retain the previous asset version.');
        }
        chmod($snapshot, 0660);
    }

    public static function typeForName(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return self::TYPES[$extension] ?? 'documents';
    }

    public static function effectiveUploadExtensions(array $configured): array
    {
        $configured = array_values(array_unique(array_filter(array_map(
            static fn (mixed $extension): string => strtolower(ltrim(trim((string)$extension), '.')),
            $configured
        ))));
        $legacyDefaults = [
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md'],
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'css', 'js'],
        ];
        $sortedConfigured = $configured;
        sort($sortedConfigured);
        foreach ($legacyDefaults as $legacy) {
            sort($legacy);
            if ($sortedConfigured === $legacy) {
                return self::DEFAULT_UPLOAD_EXTENSIONS;
            }
        }

        return $configured === [] ? self::DEFAULT_UPLOAD_EXTENSIONS : $configured;
    }

    public static function effectiveMaxBytes(int $configured): int
    {
        return $configured <= 0 || $configured === 5242880 ? self::DEFAULT_MAX_BYTES : $configured;
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
