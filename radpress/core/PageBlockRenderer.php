<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\ProductRepository;

final class PageBlockRenderer
{
    public function __construct(
        private readonly Paths $paths,
        private readonly PostRepository $posts,
        private readonly ProductRepository $products,
        private readonly ?array $site = null
    ) {
    }

    public function render(array $blocks): string
    {
        $html = '';
        $sitePath = $this->paths->configPath('site.json');
        $site = $this->site ?? (is_file($sitePath) ? (new FileStore())->readJson($sitePath) : []);
        $posts = $this->posts->allPublished();
        $products = $this->products->published();
        $widgets = (new \Batoi\Press\Content\WidgetRepository($this->paths))->load()['widgets'];

        foreach (array_slice($blocks, 0, 30) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string)($block['type'] ?? 'html');
            $title = trim((string)($block['title'] ?? ''));
            $heading = $title !== '' ? '<h2>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>' : '';
            if ($type === 'plugin') {
                $body = ($GLOBALS['bp_plugin_context'] ?? null)?->renderBlock((string)($block['plugin_block'] ?? ''), $block) ?? '';
                if ($body !== '') $html .= '<section class="bp-content-block">' . $heading . $body . '</section>';
                continue;
            }

            if ($type === 'form') {
                try { $form = (new \Batoi\Press\Content\FormRepository($this->paths))->find((string)($block['form'] ?? '')); }
                catch (\RuntimeException) { $form = null; }
                if ($form !== null && $form['enabled'] && (new PluginManager($this->paths))->enabled('forms')) {
                    $label = htmlspecialchars((string)$form['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $url = function_exists('bp_url') ? \bp_url('/forms/' . $form['id']) : '/forms/' . $form['id'];
                    $html .= '<section class="bp-content-block">' . $heading . '<a class="bp-button" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a></section>';
                }
                continue;
            }

            if ($type === 'html') {
                $html .= '<section class="bp-content-block bp-prose">' . $heading . (string)($block['body'] ?? '') . '</section>';
                continue;
            }
            if ($type === 'gallery') {
                $html .= ThemePartial::render($this->paths, $site, 'block-gallery', [
                    'title' => $title, 'block' => $block, 'body' => (string)($block['body'] ?? ''),
                ]);
                continue;
            }
            if ($type === 'posts' || $type === 'products') {
                $items = $this->filterItems($type === 'posts' ? $posts : $products, (string)($block['category'] ?? ''), (int)($block['limit'] ?? 6));
                foreach ($items as &$item) {
                    $item['url'] = $type === 'posts' ? $this->posts->publicPath($item) : '/product/' . rawurlencode((string)($item['slug'] ?? ''));
                    $image = (string)($item['featured_image'] ?? '');
                    $item['image_url'] = (preg_match('#^https?://#i', $image) || (str_starts_with($image, '/') && !str_starts_with($image, '//'))) ? $image : '';
                }
                unset($item);
                $html .= ThemePartial::render($this->paths, $site, 'block-' . $type, [
                    'title' => $title, 'block' => $block, 'items' => $items,
                ]);
                continue;
            }
            if ($type === 'widget') {
                $wanted = trim((string)($block['widget'] ?? ''));
                foreach ($widgets as $widget) {
                    if (is_array($widget) && $wanted !== '' && strcasecmp((string)($widget['title'] ?? ''), $wanted) === 0) {
                        $html .= ThemePartial::render($this->paths, $site, 'block-widget', [
                            'title' => $title !== '' ? $title : $wanted, 'block' => $block,
                            'widget' => $widget, 'body' => $this->widgetBody($widget, $posts),
                        ]);
                        break;
                    }
                }
            }
        }

        return $html;
    }

    private function filterItems(array $items, string $category, int $limit): array
    {
        $category = trim($category);
        if ($category !== '') {
            $items = array_values(array_filter($items, static fn(array $item): bool => strcasecmp((string)($item['category'] ?? ''), $category) === 0));
        }
        return array_slice($items, 0, max(1, min(24, $limit)));
    }

    private function widgetBody(array $widget, array $posts): string
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = (string)($widget['type'] ?? 'html');
        if ($type === 'tag_cloud') {
            $tags = [];
            foreach ($posts as $post) {
                foreach ((array)($post['tags'] ?? []) as $tag) $tags[(string)$tag] = ($tags[(string)$tag] ?? 0) + 1;
            }
            ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
            $body = '<div class="bp-tag-cloud">';
            foreach ($tags as $tag => $count) $body .= '<span>' . $escape((string)$tag) . ' <small>' . $count . '</small></span>';
            return $body . '</div>';
        }
        if ($type === 'recent_posts') {
            $body = '<ol' . ($type === 'activity_calendar' ? ' class="bp-activity-calendar"' : '') . '>';
            foreach (array_slice($posts, 0, $type === 'recent_posts' ? 5 : 8) as $post) {
                $body .= '<li>';
                if ($type === 'activity_calendar') {
                    $date = (string)($post['published_at'] ?? '');
                    $body .= '<time datetime="' . $escape($date) . '">' . $escape(function_exists('bp_date') ? \bp_date($date) : substr($date, 0, 10)) . '</time>';
                }
                $body .= '<a href="' . $escape($this->posts->publicPath($post)) . '">' . $escape((string)($post['title'] ?? 'Untitled')) . '</a></li>';
            }
            return $body . '</ol>';
        }
        $urls = [];
        foreach ($posts as $post) $urls[(string)$post['slug']] = $this->posts->publicPath($post);
        return WidgetRenderer::render($widget, $posts, $urls);
    }
}
