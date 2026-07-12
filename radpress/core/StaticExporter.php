<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use ZipArchive;

final class StaticExporter
{
    public function __construct(
        private readonly Paths $paths,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly array $site
    ) {
    }

    public function export(): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'ZipArchive is not available in this PHP installation.'];
        }

        $stamp = date('Ymd-His');
        $workDir = $this->paths->dataPath('tmp/export-' . $stamp);
        $zipPath = $this->paths->dataPath('export/site-static-' . $stamp . '.zip');
        $this->ensureDirectory(dirname($workDir));
        $this->ensureDirectory(dirname($zipPath));

        $this->writeSite($workDir);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Unable to create export ZIP.'];
        }

        foreach ($this->files($workDir) as $file) {
            $zip->addFile($file, ltrim(substr($file, strlen($workDir)), '/'));
        }
        $zip->close();

        $verification = $this->verifyPackage($zipPath);
        return [
            'ok' => true,
            'path' => $zipPath,
            'name' => basename($zipPath),
            'size' => is_file($zipPath) ? filesize($zipPath) : 0,
            'verification' => $verification,
        ];
    }

    public function status(): array
    {
        $exportDir = $this->paths->dataPath('export');
        $tmpDir = $this->paths->dataPath('tmp');
        $exports = [];
        foreach (glob($exportDir . '/site-static-*.zip') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $exports[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file) ?: 0,
                'modified' => filemtime($file) ?: 0,
            ];
        }
        usort($exports, static fn (array $a, array $b): int => (int)$b['modified'] <=> (int)$a['modified']);

        return [
            'zip_available' => class_exists(ZipArchive::class),
            'published_pages' => count($this->pages->allPublished()),
            'published_posts' => count($this->posts->allPublished()),
            'media_files' => count($this->mediaFiles()) + count($this->assetFiles()),
            'legacy_media_files' => count($this->mediaFiles()),
            'asset_files' => count($this->assetFiles()),
            'base_url' => (string)($this->site['base_url'] ?? ''),
            'export_path' => $exportDir,
            'export_writable' => is_dir($exportDir) ? is_writable($exportDir) : is_writable(dirname($exportDir)),
            'tmp_writable' => is_dir($tmpDir) ? is_writable($tmpDir) : is_writable(dirname($tmpDir)),
            'exports' => array_slice($exports, 0, 5),
        ];
    }

    public function exportFile(string $name): ?string
    {
        if (!preg_match('/^site-static-\d{8}-\d{6}\.zip$/', $name)) {
            return null;
        }

        $path = $this->paths->dataPath('export/' . $name);
        return is_file($path) ? $path : null;
    }

    public function verifyPackage(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'errors' => ['ZipArchive is not available.'], 'warnings' => []];
        }
        if (!is_file($zipPath)) {
            return ['ok' => false, 'errors' => ['Static export package was not created.'], 'warnings' => []];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'errors' => ['Static export package could not be opened.'], 'warnings' => []];
        }

        $entries = [];
        $errors = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->getNameIndex($index);
            $entries[$name] = true;
            if ($this->isUnsafeEntry($name)) {
                $errors[] = 'Unsafe archive entry: ' . $name;
            }
            if ($zip->statIndex($index) !== false && (int)($zip->statIndex($index)['size'] ?? 0) === 0) {
                $errors[] = 'Empty archive entry: ' . $name;
            }
        }
        $zip->close();

        foreach ($this->expectedEntries() as $entry) {
            if (!isset($entries[$entry])) {
                $errors[] = 'Missing expected archive entry: ' . $entry;
            }
        }

        $warnings = [];
        if (count($this->posts->allPublished()) === 0) {
            $warnings[] = 'No published posts were available for feed or blog item output.';
        }
        if (count($this->pages->allPublished()) === 0) {
            $warnings[] = 'No published pages were available for static page output.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'entries' => count($entries),
            'expected_entries' => count($this->expectedEntries()),
        ];
    }

    private function writeSite(string $workDir): void
    {
        foreach ($this->pages->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $target = $slug === 'home' ? 'index.html' : $slug . '/index.html';
            $this->writeHtml($workDir, $target, $this->renderTheme('page', ['page' => $page, 'title' => (string)($page['title'] ?? '')], '/' . ($slug === 'home' ? '' : $slug . '/')));
        }

        $posts = $this->posts->allPublished();
        foreach ($posts as $post) {
            $slug = (string)($post['slug'] ?? '');
            $this->writeHtml($workDir, 'blog/' . $slug . '/index.html', $this->renderTheme('post', ['post' => $post, 'title' => (string)($post['title'] ?? '')], '/blog/' . $slug . '/'));
        }
        $this->writeHtml($workDir, 'blog/index.html', $this->renderTheme('blog', ['posts' => $posts, 'title' => 'Blog'], '/blog/'));
        $this->writeHtml($workDir, 'archive/index.html', $this->renderTheme('archive', ['posts' => $posts, 'title' => 'Archive'], '/archive/'));
        $this->writeHtml($workDir, '404.html', $this->renderTheme('404', ['title' => 'Page Not Found'], '/404.html', 404));
        $this->write($workDir . '/sitemap.xml', $this->sitemap());
        $this->write($workDir . '/feed.xml', $this->feed());
        $this->writePublicAssets($workDir);
        $this->writeMedia($workDir);
        $this->writeAssets($workDir);
        $this->writeThemeAssets($workDir);
    }

    private function renderTheme(string $layout, array $data, string $requestUri, int $status = 200): string
    {
        $previousMode = $GLOBALS['bp_static_export_mode'] ?? null;
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $GLOBALS['bp_static_export_mode'] = true;
        $_SERVER['REQUEST_URI'] = $requestUri;
        try {
            return (new Theme($this->paths, $this->site))->render($layout, $data, $status, ['localized_assets' => false])->content();
        } finally {
            if ($previousMode === null) {
                unset($GLOBALS['bp_static_export_mode']);
            } else {
                $GLOBALS['bp_static_export_mode'] = $previousMode;
            }
            if ($previousUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousUri;
            }
        }
    }

    private function sitemap(): string
    {
        $baseUrl = rtrim((string)($this->site['base_url'] ?? ''), '/');
        $urls = [];
        foreach ($this->pages->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $urls[] = $baseUrl . ($slug === 'home' ? '/' : '/' . $slug . '/');
        }
        foreach ($this->posts->allPublished() as $post) {
            $urls[] = $baseUrl . '/blog/' . (string)($post['slug'] ?? '') . '/';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc></url>' . "\n";
        }
        return $xml . '</urlset>';
    }

    private function feed(): string
    {
        $baseUrl = rtrim((string)($this->site['base_url'] ?? ''), '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0"><channel>';
        $xml .= '<title>' . htmlspecialchars((string)($this->site['name'] ?? 'Batoi Press'), ENT_XML1) . '</title>';
        $xml .= '<link>' . htmlspecialchars($baseUrl . '/blog/', ENT_XML1) . '</link>';
        foreach ($this->posts->allPublished() as $post) {
            $xml .= '<item><title>' . htmlspecialchars((string)($post['title'] ?? ''), ENT_XML1) . '</title>';
            $xml .= '<link>' . htmlspecialchars($baseUrl . '/blog/' . (string)($post['slug'] ?? '') . '/', ENT_XML1) . '</link></item>';
        }
        return $xml . '</channel></rss>';
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);
        file_put_contents($path, $contents, LOCK_EX);
    }

    private function writeHtml(string $workDir, string $target, string $html): void
    {
        $directory = dirname($target);
        $depth = $directory === '.' ? 0 : count(array_filter(explode('/', $directory), static fn(string $part): bool => $part !== ''));
        $prefix = str_repeat('../', $depth);
        $html = preg_replace_callback(
            "/\\b(href|src|action)=([\"'])\\/(?!\\/)/i",
            static function (array $match) use ($prefix): string {
                $valuePrefix = $prefix !== '' ? $prefix : './';
                return $match[1] . '=' . $match[2] . $valuePrefix;
            },
            $html
        ) ?? $html;
        $this->write($workDir . '/' . $target, $html);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function files(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $files = array_merge($files, $this->files($path));
            } else {
                $files[] = $path;
            }
        }
        return $files;
    }

    private function expectedEntries(): array
    {
        $entries = ['blog/index.html', 'archive/index.html', '404.html', 'sitemap.xml', 'feed.xml'];
        foreach ($this->pages->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $entries[] = $slug === 'home' ? 'index.html' : $slug . '/index.html';
        }
        foreach ($this->posts->allPublished() as $post) {
            $entries[] = 'blog/' . (string)($post['slug'] ?? '') . '/index.html';
        }
        foreach ($this->mediaFiles() as $file) {
            $entries[] = 'media/' . basename($file);
        }
        foreach ($this->assetFiles() as $relative => $file) {
            $entries[] = 'assets/' . $relative;
        }
        $theme = new ThemeManager($this->paths);
        $slug = $theme->activeSlug($this->site);
        foreach ($theme->assetFiles($slug) as $relative => $file) {
            $entries[] = 'theme-assets/' . $slug . '/' . $relative;
        }

        return array_values(array_unique($entries));
    }

    private function isUnsafeEntry(string $entry): bool
    {
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '..')) {
            return true;
        }

        foreach (['admin/', 'radpress/', 'config/', 'data/', 'sessions/', 'backups/'] as $prefix) {
            if (str_starts_with($entry, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function writeMedia(string $workDir): void
    {
        foreach ($this->mediaFiles() as $file) {
            $target = $workDir . '/media/' . basename($file);
            $this->ensureDirectory(dirname($target));
            copy($file, $target);
        }
    }

    private function writeAssets(string $workDir): void
    {
        foreach ($this->assetFiles() as $relative => $file) {
            $target = $workDir . '/assets/' . $relative;
            $this->ensureDirectory(dirname($target));
            copy($file, $target);
        }
    }

    private function writeThemeAssets(string $workDir): void
    {
        $manager = new ThemeManager($this->paths);
        $slug = $manager->activeSlug($this->site);
        foreach ($manager->assetFiles($slug) as $relative => $file) {
            $target = $workDir . '/theme-assets/' . $slug . '/' . $relative;
            $this->ensureDirectory(dirname($target));
            copy($file, $target);
        }
    }

    private function writePublicAssets(string $workDir): void
    {
        $root = $this->paths->publicPath('assets');
        if (!is_dir($root)) {
            return;
        }
        foreach ($this->files($root) as $file) {
            $relative = ltrim(substr($file, strlen($root)), '/');
            $target = $workDir . '/assets/' . $relative;
            $this->ensureDirectory(dirname($target));
            copy($file, $target);
        }
    }

    private function mediaFiles(): array
    {
        $mediaDir = $this->paths->contentPath('media');
        if (!is_dir($mediaDir)) {
            return [];
        }

        $files = array_values(array_filter(glob($mediaDir . '/*') ?: [], 'is_file'));
        usort($files, static fn (string $a, string $b): int => strcasecmp(basename($a), basename($b)));
        return $files;
    }

    private function assetFiles(): array
    {
        $root = $this->paths->contentPath('assets');
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        foreach ($this->files($root) as $file) {
            $relative = ltrim(substr($file, strlen($root)), '/');
            if ($relative !== '' && basename($relative) !== '.gitkeep') {
                $files[$relative] = $file;
            }
        }
        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }
}
