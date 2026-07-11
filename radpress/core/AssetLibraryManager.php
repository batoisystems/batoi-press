<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;
use ZipArchive;

final class AssetLibraryManager
{
    private const ALLOWED_EXTENSIONS = [
        'css', 'js', 'mjs', 'json', 'map',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp',
    ];
    private const MAX_FILES = 1000;
    private const MAX_EXTRACTED_BYTES = 52428800;

    public function __construct(private readonly Paths $paths)
    {
    }

    public function installZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Library installation requires the PHP Zip extension.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open library ZIP.');
        }
        if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new RuntimeException('Library package file count is not allowed.');
        }

        $manifestContents = $zip->getFromName('library.json');
        if ($manifestContents === false) {
            $zip->close();
            throw new RuntimeException('Library ZIP must contain library.json at its root.');
        }
        $decoded = json_decode($manifestContents, true);
        if (!is_array($decoded)) {
            $zip->close();
            throw new RuntimeException('Library manifest is not valid JSON.');
        }
        $manifest = $this->normalizeManifest($decoded);
        $target = $this->libraryPath((string)$manifest['name'], (string)$manifest['version']);
        if (is_dir($target)) {
            $zip->close();
            throw new RuntimeException('This library version is already installed.');
        }

        $staging = $this->paths->dataPath('tmp/library-' . bin2hex(random_bytes(5)));
        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            $zip->close();
            throw new RuntimeException('Unable to prepare library installation workspace.');
        }

        try {
            $totalBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string)$zip->getNameIndex($index);
                $this->validateEntry($zip, $index, $name);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stat = $zip->statIndex($index);
                $totalBytes += (int)($stat['size'] ?? 0);
                if ($totalBytes > self::MAX_EXTRACTED_BYTES) {
                    throw new RuntimeException('Library package is too large after extraction.');
                }
                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    throw new RuntimeException('Unable to read library package entry.');
                }
                $file = $staging . '/' . AssetManager::validateRelativePath($name);
                $directory = dirname($file);
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create a library package directory.');
                }
                if (file_put_contents($file, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write a library package file.');
                }
            }
            $this->validateEntryPoints($staging, $manifest);
            file_put_contents($staging . '/library.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to prepare library storage.');
            }
            if (!rename($staging, $target)) {
                throw new RuntimeException('Unable to install the library package.');
            }
            if ((bool)$manifest['enabled']) {
                $this->disableOtherVersions((string)$manifest['name'], (string)$manifest['version']);
            }
        } finally {
            $zip->close();
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
        }

        return $manifest;
    }

    public function all(): array
    {
        $libraries = [];
        foreach (glob($this->root() . '/*/*/library.json') ?: [] as $file) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            try {
                $manifest = $this->normalizeManifest($decoded);
                $this->validateEntryPoints(dirname($file), $manifest);
            } catch (RuntimeException) {
                continue;
            }
            $manifest['path'] = dirname($file);
            $libraries[] = $manifest;
        }
        usort($libraries, static fn (array $a, array $b): int => strcasecmp((string)$a['name'] . ':' . (string)$a['version'], (string)$b['name'] . ':' . (string)$b['version']));
        return $libraries;
    }

    public function setEnabled(string $name, string $version, bool $enabled): array
    {
        $file = $this->manifestPath($name, $version);
        if (!is_file($file)) {
            throw new RuntimeException('Library version was not found.');
        }
        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Library manifest is invalid.');
        }
        $decoded['enabled'] = $enabled;
        $manifest = $this->normalizeManifest($decoded);
        if (file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to update the library manifest.');
        }
        if ($enabled) {
            $this->disableOtherVersions((string)$manifest['name'], (string)$manifest['version']);
        }
        return $manifest;
    }

    public function delete(string $name, string $version): void
    {
        $directory = $this->libraryPath($name, $version);
        if (!is_dir($directory) || !is_file($directory . '/library.json')) {
            throw new RuntimeException('Library version was not found.');
        }
        $this->removeDirectory($directory);
        $parent = dirname($directory);
        if (is_dir($parent) && (scandir($parent) ?: []) === ['.', '..']) {
            rmdir($parent);
        }
    }

    public function tags(string $position, bool $localized = true): string
    {
        $html = '';
        foreach ($this->all() as $library) {
            if (!($library['enabled'] ?? false) || ($library['scope'] ?? '') !== 'global') {
                continue;
            }
            $entries = $position === 'head' ? (array)$library['styles'] : (array)$library['scripts'];
            foreach ($entries as $entry) {
                $url = '/assets/libraries/' . rawurlencode((string)$library['name']) . '/' . rawurlencode((string)$library['version']) . '/' . implode('/', array_map('rawurlencode', explode('/', (string)$entry['file'])));
                if ($localized && function_exists('bp_url')) {
                    $url = \bp_url($url);
                }
                if ($position === 'head') {
                    $html .= '<link rel="stylesheet" href="' . $this->e($url) . '"' . $this->attributes($entry, ['media', 'integrity', 'crossorigin']) . '>' . "\n";
                } else {
                    $attributes = $this->attributes($entry, ['integrity', 'crossorigin']);
                    $attributes .= !empty($entry['module']) ? ' type="module"' : '';
                    $attributes .= !empty($entry['defer']) && empty($entry['module']) ? ' defer' : '';
                    $attributes .= !empty($entry['async']) ? ' async' : '';
                    $html .= '<script src="' . $this->e($url) . '"' . $attributes . '></script>' . "\n";
                }
            }
        }
        return $html;
    }

    private function normalizeManifest(array $manifest): array
    {
        $name = strtolower(trim((string)($manifest['name'] ?? '')));
        $version = trim((string)($manifest['version'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $name)) {
            throw new RuntimeException('Library name must be a lowercase slug.');
        }
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,79}$/', $version)) {
            throw new RuntimeException('Library version is not valid.');
        }
        $scope = (string)($manifest['scope'] ?? 'global');
        if ($scope !== 'global') {
            throw new RuntimeException('Only global library scope is currently supported.');
        }

        $styles = $this->normalizeEntries((array)($manifest['styles'] ?? []), 'style');
        $scripts = $this->normalizeEntries((array)($manifest['scripts'] ?? []), 'script');
        if ($styles === [] && $scripts === []) {
            throw new RuntimeException('Library manifest must declare at least one CSS or JavaScript entry point.');
        }

        return [
            'name' => $name,
            'version' => $version,
            'enabled' => (bool)($manifest['enabled'] ?? true),
            'scope' => $scope,
            'styles' => $styles,
            'scripts' => $scripts,
        ];
    }

    private function normalizeEntries(array $entries, string $kind): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $entry = is_string($entry) ? ['file' => $entry] : (is_array($entry) ? $entry : []);
            $file = AssetManager::validateRelativePath((string)($entry['file'] ?? ''));
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($kind === 'style' && $extension !== 'css') {
                throw new RuntimeException('Library style entry points must be CSS files.');
            }
            if ($kind === 'script' && !in_array($extension, ['js', 'mjs'], true)) {
                throw new RuntimeException('Library script entry points must be JS or MJS files.');
            }
            $item = ['file' => $file];
            foreach (['media', 'integrity', 'crossorigin'] as $attribute) {
                $value = trim((string)($entry[$attribute] ?? ''));
                if ($value !== '') {
                    $item[$attribute] = $this->validatedAttribute($attribute, $value);
                }
            }
            if ($kind === 'script') {
                $item['module'] = $extension === 'mjs' || (bool)($entry['module'] ?? false);
                $item['defer'] = (bool)($entry['defer'] ?? true);
                $item['async'] = (bool)($entry['async'] ?? false);
            }
            $normalized[] = $item;
        }
        return $normalized;
    }

    private function validatedAttribute(string $name, string $value): string
    {
        if ($name === 'integrity' && !preg_match('/^(sha256|sha384|sha512)-[A-Za-z0-9+\/=]+$/', $value)) {
            throw new RuntimeException('Library integrity value is invalid.');
        }
        if ($name === 'crossorigin' && !in_array($value, ['anonymous', 'use-credentials'], true)) {
            throw new RuntimeException('Library crossorigin value is invalid.');
        }
        if ($name === 'media' && !preg_match('/^[A-Za-z0-9 ():\/.,_-]{1,200}$/', $value)) {
            throw new RuntimeException('Library media query is invalid.');
        }
        return $value;
    }

    private function validateEntry(ZipArchive $zip, int $index, string $name): void
    {
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) || str_contains($normalized, "\0")) {
            throw new RuntimeException('Library ZIP contains an unsafe path.');
        }
        try {
            AssetManager::validateRelativePath(rtrim($normalized, '/'));
        } catch (RuntimeException) {
            throw new RuntimeException('Library ZIP contains an unsafe path.');
        }
        if (!str_ends_with($normalized, '/')) {
            $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new RuntimeException('Library ZIP contains an unsupported file type: ' . $extension);
            }
        }
        $opsys = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            $fileType = ($attributes >> 16) & 0170000;
            if ($fileType === 0120000) {
                throw new RuntimeException('Library ZIP cannot contain symbolic links.');
            }
        }
    }

    private function validateEntryPoints(string $root, array $manifest): void
    {
        foreach (array_merge((array)$manifest['styles'], (array)$manifest['scripts']) as $entry) {
            if (!is_file($root . '/' . (string)$entry['file'])) {
                throw new RuntimeException('Library entry point is missing: ' . (string)$entry['file']);
            }
        }
    }

    private function attributes(array $entry, array $allowed): string
    {
        $html = '';
        foreach ($allowed as $attribute) {
            if (isset($entry[$attribute]) && $entry[$attribute] !== '') {
                $html .= ' ' . $attribute . '="' . $this->e((string)$entry[$attribute]) . '"';
            }
        }
        return $html;
    }

    private function root(): string
    {
        return $this->paths->contentPath('assets/libraries');
    }

    private function libraryPath(string $name, string $version): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $name) || !preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,79}$/', $version)) {
            throw new RuntimeException('Invalid library identifier.');
        }
        return $this->root() . '/' . $name . '/' . $version;
    }

    private function manifestPath(string $name, string $version): string
    {
        return $this->libraryPath($name, $version) . '/library.json';
    }

    private function disableOtherVersions(string $name, string $activeVersion): void
    {
        foreach (glob($this->root() . '/' . $name . '/*/library.json') ?: [] as $file) {
            if (basename(dirname($file)) === $activeVersion) {
                continue;
            }
            $decoded = json_decode((string)file_get_contents($file), true);
            if (!is_array($decoded) || !($decoded['enabled'] ?? false)) {
                continue;
            }
            $decoded['enabled'] = false;
            try {
                $manifest = $this->normalizeManifest($decoded);
            } catch (RuntimeException) {
                continue;
            }
            if (file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Unable to update the previous library version.');
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_link($path)) {
                unlink($path);
            } else {
                is_dir($path) ? $this->removeDirectory($path) : unlink($path);
            }
        }
        rmdir($directory);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
