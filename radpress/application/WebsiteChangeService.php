<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Content\MenuValidationException;
use Batoi\Press\Core\Config;

/** Typed preparation only. Publication must go through the proposal approval service. */
final class WebsiteChangeService
{
    public function __construct(private readonly MenuRepository $menus, private readonly PageRepository $pages, private readonly PostRepository $posts, private readonly Config $config) {}

    public function prepareMenu(string $key, array $changes, int $expectedRevision, string $actor): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) || $expectedRevision < 0) $this->invalid('Invalid menu key or revision.');
        if (array_diff(array_keys($changes), ['name', 'location', 'items']) !== []) $this->invalid('Only menu name, location and items may change.');
        foreach (['name' => 80, 'location' => 64] as $field => $maximum) {
            if (array_key_exists($field, $changes) && (!is_string($changes[$field]) || strlen($changes[$field]) > $maximum || trim($changes[$field]) === '')) $this->invalid('Invalid menu ' . $field . '.');
        }
        if (isset($changes['location']) && !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $changes['location'])) $this->invalid('Invalid menu location.');
        $before = $this->menus->load($key);
        $input = array_replace($before, $changes);
        if (!is_array($input['items']) || !array_is_list($input['items']) || count($input['items']) > MenuRepository::MAX_ITEMS) $this->invalid('Menu items must be a list of at most 100 items.');
        foreach ($input['items'] as &$item) {
            if (!is_array($item) || array_diff(array_keys($item), ['id', 'type', 'label', 'url', 'parent_id', 'parent', 'presentation', 'description', 'column', 'target', 'enabled']) !== []) $this->invalid('Unsupported menu item fields.');
            foreach ($item as $field => $value) {
                $valid = match ($field) {
                    'enabled' => is_bool($value),
                    'column' => is_int($value) && $value >= 1 && $value <= MenuRepository::MAX_MEGA_COLUMNS,
                    'parent_id' => $value === null || (is_string($value) && strlen($value) <= 67),
                    default => is_string($value) && strlen($value) <= match ($field) { 'url' => 2048, 'description' => 180, 'label' => 120, default => 120 },
                };
                if (!$valid) $this->invalid('Invalid menu item ' . $field . '.');
            }
            if (isset($item['target']) && !in_array($item['target'], ['_self', '_blank'], true)) $this->invalid('Invalid link target.');
            if (isset($item['presentation']) && !in_array($item['presentation'], MenuRepository::PRESENTATIONS, true)) $this->invalid('Invalid menu presentation.');
            // Parent URL is an output-only compatibility field; the repository derives it from IDs.
            unset($item['parent']);
        }
        unset($item);
        try {
            $after = $this->menus->prepareSave($input, $actor, $expectedRevision, $key);
        } catch (MenuConflictException $error) {
            throw new ContentMutationException('The menu changed. Read it again before proposing changes.', 'revision_conflict', 409);
        } catch (MenuValidationException $error) {
            throw new ContentMutationException($error->getMessage(), 'validation_failed', 422);
        }
        $this->validateReferences($after['items']);
        return ['before' => $before, 'after' => $after, 'base_revision' => $expectedRevision, 'key' => $key];
    }

    private function validateReferences(array $items): void
    {
        $paths = ['page' => [], 'post' => [], 'archive' => []];
        foreach ($this->pages->allPublished() as $page) {
            $paths['page'][$this->pages->publicPath($page)] = true;
            if (($page['slug'] ?? '') === ($this->config->site()['homepage'] ?? 'home')) $paths['page']['/'] = true;
        }
        foreach ($this->posts->allPublished() as $post) $paths['post'][$this->posts->publicPath($post)] = true;
        foreach ($this->posts->types() as $type) $paths['archive']['/' . $type] = true;
        $known = $paths['page'] + $paths['post'] + $paths['archive'];
        foreach ($items as $item) {
            if (in_array($item['type'], ['heading', 'separator'], true)) continue;
            $url = $item['url'];
            $decoded = rawurldecode($url);
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || str_starts_with($decoded, '//')) $this->invalid('Menu URLs cannot contain whitespace, control characters, backslashes or protocol-relative destinations.');
            $parts = parse_url($url);
            if ($parts === false || isset($parts['user']) || isset($parts['pass'])) $this->invalid('Menu URLs cannot contain credentials.');
            $internal = str_starts_with($url, '/');
            $type = $item['type'];
            if (isset($paths[$type]) && !$internal) $this->invalid('Page, post and archive links require a known internal destination.');
            if ($internal) {
                $path = rtrim((string)($parts['path'] ?? ''), '/') ?: '/';
                $allowed = $paths[$type] ?? $known;
                if (!isset($allowed[$path])) $this->invalid('An internal menu destination is not a currently public page, post or archive.');
            }
        }
    }

    private function invalid(string $message): never { throw new ContentMutationException($message, 'validation_failed', 422); }
}
