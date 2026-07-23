<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Slug;
use RuntimeException;

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

    public function findByPath(string $path): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => Slug::normalize(rawurldecode($segment)),
            explode('/', $path)
        ), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return null;
        }

        $page = $this->findBySlug((string)end($segments));
        if ($page === null) {
            return null;
        }

        return trim($this->publicPath($page), '/') === implode('/', $segments) ? $page : null;
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
        $requestedSlug = trim((string)($input['slug'] ?? ''));
        $slug = Slug::normalize($requestedSlug !== '' ? $requestedSlug : (string)($input['title'] ?? ''));
        if ($slug === '') {
            $slug = 'page-' . date('Ymd-His');
        }

        $originalSlug = Slug::normalize((string)($input['original_slug'] ?? ''));
        $now = date(DATE_ATOM);
        $existing = $originalSlug !== '' ? $this->findBySlug($originalSlug) : $this->findBySlug($slug);
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->findBySlug($slug) !== null) {
            throw new RuntimeException('A page with this slug already exists.');
        }
        $status = in_array(($input['status'] ?? 'draft'), ['draft', 'published'], true) ? (string)($input['status'] ?? 'draft') : 'draft';
        $template = strtolower(trim((string)($input['template'] ?? $existing['template'] ?? 'page')));
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $template) !== 1) {
            $template = 'page';
        }
        $parentSlug = Slug::normalize((string)($input['parent_slug'] ?? $existing['parent_slug'] ?? ''));
        $this->validateParent($parentSlug, $slug, $originalSlug);
        $meta = [
            'id' => (string)($existing['id'] ?? 'pg_' . bin2hex(random_bytes(6))),
            'type' => 'page',
            'title' => trim((string)($input['title'] ?? 'Untitled Page')),
            'slug' => $slug,
            'parent_slug' => $parentSlug,
            'status' => $status,
            'template' => $template,
            'author' => (string)($existing['author'] ?? $actor),
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'seo_title' => trim((string)($input['seo_title'] ?? $input['title'] ?? '')),
            'seo_description' => trim((string)($input['seo_description'] ?? '')),
        ];

        $dir = $this->targetDir($originalSlug, $slug);
        $this->snapshot($dir, $slug);
        $this->files->writeJson($dir . '/meta.json', $meta);
        $this->files->write($dir . '/body.html', $this->html->sanitize((string)($input['body'] ?? '')));
        if ($originalSlug !== '' && $originalSlug !== $slug) {
            $this->updateChildParentReferences($originalSlug, $slug, $now);
        }

        return $meta;
    }

    public function publicPath(array|string $page): string
    {
        if (is_string($page)) {
            $page = $this->findBySlug($page) ?? ['slug' => Slug::normalize($page)];
        }

        $pages = [];
        foreach ($this->all() as $candidate) {
            $candidateSlug = (string)($candidate['slug'] ?? '');
            if ($candidateSlug !== '') {
                $pages[$candidateSlug] = $candidate;
            }
        }

        $segments = [];
        $current = $page;
        $visited = [];
        while (is_array($current)) {
            $slug = Slug::normalize((string)($current['slug'] ?? ''));
            if ($slug === '' || isset($visited[$slug])) {
                break;
            }
            $visited[$slug] = true;
            array_unshift($segments, rawurlencode($slug));
            $parent = Slug::normalize((string)($current['parent_slug'] ?? ''));
            if ($parent === '' || !isset($pages[$parent])) {
                break;
            }
            $current = $pages[$parent];
        }

        return '/' . implode('/', $segments);
    }

    private function targetDir(string $originalSlug, string $slug): string
    {
        $dir = $this->paths->contentPath('pages/' . $slug);
        if ($originalSlug === '' || $originalSlug === $slug) {
            return $dir;
        }

        $source = $this->paths->contentPath('pages/' . $originalSlug);
        if (!is_dir($source)) {
            return $dir;
        }

        $this->snapshot($source, $originalSlug);
        if (is_dir($dir)) {
            throw new RuntimeException('Unable to rename page because the target slug directory already exists.');
        }
        if (!rename($source, $dir)) {
            throw new RuntimeException('Unable to rename page content directory.');
        }

        return $dir;
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

    private function validateParent(string $parentSlug, string $slug, string $originalSlug): void
    {
        if ($parentSlug === '') {
            return;
        }
        if ($parentSlug === $slug || ($originalSlug !== '' && $parentSlug === $originalSlug)) {
            throw new RuntimeException('A page cannot be its own parent.');
        }

        $parent = $this->findBySlug($parentSlug);
        if ($parent === null) {
            throw new RuntimeException('Selected parent page was not found.');
        }

        $visited = [];
        while ($parent !== null) {
            $candidate = (string)($parent['slug'] ?? '');
            if ($candidate === $slug || ($originalSlug !== '' && $candidate === $originalSlug)) {
                throw new RuntimeException('Page hierarchy cannot contain a cycle.');
            }
            if ($candidate === '' || isset($visited[$candidate])) {
                throw new RuntimeException('Existing page hierarchy contains a cycle.');
            }
            $visited[$candidate] = true;
            $next = Slug::normalize((string)($parent['parent_slug'] ?? ''));
            $parent = $next !== '' ? $this->findBySlug($next) : null;
        }
    }

    private function updateChildParentReferences(string $oldSlug, string $newSlug, string $updatedAt): void
    {
        foreach (glob($this->paths->contentPath('pages/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $metaFile = $dir . '/meta.json';
            if (!is_file($metaFile)) {
                continue;
            }
            $meta = $this->files->readJson($metaFile);
            if (Slug::normalize((string)($meta['parent_slug'] ?? '')) !== $oldSlug) {
                continue;
            }
            $childSlug = Slug::normalize((string)($meta['slug'] ?? basename($dir)));
            $this->snapshot($dir, $childSlug);
            $meta['parent_slug'] = $newSlug;
            $meta['updated_at'] = $updatedAt;
            $this->files->writeJson($metaFile, $meta);
        }
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
