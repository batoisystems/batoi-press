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

    private array $context = [];
    private ?CollectionView $collections = null;

    public function render(array $blocks, int $depth = 0): string
    {
        if ($depth > 1) return '';
        $html = '';
        if ($depth === 0) {
            $sitePath = $this->paths->configPath('site.json');
            $this->context = [
                $this->site ?? (is_file($sitePath) ? (new FileStore())->readJson($sitePath) : []),
                $this->posts->allPublished(), $this->products->published(),
                (new \Batoi\Press\Content\WidgetRepository($this->paths))->load()['widgets'],
            ];
        }
        [$site, $posts, $products, $widgets] = $this->context;

        foreach (array_slice($blocks, 0, 30) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $start = strlen($html);
            $type = (string)($block['type'] ?? 'html');
            $title = trim((string)($block['title'] ?? ''));
            $heading = $title !== '' ? '<h2>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>' : '';
            try {
            if ($type === 'grid') {
                $html .= '<section class="bp-content-block">' . $heading . '<div class="bp-block-grid bp-block-grid-' . max(1, min(4, (int)($block['columns'] ?? 2))) . '">';
                foreach (array_slice((array)($block['cells'] ?? []), 0, 12) as $cell) {
                    if (is_array($cell) && ($cell['type'] ?? '') !== 'grid') $html .= '<div class="bp-block-cell">' . $this->render([$cell], $depth + 1) . '</div>';
                }
                $html .= '</div></section>';
                continue;
            }
            if ($type === 'plugin') {
                $body = ($GLOBALS['bp_plugin_context'] ?? null)?->renderBlock((string)($block['plugin_block'] ?? ''), $block) ?? '';
                if ($body !== '') $html .= '<section class="bp-content-block">' . $heading . $body . '</section>';
                continue;
            }

            if ($type === 'collection') {
                $this->collections ??= new CollectionView($this->paths, $site);
                $html .= $this->collections->render($block);
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
                if (trim((string)($block['gallery_images'] ?? '')) !== '') {
                    $body = '';
                    foreach (array_slice(WidgetRenderer::galleryImages((string)$block['gallery_images']), 0, max(1, min(24, (int)($block['limit'] ?? 6)))) as $image) {
                        $url = htmlspecialchars(function_exists('bp_url') ? \bp_url($image['url']) : $image['url'], ENT_QUOTES, 'UTF-8');
                        $body .= '<a href="' . $url . '" data-uif-role="item"><img loading="lazy" src="' . $url . '" alt="' . htmlspecialchars($image['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></a>';
                    }
                    $block['body'] = '<div class="bp-widget-gallery" data-uif="lightbox">' . $body . '</div>';
                }
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
            } finally {
                $classes = self::classNames((string)($block['custom_class'] ?? ''));
                if ($classes !== '' && strlen($html) > $start) $html = substr($html, 0, $start) . '<div class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">' . substr($html, $start) . '</div>';
            }
        }

        return $html;
    }

    public static function gridCss(): string
    {
        return '.bp-block-grid{display:grid;gap:1.5rem;grid-template-columns:repeat(2,minmax(0,1fr))}.bp-block-grid-1{grid-template-columns:minmax(0,1fr)}.bp-block-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.bp-block-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}.bp-block-cell{min-width:0;overflow-wrap:anywhere}.bp-block-cell img,.bp-block-cell video{max-width:100%;height:auto}.bp-block-cell iframe{max-width:100%}.bp-block-cell pre{overflow:auto}.bp-block-cell .bp-post-grid,.bp-block-cell .bp-product-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr))}.bp-block-cell .bp-product-grid{padding:0}.bp-block-cell .bp-content-block{margin-top:0}@media(max-width:56rem){.bp-block-grid-3,.bp-block-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:36rem){.bp-block-grid{grid-template-columns:minmax(0,1fr)}}';
    }

    public static function classNames(string $value): string
    {
        $classes = preg_split('/\s+/', trim(substr($value, 0, 500))) ?: [];
        return implode(' ', array_unique(array_filter($classes, static fn(string $class): bool => preg_match('/^-?[_a-zA-Z]+[_a-zA-Z0-9-]*$/D', $class) === 1)));
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
