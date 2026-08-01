<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\FileStore;

final class SiteReadService
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly MenuRepository $menus
    ) {
    }

    public static function create(Config $config, PageRepository $pages, PostRepository $posts): self
    {
        return new self($config, $pages, $posts, new MenuRepository($config->paths(), new FileStore()));
    }

    public function discovery(): array
    {
        $update = $this->readJson($this->config->paths()->configPath('update.json'));
        return [
            'name' => 'Batoi Press',
            'version' => (string)($update['current_version'] ?? 'unknown'),
            'api_version' => 'v2',
            'mcp_protocol_version' => '2025-11-25',
            'capabilities' => ['site_read', 'content_read', 'taxonomy_read', 'menu_read', 'search', 'fetch'],
        ];
    }

    public function site(): array
    {
        $site = $this->config->site();
        $data = [
            'id' => 'site',
            'name' => (string)($site['name'] ?? 'Batoi Press'),
            'tagline' => (string)($site['tagline'] ?? ''),
            'url' => $this->baseUrl(),
            'locale' => (string)($site['locale'] ?? 'en'),
            'timezone' => (string)($site['timezone'] ?? 'UTC'),
            'homepage' => (string)($site['homepage'] ?? 'home'),
            'theme' => (string)($site['theme'] ?? 'default'),
        ];
        return $data + ['revision' => $this->revision($data)];
    }

    public function listPages(array $filters = []): array
    {
        return $this->paginate($this->filterContent($this->pages->all(), $filters, 'page'), $filters);
    }

    public function listPosts(array $filters = []): array
    {
        return $this->paginate($this->filterContent($this->posts->all(), $filters, 'post'), $filters);
    }

    public function page(string $slugOrId): ?array
    {
        foreach ($this->pages->all() as $page) {
            if (($page['slug'] ?? '') === $slugOrId || ($page['id'] ?? '') === $slugOrId) {
                return $this->contentRecord($page, 'page', true);
            }
        }
        return null;
    }

    public function post(string $slugOrId): ?array
    {
        foreach ($this->posts->all() as $post) {
            if (($post['slug'] ?? '') === $slugOrId || ($post['id'] ?? '') === $slugOrId) {
                return $this->contentRecord($post, 'post', true);
            }
        }
        return null;
    }

    public function taxonomies(): array
    {
        $categories = [];
        $tags = [];
        foreach ($this->posts->all() as $post) {
            $public = PublicationState::isPublic($post);
            $category = trim((string)($post['category'] ?? ''));
            if ($category !== '') {
                $key = $this->lower($category);
                $categories[$key] ??= ['name' => $category, 'slug' => $this->taxonomySlug($category), 'count' => 0, 'public_count' => 0];
                $categories[$key]['count']++;
                $categories[$key]['public_count'] += $public ? 1 : 0;
            }
            foreach (array_unique(array_filter(array_map('trim', (array)($post['tags'] ?? [])))) as $tag) {
                $key = $this->lower($tag);
                $tags[$key] ??= ['name' => $tag, 'slug' => $this->taxonomySlug($tag), 'count' => 0, 'public_count' => 0];
                $tags[$key]['count']++;
                $tags[$key]['public_count'] += $public ? 1 : 0;
            }
        }
        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
        return ['categories' => array_values($categories), 'tags' => array_values($tags)];
    }

    public function listMedia(array $filters = []): array
    {
        $query = $this->lower(trim((string)($filters['q'] ?? '')));
        $type = strtolower(trim((string)($filters['type'] ?? '')));
        $records = [];
        foreach ((new AssetManager($this->config->paths()))->all() as $asset) {
            if ($query !== '' && !str_contains($this->lower((string)($asset['relative'] ?? '')), $query)) continue;
            if ($type !== '' && ($asset['type'] ?? '') !== $type) continue;
            $records[] = $this->mediaRecord($asset);
        }
        return $this->paginate($records, $filters);
    }

    public function media(string $id): ?array
    {
        foreach ((new AssetManager($this->config->paths()))->all() as $asset) {
            $record = $this->mediaRecord($asset);
            if ($record['id'] === $id) return $record;
        }
        return null;
    }

    public function contentHealth(string $identifier = ''): array
    {
        $records = [];
        foreach ([['type' => 'page', 'items' => $this->pages->all()], ['type' => 'post', 'items' => $this->posts->all()]] as $group) {
            foreach ($group['items'] as $item) {
                if ($identifier !== '' && ($item['id'] ?? '') !== $identifier && ($item['slug'] ?? '') !== $identifier) continue;
                $issues = [];
                if (trim((string)($item['seo_description'] ?? '')) === '') $issues[] = ['code' => 'missing_seo_description', 'severity' => 'warning', 'message' => 'Add a concise search description.'];
                if (preg_match('/<img\b(?![^>]*\balt\s*=)[^>]*>/i', (string)($item['body'] ?? '')) === 1) $issues[] = ['code' => 'missing_image_alt', 'severity' => 'error', 'message' => 'One or more images need an alt attribute.'];
                if (!preg_match('/<h[12]\b/i', (string)($item['body'] ?? ''))) $issues[] = ['code' => 'missing_primary_heading', 'severity' => 'warning', 'message' => 'Add an H1 or H2 heading to establish structure.'];
                $updated = strtotime((string)($item['updated_at'] ?? ''));
                if ($updated !== false && $updated < time() - 31536000) $issues[] = ['code' => 'stale_content', 'severity' => 'info', 'message' => 'This content has not been updated in more than one year.'];
                $records[] = [
                    'id' => (string)($item['id'] ?? ''), 'type' => (string)$group['type'], 'slug' => (string)($item['slug'] ?? ''),
                    'title' => (string)($item['title'] ?? ''), 'status' => (string)($item['status'] ?? 'draft'), 'issues' => $issues,
                    'score' => max(0, 100 - count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error')) * 30 - count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'warning')) * 15 - count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'info')) * 5),
                ];
                if ($identifier !== '') return ['data' => $records, 'meta' => ['count' => count($records)]];
                if (count($records) >= 100) break 2;
            }
        }
        return ['data' => $records, 'meta' => ['count' => count($records), 'truncated' => count($records) >= 100]];
    }

    public function listMenus(): array
    {
        $records = [];
        foreach (glob($this->config->paths()->contentPath('menus/*.json')) ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!is_string($name) || $name === '') {
                continue;
            }
            $menu = $this->menus->load($name);
            $records[] = [
                'id' => (string)($menu['id'] ?? 'menu_' . $name),
                'key' => $name,
                'name' => (string)($menu['name'] ?? $name),
                'location' => (string)($menu['location'] ?? ''),
                'revision' => (int)($menu['revision'] ?? 0),
                'item_count' => count((array)($menu['items'] ?? [])),
            ];
        }
        return $records;
    }

    public function menu(string $nameOrId): ?array
    {
        foreach ($this->listMenus() as $summary) {
            $name = (string)($summary['key'] ?? '');
            if ($nameOrId !== $name && $nameOrId !== $summary['id']) {
                continue;
            }
            $menu = $this->menus->load($name);
            unset($menu['updated_by']);
            return $menu;
        }
        return null;
    }

    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $needle = $this->lower($query);
        $results = [];
        foreach ([['type' => 'page', 'items' => $this->pages->all()], ['type' => 'post', 'items' => $this->posts->all()]] as $group) {
            foreach ($group['items'] as $item) {
                $haystack = $this->lower(implode(' ', [
                    (string)($item['title'] ?? ''),
                    (string)($item['subtitle'] ?? ''),
                    (string)($item['seo_description'] ?? ''),
                    strip_tags((string)($item['body'] ?? '')),
                ]));
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
                $record = $this->contentRecord($item, (string)$group['type'], false);
                $results[] = [
                    'id' => (string)$record['id'],
                    'title' => (string)$record['title'],
                    'url' => (string)$record['url'],
                    'type' => (string)$group['type'],
                    'status' => (string)$record['status'],
                ];
                if (count($results) >= max(1, min(50, $limit))) {
                    return $results;
                }
            }
        }
        return $results;
    }

    public function fetch(string $id): ?array
    {
        $record = $this->page($id) ?? $this->post($id);
        if ($record === null) {
            return null;
        }
        return [
            'id' => (string)$record['id'],
            'title' => (string)$record['title'],
            'text' => $this->plainText((string)($record['body'] ?? '')),
            'url' => (string)$record['url'],
            'metadata' => [
                'type' => (string)$record['type'],
                'status' => (string)$record['status'],
                'slug' => (string)$record['slug'],
                'revision' => (string)$record['revision'],
            ],
        ];
    }

    private function filterContent(array $items, array $filters, string $type): array
    {
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        $query = $this->lower(trim((string)($filters['q'] ?? '')));
        $records = [];
        foreach ($items as $item) {
            if ($status !== '' && ($item['status'] ?? '') !== $status) {
                continue;
            }
            if ($query !== '' && !str_contains($this->lower((string)($item['title'] ?? '') . ' ' . (string)($item['slug'] ?? '')), $query)) {
                continue;
            }
            $records[] = $this->contentRecord($item, $type, false);
        }
        return $records;
    }

    private function contentRecord(array $item, string $type, bool $includeBody): array
    {
        $slug = (string)($item['slug'] ?? '');
        $path = $type === 'post' ? '/blog/' . rawurlencode($slug) : $this->pages->publicPath($item);
        $data = [
            'id' => (string)($item['id'] ?? $type . '_' . $slug),
            'type' => $type,
            'title' => (string)($item['title'] ?? ''),
            'slug' => $slug,
            'status' => (string)($item['status'] ?? 'draft'),
            'url' => $this->absoluteUrl($path),
            'updated_at' => (string)($item['updated_at'] ?? ''),
            'publish_at' => (string)($item['publish_at'] ?? $item['published_at'] ?? ''),
            'unpublish_at' => (string)($item['unpublish_at'] ?? ''),
            'reviewer' => (string)($item['reviewer'] ?? ''),
            'public' => PublicationState::isPublic($item),
        ];
        if ($type === 'page') {
            $data['parent_slug'] = (string)($item['parent_slug'] ?? '');
            $data['template'] = (string)($item['template'] ?? 'page');
        } else {
            $data['subtitle'] = (string)($item['subtitle'] ?? '');
            $data['category'] = (string)($item['category'] ?? '');
            $data['tags'] = array_values(is_array($item['tags'] ?? null) ? $item['tags'] : []);
            $data['published_at'] = (string)($item['published_at'] ?? '');
        }
        if ($includeBody) {
            $data['body'] = (string)($item['body'] ?? '');
            $data['seo_title'] = (string)($item['seo_title'] ?? '');
            $data['seo_description'] = (string)($item['seo_description'] ?? '');
        }
        return $data + ['revision' => $this->revision($item)];
    }

    private function paginate(array $items, array $filters): array
    {
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $offset = $this->decodeCursor((string)($filters['cursor'] ?? ''));
        $data = array_slice($items, $offset, $limit);
        $next = $offset + count($data);
        return [
            'data' => $data,
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $next < count($items) ? $this->encodeCursor($next) : null,
                'has_more' => $next < count($items),
            ],
        ];
    }

    private function taxonomySlug(string $value): string
    {
        $value = $this->lower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function mediaRecord(array $asset): array
    {
        $storage = (string)($asset['storage'] ?? 'assets');
        $relative = (string)($asset['relative'] ?? '');
        return [
            'id' => 'asset_' . substr(hash('sha256', $storage . ':' . $relative), 0, 24),
            'name' => (string)($asset['name'] ?? basename($relative)),
            'storage' => $storage,
            'relative' => $relative,
            'url' => $this->absoluteUrl((string)($asset['url'] ?? '')),
            'type' => (string)($asset['type'] ?? 'documents'),
            'kind' => (string)($asset['kind'] ?? 'Document asset'),
            'mime_type' => AssetManager::mimeType($relative),
            'size' => max(0, (int)($asset['size'] ?? 0)),
            'modified_at' => !empty($asset['modified']) ? date(DATE_ATOM, (int)$asset['modified']) : '',
        ];
    }

    private function revision(array $record): string
    {
        return ContentRevision::for($record);
    }

    private function baseUrl(): string
    {
        return rtrim((string)($this->config->site()['base_url'] ?? ''), '/');
    }

    private function absoluteUrl(string $path): string
    {
        $base = $this->baseUrl();
        return $base !== '' ? $base . '/' . ltrim($path, '/') : $path;
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('offset:' . $offset), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): int
    {
        if ($cursor === '') {
            return 0;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        return is_string($decoded) && preg_match('/^offset:([0-9]{1,9})$/D', $decoded, $matches) === 1
            ? (int)$matches[1]
            : 0;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim(strlen($text) > 100000 ? substr($text, 0, 100000) : $text);
    }

    private function readJson(string $path): array
    {
        return is_file($path) ? (new FileStore())->readJson($path) : [];
    }
}
