<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Slug;

final class PageRepository
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files,
        private readonly HtmlContent $html
    ) {
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->all() as $page) {
            if (($page['slug'] ?? '') === $slug) {
                return $page;
            }
        }

        return null;
    }

    public function allPublished(): array
    {
        return array_values(array_filter($this->all(), static fn (array $page): bool => ($page['status'] ?? '') === 'published'));
    }

    public function all(): array
    {
        return $this->loadFrom($this->paths->contentPath('pages'));
    }

    public function save(array $input, string $actor): array
    {
        $slug = Slug::normalize((string)($input['slug'] ?? $input['title'] ?? ''));
        if ($slug === '') {
            $slug = 'page-' . date('Ymd-His');
        }

        $now = date(DATE_ATOM);
        $existing = $this->findBySlug($slug);
        $meta = [
            'id' => (string)($existing['id'] ?? 'pg_' . bin2hex(random_bytes(6))),
            'type' => 'page',
            'title' => trim((string)($input['title'] ?? 'Untitled Page')),
            'slug' => $slug,
            'status' => in_array(($input['status'] ?? 'draft'), ['draft', 'published'], true) ? (string)$input['status'] : 'draft',
            'template' => 'page',
            'author' => (string)($existing['author'] ?? $actor),
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'seo_title' => trim((string)($input['seo_title'] ?? $input['title'] ?? '')),
            'seo_description' => trim((string)($input['seo_description'] ?? '')),
        ];

        $dir = $this->paths->contentPath('pages/' . $slug);
        $this->snapshot($dir, $slug);
        $this->files->writeJson($dir . '/meta.json', $meta);
        $this->files->write($dir . '/body.html', $this->html->sanitize((string)($input['body'] ?? '')));

        return $meta;
    }

    private function loadFrom(string $base): array
    {
        if (!is_dir($base)) {
            return [];
        }

        $items = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $metaFile = $dir . '/meta.json';
            $bodyFile = $dir . '/body.html';
            if (!is_file($metaFile) || !is_file($bodyFile)) {
                continue;
            }

            $meta = $this->files->readJson($metaFile);
            $meta['body'] = $this->html->sanitize($this->files->read($bodyFile));
            $items[] = $meta;
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
        return $items;
    }

    private function snapshot(string $dir, string $slug): void
    {
        if (!is_file($dir . '/meta.json') && !is_file($dir . '/body.html')) {
            return;
        }

        $target = $this->paths->dataPath('versions/pages/' . $slug . '/' . date('Ymd-His'));
        if (is_file($dir . '/meta.json')) {
            $this->files->write($target . '/meta.json', $this->files->read($dir . '/meta.json'));
        }
        if (is_file($dir . '/body.html')) {
            $this->files->write($target . '/body.html', $this->files->read($dir . '/body.html'));
        }
    }
}
