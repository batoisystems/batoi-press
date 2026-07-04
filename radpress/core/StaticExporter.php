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
            'media_files' => count($this->mediaFiles()),
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
            $this->write($workDir . '/' . $target, $this->html((string)($page['title'] ?? ''), (string)($page['body'] ?? '')));
        }

        $posts = $this->posts->allPublished();
        $listing = '<h1>Blog</h1><ul>';
        foreach ($posts as $post) {
            $slug = (string)($post['slug'] ?? '');
            $listing .= '<li><a href="/blog/' . htmlspecialchars($slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '/">' . htmlspecialchars((string)($post['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
            $this->write($workDir . '/blog/' . $slug . '/index.html', $this->html((string)($post['title'] ?? ''), (string)($post['body'] ?? '')));
        }
        $listing .= '</ul>';
        $this->write($workDir . '/blog/index.html', $this->html('Blog', $listing));
        $this->write($workDir . '/sitemap.xml', $this->sitemap());
        $this->write($workDir . '/feed.xml', $this->feed());
        $this->writeMedia($workDir);
    }

    private function html(string $title, string $body): string
    {
        $siteName = htmlspecialchars((string)($this->site['name'] ?? 'Batoi Press'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $safeTitle . ' | ' . $siteName . '</title></head><body>' . $body . '</body></html>';
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
        $entries = ['blog/index.html', 'sitemap.xml', 'feed.xml'];
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
}
