<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Slug;
use RuntimeException;

final class PostRepository
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files,
        private readonly HtmlContent $html
    ) {
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->all() as $post) {
            if (($post['slug'] ?? '') === $slug) {
                return $post;
            }
        }

        return null;
    }

    public function allPublished(): array
    {
        return array_values(array_filter($this->all(), static fn (array $post): bool => ($post['status'] ?? '') === 'published'));
    }

    public function all(): array
    {
        $base = $this->paths->contentPath('posts');
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

        usort($items, static fn (array $a, array $b): int => strcmp((string)($b['published_at'] ?? ''), (string)($a['published_at'] ?? '')));
        return $items;
    }

    public function save(array $input, string $actor): array
    {
        $slug = Slug::normalize((string)($input['slug'] ?? $input['title'] ?? ''));
        if ($slug === '') {
            $slug = 'post-' . date('Ymd-His');
        }

        $originalSlug = Slug::normalize((string)($input['original_slug'] ?? ''));
        $now = date(DATE_ATOM);
        $existing = $originalSlug !== '' ? $this->findBySlug($originalSlug) : $this->findBySlug($slug);
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->findBySlug($slug) !== null) {
            throw new RuntimeException('A post with this slug already exists.');
        }
        $status = in_array(($input['status'] ?? 'draft'), ['draft', 'published'], true) ? (string)($input['status'] ?? 'draft') : 'draft';
        $meta = [
            'id' => (string)($existing['id'] ?? 'post_' . bin2hex(random_bytes(6))),
            'type' => 'post',
            'title' => trim((string)($input['title'] ?? 'Untitled Post')),
            'slug' => $slug,
            'status' => $status,
            'template' => 'post',
            'author' => (string)($existing['author'] ?? $actor),
            'category' => trim((string)($input['category'] ?? 'General')),
            'tags' => array_values(array_filter(array_map('trim', explode(',', (string)($input['tags'] ?? ''))))),
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'published_at' => $status === 'published' ? (string)($existing['published_at'] ?? $now) : '',
            'seo_title' => trim((string)($input['seo_title'] ?? $input['title'] ?? '')),
            'seo_description' => trim((string)($input['seo_description'] ?? '')),
        ];

        $dir = $this->targetDir($originalSlug, $slug);
        $this->snapshot($dir, $slug);
        $this->files->writeJson($dir . '/meta.json', $meta);
        $this->files->write($dir . '/body.html', $this->html->sanitize((string)($input['body'] ?? '')));

        return $meta;
    }

    private function targetDir(string $originalSlug, string $slug): string
    {
        $dir = $this->paths->contentPath('posts/' . $slug);
        if ($originalSlug === '' || $originalSlug === $slug) {
            return $dir;
        }

        $source = $this->paths->contentPath('posts/' . $originalSlug);
        if (!is_dir($source)) {
            return $dir;
        }

        $this->snapshot($source, $originalSlug);
        if (is_dir($dir)) {
            throw new RuntimeException('Unable to rename post because the target slug directory already exists.');
        }
        if (!rename($source, $dir)) {
            throw new RuntimeException('Unable to rename post content directory.');
        }

        return $dir;
    }

    private function snapshot(string $dir, string $slug): void
    {
        if (!is_file($dir . '/meta.json') && !is_file($dir . '/body.html')) {
            return;
        }

        $target = $this->paths->dataPath('versions/posts/' . $slug . '/' . date('Ymd-His'));
        if (is_file($dir . '/meta.json')) {
            $this->files->write($target . '/meta.json', $this->files->read($dir . '/meta.json'));
        }
        if (is_file($dir . '/body.html')) {
            $this->files->write($target . '/body.html', $this->files->read($dir . '/body.html'));
        }
    }
}
