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

    public function findByPath(string $path, string $postType = 'blog'): ?array
    {
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => Slug::normalize(rawurldecode($segment)),
            explode('/', trim($path, '/'))
        ), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return null;
        }

        $post = $this->findBySlug((string)end($segments));
        return $post !== null && $this->publicPath($post) === '/' . $postType . '/' . implode('/', $segments) ? $post : null;
    }

    public function allPublished(): array
    {
        return array_values(array_filter($this->all(), static fn (array $post): bool => PublicationState::isPublic($post)));
    }

    public function all(): array
    {
        return (new ContentTransaction($this->paths, $this->files))->read('post', fn (): array => $this->loadAll());
    }

    private function loadAll(): array
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

        usort($items, static fn (array $a, array $b): int => strcmp((string)($b['publish_at'] ?? $b['published_at'] ?? ''), (string)($a['publish_at'] ?? $a['published_at'] ?? '')));
        return $items;
    }

    public function save(array $input, string $actor): array
    {
        $prepared = $this->prepareSave($input, $actor);
        return (new ContentTransaction($this->paths, $this->files))->commit('post', $prepared);
    }

    /** Validate and normalize without changing content or snapshots. */
    public function prepareSave(array $input, string $actor): array
    {
        $requestedSlug = trim((string)($input['slug'] ?? ''));
        $slug = Slug::normalize($requestedSlug !== '' ? $requestedSlug : (string)($input['title'] ?? ''));
        if ($slug === '') {
            $slug = 'post-' . date('Ymd-His');
        }

        $originalSlug = Slug::normalize((string)($input['original_slug'] ?? ''));
        $now = date(DATE_ATOM);
        $existing = $originalSlug !== '' ? $this->findBySlug($originalSlug) : $this->findBySlug($slug);
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->findBySlug($slug) !== null) {
            throw new RuntimeException('A post with this slug already exists.');
        }
        $status = PublicationState::normalize($input['status'] ?? 'draft');
        $requestedPublishAt = $input['publish_at'] ?? $input['published_at'] ?? $existing['publish_at'] ?? $existing['published_at'] ?? '';
        $publishAt = PublicationState::normalizeDate($requestedPublishAt, 'Publish');
        if ($status === 'published' && $publishAt === '') {
            $publishAt = $now;
        }
        if ($status === 'scheduled' && $publishAt === '') {
            throw new RuntimeException('A scheduled post requires a publish date and time.');
        }
        $unpublishAt = PublicationState::normalizeDate($input['unpublish_at'] ?? $existing['unpublish_at'] ?? '', 'Unpublish');
        $reviewer = substr(trim((string)($input['reviewer'] ?? $existing['reviewer'] ?? '')), 0, 100);
        $workflowNote = substr(trim((string)($input['workflow_note'] ?? '')), 0, 500);
        $layout = (string)($input['layout'] ?? $existing['layout'] ?? 'full');
        $parentSlug = Slug::normalize((string)($input['parent_slug'] ?? $existing['parent_slug'] ?? ''));
        $this->validateParent($parentSlug, $slug, $originalSlug);
        $parent = $parentSlug !== '' ? $this->findBySlug($parentSlug) : null;
        $postType = trim((string)($input['post_type'] ?? $existing['post_type'] ?? $parent['post_type'] ?? 'blog'));
        if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $postType) || in_array($postType, ['admin','api','mcp','media','assets','theme-assets','shop','product','contact','archive','setup','install','oauth','.well-known'], true) || str_starts_with($postType, 'admin')) {
            throw new RuntimeException('Choose a lowercase post type path that is not a reserved application route.');
        }
        foreach ((new PageRepository($this->paths, $this->files, $this->html))->all() as $page) {
            if ($postType !== 'blog' && empty($page['parent_slug']) && ($page['slug'] ?? '') === $postType) throw new RuntimeException('This post type path is already used by a page.');
        }
        if ($postType !== 'blog' && file_exists($this->paths->publicPath($postType))) throw new RuntimeException('This post type path is already used by a public file or directory.');
        if ($parent !== null && ($parent['post_type'] ?? 'blog') !== $postType) throw new RuntimeException('Parent and child posts must use the same post type.');
        if ($existing !== null && ($existing['post_type'] ?? 'blog') !== $postType) {
            foreach ($this->all() as $child) {
                if (($child['parent_slug'] ?? '') === ($existing['slug'] ?? '')) throw new RuntimeException('Move child posts out of this hierarchy before changing its post type.');
            }
        }
        $meta = [
            'id' => (string)($existing['id'] ?? 'post_' . bin2hex(random_bytes(6))),
            'type' => 'post',
            'post_type' => $postType,
            'title' => trim((string)($input['title'] ?? 'Untitled Post')),
            'subtitle' => trim((string)($input['subtitle'] ?? '')),
            'slug' => $slug,
            'parent_slug' => $parentSlug,
            'status' => $status,
            'publish_at' => $publishAt,
            'unpublish_at' => $unpublishAt,
            'reviewer' => $reviewer,
            'workflow_history' => PublicationState::history($existing ?? [], $status, $reviewer, $workflowNote, $actor, $now),
            'template' => 'post',
            'author' => (string)($existing['author'] ?? $actor),
            'category' => trim((string)($input['category'] ?? 'General')),
            'featured_image' => $this->safeAssetUrl((string)($input['featured_image'] ?? '')),
            'featured_image_alt' => trim((string)($input['featured_image_alt'] ?? '')),
            'layout' => in_array($layout, ['full', 'sidebar-right', 'sidebar-left'], true) ? $layout : 'full',
            'tags' => array_values(array_filter(array_map('trim', explode(',', (string)($input['tags'] ?? ''))))),
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'published_at' => $publishAt,
            'seo_title' => trim((string)($input['seo_title'] ?? $input['title'] ?? '')),
            'seo_description' => trim((string)($input['seo_description'] ?? '')),
        ];

        return ['meta' => $meta, 'body' => $this->html->sanitize((string)($input['body'] ?? '')), 'original_slug' => $originalSlug, 'base_revision' => $existing === null ? null : \Batoi\Press\Application\ContentRevision::for($existing)];
    }

    public function publicPath(array|string $post): string
    {
        if (is_string($post)) {
            $post = $this->findBySlug($post) ?? ['slug' => Slug::normalize($post)];
        }
        $posts = [];
        foreach ($this->all() as $candidate) {
            $candidateSlug = (string)($candidate['slug'] ?? '');
            if ($candidateSlug !== '') {
                $posts[$candidateSlug] = $candidate;
            }
        }

        $segments = [];
        $current = $post;
        $visited = [];
        while (is_array($current)) {
            $slug = Slug::normalize((string)($current['slug'] ?? ''));
            if ($slug === '' || isset($visited[$slug])) {
                break;
            }
            $visited[$slug] = true;
            array_unshift($segments, rawurlencode($slug));
            $parent = Slug::normalize((string)($current['parent_slug'] ?? ''));
            if ($parent === '' || !isset($posts[$parent])) {
                break;
            }
            $current = $posts[$parent];
        }
        return '/' . rawurlencode((string)($post['post_type'] ?? 'blog')) . '/' . implode('/', $segments);
    }

    public function types(): array
    {
        $types = ['blog'];
        foreach ($this->all() as $post) {
            $type = (string)($post['post_type'] ?? 'blog');
            if (preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $type)) $types[] = $type;
        }
        return array_values(array_unique($types));
    }

    public function adjacentPublished(string $slug): array
    {
        $posts = $this->allPublished();
        foreach ($posts as $index => $post) {
            if ((string)($post['slug'] ?? '') !== $slug) {
                continue;
            }

            return [
                'previous' => $posts[$index + 1] ?? null,
                'next' => $index > 0 ? $posts[$index - 1] : null,
            ];
        }

        return ['previous' => null, 'next' => null];
    }

    private function safeAssetUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1 ? $value : '';
    }

    private function validateParent(string $parentSlug, string $slug, string $originalSlug): void
    {
        if ($parentSlug === '') {
            return;
        }
        if ($parentSlug === $slug || ($originalSlug !== '' && $parentSlug === $originalSlug)) {
            throw new RuntimeException('A post cannot be its own parent.');
        }
        $parent = $this->findBySlug($parentSlug);
        if ($parent === null) {
            throw new RuntimeException('Selected parent post was not found.');
        }
        $visited = [];
        while ($parent !== null) {
            $candidate = Slug::normalize((string)($parent['slug'] ?? ''));
            if ($candidate === $slug || ($originalSlug !== '' && $candidate === $originalSlug)) {
                throw new RuntimeException('Post hierarchy cannot contain a cycle.');
            }
            if ($candidate === '' || isset($visited[$candidate])) {
                throw new RuntimeException('Existing post hierarchy contains a cycle.');
            }
            $visited[$candidate] = true;
            $next = Slug::normalize((string)($parent['parent_slug'] ?? ''));
            $parent = $next !== '' ? $this->findBySlug($next) : null;
        }
    }

    private function updateChildParentReferences(string $oldSlug, string $newSlug, string $updatedAt): void
    {
        foreach (glob($this->paths->contentPath('posts/*'), GLOB_ONLYDIR) ?: [] as $dir) {
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
