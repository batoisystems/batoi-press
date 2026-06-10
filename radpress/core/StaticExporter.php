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

        $this->writeSite($workDir);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Unable to create export ZIP.'];
        }

        foreach ($this->files($workDir) as $file) {
            $zip->addFile($file, ltrim(substr($file, strlen($workDir)), '/'));
        }
        $zip->close();

        return ['ok' => true, 'path' => $zipPath];
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
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents, LOCK_EX);
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
}
