<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;

final class ThemeManager
{
    private const REQUIRED_LAYOUTS = [
        'layouts/base.php',
        'layouts/page.php',
        'layouts/post.php',
        'layouts/blog.php',
        'layouts/archive.php',
        'layouts/404.php',
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files = new FileStore()
    ) {
    }

    public function activeSlug(array $site): string
    {
        $slug = $this->sanitizeSlug((string)($site['theme'] ?? 'default'));
        return $slug !== '' && $this->exists($slug) ? $slug : 'default';
    }

    public function exists(string $slug): bool
    {
        $slug = $this->sanitizeSlug($slug);
        return $slug !== ''
            && is_dir($this->paths->themePath($slug))
            && is_file($this->paths->themePath($slug . '/theme.json'));
    }

    public function manifest(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '' || !$this->exists($slug)) {
            throw new RuntimeException('Theme not found.');
        }

        $raw = $this->files->readJson($this->paths->themePath($slug . '/theme.json'));
        return $this->normalizeManifest($slug, $raw);
    }

    public function normalizeManifest(string $slug, array $raw): array
    {
        $slug = $this->sanitizeSlug((string)($raw['slug'] ?? $slug));
        if ($slug === '') {
            throw new RuntimeException('Theme manifest has an invalid slug.');
        }

        $schema = (int)($raw['schema'] ?? 1);
        if ($schema !== 1) {
            throw new RuntimeException('Unsupported theme manifest schema.');
        }

        $name = trim((string)($raw['name'] ?? ''));
        $version = trim((string)($raw['version'] ?? ''));
        $author = trim((string)($raw['author'] ?? ''));
        if ($name === '' || $version === '' || $author === '') {
            throw new RuntimeException('Theme manifest requires name, version, and author.');
        }
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException('Theme manifest version must use semantic version syntax.');
        }

        $supports = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($raw['supports'] ?? [])
        ), static fn(string $value): bool => preg_match('/^[a-z][a-z0-9_-]*$/', $value) === 1)));

        $assets = (array)($raw['assets'] ?? []);
        return [
            'schema' => 1,
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'author' => $author,
            'supports' => $supports,
            'assets' => [
                'styles' => $this->normalizeEntries((array)($assets['styles'] ?? []), 'style'),
                'scripts' => $this->normalizeEntries((array)($assets['scripts'] ?? []), 'script'),
            ],
        ];
    }

    public function validate(string $slug): array
    {
        $errors = [];
        try {
            $manifest = $this->manifest($slug);
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'errors' => [$exception->getMessage()], 'manifest' => null];
        }

        foreach (self::REQUIRED_LAYOUTS as $layout) {
            if (!is_file($this->paths->themePath($slug . '/' . $layout))) {
                $errors[] = 'Missing required file: ' . $layout;
            }
        }
        foreach (['styles', 'scripts'] as $group) {
            foreach ($manifest['assets'][$group] as $entry) {
                if ($this->resolveAsset($slug, (string)$entry['file']) === null) {
                    $errors[] = 'Missing declared theme asset: assets/' . (string)$entry['file'];
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'manifest' => $manifest];
    }

    public function context(string $slug): array
    {
        $validation = $this->validate($slug);
        $manifest = is_array($validation['manifest'] ?? null) ? $validation['manifest'] : [
            'schema' => 1,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'version' => '0.0.0',
            'author' => 'Unknown',
            'supports' => [],
            'assets' => ['styles' => [], 'scripts' => []],
        ];
        return $manifest + ['valid' => (bool)$validation['ok'], 'errors' => (array)$validation['errors']];
    }

    public function resolveAsset(string $slug, string $relative): ?string
    {
        $slug = $this->sanitizeSlug($slug);
        try {
            $relative = AssetManager::validateRelativePath($relative);
        } catch (RuntimeException) {
            return null;
        }
        if ($slug === '') {
            return null;
        }

        $root = $this->paths->themePath($slug . '/assets');
        $file = $root . '/' . $relative;
        if (!is_file($file)) {
            return null;
        }
        $realRoot = realpath($root);
        $realFile = realpath($file);
        return $realRoot !== false && $realFile !== false && str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)
            ? $realFile
            : null;
    }

    public function assetUrl(string $slug, string $relative, bool $localized = true): string
    {
        $relative = AssetManager::validateRelativePath($relative);
        $url = '/theme-assets/' . rawurlencode($this->sanitizeSlug($slug)) . '/'
            . implode('/', array_map('rawurlencode', explode('/', $relative)));
        return $localized && function_exists('bp_url') ? \bp_url($url) : $url;
    }

    public function tags(string $slug, string $position, bool $localized = true): string
    {
        $context = $this->context($slug);
        if (!($context['valid'] ?? false)) {
            return '';
        }

        $html = '';
        if ($position === 'head') {
            foreach ((array)$context['assets']['styles'] as $entry) {
                $media = (string)($entry['media'] ?? '');
                $html .= '<link rel="stylesheet" href="' . htmlspecialchars($this->assetUrl($slug, (string)$entry['file'], $localized), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ($media !== '' ? ' media="' . htmlspecialchars($media, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '') . '>' . "\n";
            }
            return $html;
        }

        foreach ((array)$context['assets']['scripts'] as $entry) {
            $html .= '<script src="' . htmlspecialchars($this->assetUrl($slug, (string)$entry['file'], $localized), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                . (($entry['module'] ?? false) ? ' type="module"' : '')
                . (($entry['defer'] ?? false) ? ' defer' : '')
                . (($entry['async'] ?? false) ? ' async' : '') . '></script>' . "\n";
        }
        return $html;
    }

    public function assetFiles(string $slug): array
    {
        $root = $this->paths->themePath($this->sanitizeSlug($slug) . '/assets');
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
            $files[$relative] = $file->getPathname();
        }
        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    public function sanitizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?: '';
        return trim($slug, '-_');
    }

    private function normalizeEntries(array $entries, string $type): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $entry = is_string($entry) ? ['file' => $entry] : (is_array($entry) ? $entry : []);
            $file = AssetManager::validateRelativePath((string)($entry['file'] ?? ''));
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (($type === 'style' && $extension !== 'css') || ($type === 'script' && !in_array($extension, ['js', 'mjs'], true))) {
                throw new RuntimeException('Theme manifest declares an unsupported ' . $type . ' entry.');
            }
            $item = ['file' => $file];
            if ($type === 'style') {
                $media = trim((string)($entry['media'] ?? ''));
                if ($media !== '' && preg_match('/^[a-zA-Z0-9 ():\-.,\/]+$/', $media) !== 1) {
                    throw new RuntimeException('Theme stylesheet media value is invalid.');
                }
                $item['media'] = $media;
            } else {
                $item['module'] = $extension === 'mjs' || (bool)($entry['module'] ?? false);
                $item['defer'] = (bool)($entry['defer'] ?? true);
                $item['async'] = (bool)($entry['async'] ?? false);
            }
            $normalized[] = $item;
        }
        return $normalized;
    }
}
