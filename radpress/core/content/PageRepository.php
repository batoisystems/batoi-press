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
        return array_values(array_filter($this->all(), static fn (array $page): bool => PublicationState::isPublic($page)));
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
        $status = PublicationState::normalize($input['status'] ?? 'draft');
        $publishAt = PublicationState::normalizeDate($input['publish_at'] ?? $existing['publish_at'] ?? '', 'Publish');
        if ($status === 'published' && $publishAt === '') {
            $publishAt = (string)($existing['publish_at'] ?? $now);
        }
        if ($status === 'scheduled' && $publishAt === '') {
            throw new RuntimeException('A scheduled page requires a publish date and time.');
        }
        $unpublishAt = PublicationState::normalizeDate($input['unpublish_at'] ?? $existing['unpublish_at'] ?? '', 'Unpublish');
        $reviewer = substr(trim((string)($input['reviewer'] ?? $existing['reviewer'] ?? '')), 0, 100);
        $workflowNote = substr(trim((string)($input['workflow_note'] ?? '')), 0, 500);
        $template = strtolower(trim((string)($input['template'] ?? $existing['template'] ?? 'page')));
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $template) !== 1) {
            $template = 'page';
        }
        $parentSlug = Slug::normalize((string)($input['parent_slug'] ?? $existing['parent_slug'] ?? ''));
        $this->validateParent($parentSlug, $slug, $originalSlug);
        $latestPostsLimit = max(1, min(12, (int)($input['latest_posts_limit'] ?? $existing['latest_posts_limit'] ?? 3)));
        $customCss = (string)($input['custom_css'] ?? $existing['custom_css'] ?? '');
        $customJs = (string)($input['custom_js'] ?? $existing['custom_js'] ?? '');
        $this->validatePageAssets($customCss, $customJs);
        $body = (string)($input['body'] ?? $existing['body'] ?? '');
        $blockInput = $input['blocks'] ?? $existing['blocks'] ?? [];
        if (!array_key_exists('blocks', $input) && array_key_exists('body', $input) && $body !== (string)($existing['body'] ?? '') && is_array($blockInput) && $blockInput !== []) {
            if (count($blockInput) !== 1 || ($blockInput[0]['type'] ?? '') !== 'html') {
                throw new RuntimeException('This page contains multiple or dynamic blocks. Submit the blocks explicitly when changing its body.');
            }
            $blockInput[0]['body'] = $body;
        }
        $blocks = $this->normalizeBlocks($blockInput, $body);
        $body = implode("\n", array_map(static fn(array $block): string => in_array($block['type'], ['html', 'gallery'], true) ? $block['body'] : '', $blocks));
        $meta = [
            'id' => (string)($existing['id'] ?? 'pg_' . bin2hex(random_bytes(6))),
            'type' => 'page',
            'title' => trim((string)($input['title'] ?? 'Untitled Page')),
            'slug' => $slug,
            'parent_slug' => $parentSlug,
            'status' => $status,
            'publish_at' => $publishAt,
            'unpublish_at' => $unpublishAt,
            'reviewer' => $reviewer,
            'workflow_history' => PublicationState::history($existing ?? [], $status, $reviewer, $workflowNote, $actor, $now),
            'template' => $template,
            'author' => (string)($existing['author'] ?? $actor),
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'seo_title' => trim((string)($input['seo_title'] ?? $input['title'] ?? '')),
            'seo_description' => trim((string)($input['seo_description'] ?? '')),
            'show_latest_posts' => (string)($input['show_latest_posts'] ?? '0') === '1',
            'latest_posts_limit' => $latestPostsLimit,
            'custom_css' => $customCss,
            'custom_js' => $customJs,
            'blocks' => $blocks,
        ];

        $dir = $this->targetDir($originalSlug, $slug);
        $this->snapshot($dir, $slug);
        $this->files->writeJson($dir . '/meta.json', $meta);
        $this->files->write($dir . '/body.html', $body);
        if ($originalSlug !== '' && $originalSlug !== $slug) {
            $this->updateChildParentReferences($originalSlug, $slug, $now);
        }

        return $meta;
    }

    private function validatePageAssets(string $css, string $js): void
    {
        if (strlen($css) > 102400 || strlen($js) > 102400) {
            throw new RuntimeException('Custom CSS and JavaScript are limited to 100 KiB each.');
        }
        if (preg_match('#</style(?=[\s/>])#i', $css) === 1) {
            throw new RuntimeException('Custom CSS cannot contain a closing style tag.');
        }
        if (preg_match('#</script(?=[\s/>])#i', $js) === 1) {
            throw new RuntimeException('Custom JavaScript cannot contain a closing script tag.');
        }
    }

    private function normalizeBlocks(mixed $value, string $fallbackBody): array
    {
        $allowed = ['html', 'posts', 'gallery', 'products', 'widget'];
        if (!is_array($value) || count($value) > 30) {
            throw new RuntimeException('Page blocks must be an array of at most 30 items.');
        }
        $encoded = json_encode($value);
        if ($encoded === false || strlen($encoded) > 1048576 || strlen($fallbackBody) > 1048576) {
            throw new RuntimeException('Page content exceeds the 1 MiB content limit.');
        }
        $blocks = [];
        foreach (is_array($value) ? array_slice($value, 0, 30) : [] as $block) {
            if (!is_array($block)) continue;
            $type = in_array(($block['type'] ?? ''), $allowed, true) ? (string)$block['type'] : 'html';
            $body = in_array($type, ['html', 'gallery'], true) ? $this->html->sanitize((string)($block['body'] ?? '')) : '';
            $blocks[] = [
                'type' => $type,
                'title' => substr(trim((string)($block['title'] ?? '')), 0, 160),
                'body' => $body,
                'category' => substr(trim((string)($block['category'] ?? '')), 0, 100),
                'limit' => max(1, min(24, (int)($block['limit'] ?? 6))),
                'widget' => substr(trim((string)($block['widget'] ?? '')), 0, 160),
            ];
        }
        if ($blocks === []) {
            $blocks[] = ['type' => 'html', 'title' => '', 'body' => $this->html->sanitize($fallbackBody), 'category' => '', 'limit' => 6, 'widget' => ''];
        }
        return $blocks;
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
