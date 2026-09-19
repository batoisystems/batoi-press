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
        private readonly ProductRepository $products
    ) {
    }

    public function render(array $blocks): string
    {
        $html = '';
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

            if ($type === 'html' || $type === 'gallery') {
                $class = $type === 'gallery' ? 'bp-content-block bp-content-gallery' : 'bp-content-block bp-prose';
                $html .= '<section class="' . $class . '">' . $heading . (string)($block['body'] ?? '') . '</section>';
                continue;
            }
            if ($type === 'posts') {
                $html .= '<section class="bp-content-block">' . $heading . '<div class="bp-post-grid">';
                foreach ($this->filterItems($posts, (string)($block['category'] ?? ''), (int)($block['limit'] ?? 6)) as $item) {
                    $url = $this->posts->publicPath($item);
                    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $html .= '<article class="bp-post-card">';
                    $image = (string)($item['featured_image'] ?? '');
                    if (!empty($block['show_image']) && $image !== '' && (preg_match('#^https?://#i', $image) || (str_starts_with($image, '/') && !str_starts_with($image, '//')))) {
                        $html .= '<a class="bp-post-card-media" href="' . $escape($url) . '"><img loading="lazy" src="' . $escape($image) . '" alt="' . $escape((string)($item['featured_image_alt'] ?? '')) . '"></a>';
                    }
                    if (!empty($block['show_date']) && !empty($item['published_at'])) {
                        $date = (string)$item['published_at'];
                        $html .= '<p class="bp-meta"><time datetime="' . $escape($date) . '">' . $escape(function_exists('bp_date') ? \bp_date($date) : substr($date, 0, 10)) . '</time></p>';
                    }
                    $html .= '<h3><a href="' . $escape($url) . '">' . $escape((string)($item['title'] ?? 'Untitled')) . '</a></h3><p>' . $escape((string)($item['seo_description'] ?? '')) . '</p>';
                    if (!empty($block['show_read_more'])) $html .= '<a class="bp-text-link" href="' . $escape($url) . '">Read more <span aria-hidden="true">&rarr;</span></a>';
                    $html .= '</article>';
                }
                $html .= '</div></section>';
                continue;
            }
            if ($type === 'products') {
                $html .= '<section class="bp-content-block">' . $heading . '<div class="bp-product-grid">';
                foreach ($this->filterItems($products, (string)($block['category'] ?? ''), (int)($block['limit'] ?? 6)) as $item) {
                    $url = '/product/' . rawurlencode((string)($item['slug'] ?? ''));
                    $html .= '<article class="bp-product-card"><h3><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)($item['title'] ?? 'Untitled'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></h3><p class="bp-price">' . htmlspecialchars((string)($item['currency'] ?? 'USD') . ' ' . (string)($item['price'] ?? '0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></article>';
                }
                $html .= '</div></section>';
                continue;
            }
            if ($type === 'widget') {
                $wanted = trim((string)($block['widget'] ?? ''));
                foreach ($widgets as $widget) {
                    if (is_array($widget) && $wanted !== '' && strcasecmp((string)($widget['title'] ?? ''), $wanted) === 0) {
                        $widgetHeading = $heading !== '' ? $heading : '<h2>' . htmlspecialchars($wanted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
                        $html .= '<section class="bp-content-block bp-sidebar-widget">' . $widgetHeading . $this->widgetBody($widget, $posts) . '</section>';
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
