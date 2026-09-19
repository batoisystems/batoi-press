<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use DateTimeImmutable;

final class WidgetRenderer
{
    public const TYPES = ['html' => 'Custom HTML', 'recent_posts' => 'Recent Posts', 'tag_cloud' => 'Tag Cloud', 'image_gallery' => 'Image Gallery', 'activity_calendar' => 'Activity Calendar', 'subscribe' => 'Subscribe'];
    public const TARGETS = ['all_sidebars', 'left_sidebar', 'right_sidebar'];

    /** Shared editor/machine normalization; strict mode rejects rather than discards unsupported input. */
    public static function normalizeList(array $rows, bool $strict = false): array
    {
        if ($strict && (!array_is_list($rows) || count($rows) > 50)) throw new \RuntimeException('Supply at most 50 ordered widgets.');
        $widgets = [];
        $html = new HtmlContent();
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \RuntimeException('Each widget must be an object.');
            if ($strict) {
                $bounds = ['type' => 30, 'target' => 30, 'title' => 200, 'body' => 60000, 'gallery_images' => 8000, 'subscribe_url' => 2048];
                foreach ($row as $field => $value) {
                    if (!isset($bounds[$field]) || !is_string($value) || strlen($value) > $bounds[$field]) throw new \RuntimeException('Invalid widget field.');
                }
                if (!isset(self::TYPES[$row['type'] ?? '']) || !in_array($row['target'] ?? 'all_sidebars', self::TARGETS, true)) throw new \RuntimeException('Unsupported widget type or target.');
            }
            $type = isset(self::TYPES[(string)($row['type'] ?? '')]) ? (string)$row['type'] : 'html';
            $target = in_array($row['target'] ?? '', self::TARGETS, true) ? (string)$row['target'] : 'all_sidebars';
            $title = trim((string)($row['title'] ?? ''));
            if ($type === 'recent_posts') {
                if (!array_filter($widgets, static fn (array $widget): bool => $widget['type'] === 'recent_posts')) $widgets[] = ['type' => $type, 'title' => $title ?: 'Recent posts', 'target' => $target];
                elseif ($strict) throw new \RuntimeException('Only one Recent Posts widget is supported.');
                continue;
            }
            $body = $html->sanitize((string)($row['body'] ?? ''));
            $gallery = substr(trim((string)($row['gallery_images'] ?? '')), 0, 8000);
            $subscribe = trim((string)($row['subscribe_url'] ?? ''));
            if ($subscribe !== '' && (!self::safeUrl($subscribe) || !str_starts_with(strtolower($subscribe), 'https://'))) throw new \RuntimeException('Newsletter signup URLs must be valid HTTPS URLs.');
            if ($strict) {
                $urls = $subscribe === '' ? [] : [$subscribe];
                if ($gallery !== '') {
                    $lines = explode("\n", $gallery);
                    if (count($lines) > 24) throw new \RuntimeException('A gallery supports at most 24 images.');
                    foreach ($lines as $line) $urls[] = trim(explode('|', $line, 2)[0]);
                }
                foreach ($urls as $url) {
                    $parts = parse_url($url);
                    if (!self::safeUrl($url) || $parts === false || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url)) || str_starts_with(rawurldecode($url), '//')) throw new \RuntimeException('Widget links must be safe internal or HTTPS URLs without credentials.');
                }
            }
            $needsBody = in_array($type, ['html', 'image_gallery', 'subscribe'], true);
            if (($type === 'image_gallery' && self::galleryImages($gallery) !== []) || ($type === 'subscribe' && $subscribe !== '')) $needsBody = false;
            if ($title !== '' && (!$needsBody || $body !== '')) $widgets[] = ['type' => $type, 'title' => $title, 'body' => $body, 'target' => $target, 'gallery_images' => $gallery, 'subscribe_url' => $subscribe];
            elseif ($strict) throw new \RuntimeException('Each widget needs a title and any content required by its type.');
        }
        if (!array_filter($widgets, static fn (array $widget): bool => $widget['type'] === 'recent_posts')) array_unshift($widgets, ['type' => 'recent_posts', 'title' => 'Recent posts', 'target' => 'all_sidebars']);
        if ($strict && count($widgets) > 50) throw new \RuntimeException('The widget limit includes the required Recent Posts widget.');
        return $widgets;
    }

    public static function galleryImages(string $source): array
    {
        $images = [];
        foreach (array_slice(explode("\n", $source), 0, 24) as $line) {
            [$url, $alt] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if (self::safeUrl($url)) $images[] = ['url'=>$url,'alt'=>$alt];
        }
        return $images;
    }

    public static function safeUrl(string $url): bool
    {
        return !preg_match('/[\x00-\x20\x7f\\\\]/', $url) && ((str_starts_with($url,'/') && !str_starts_with($url,'//')) || (filter_var($url,FILTER_VALIDATE_URL) && str_starts_with(strtolower($url),'https://')));
    }

    public static function render(array $widget, array $posts, array $urls): string
    {
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = $widget['type'] ?? 'html';
        if ($type === 'image_gallery' && !empty($widget['gallery_images'])) {
            $body = '<div class="bp-widget-gallery">';
            foreach (self::galleryImages((string)$widget['gallery_images']) as $image) $body .= '<figure><img loading="lazy" src="' . $e($image['url']) . '" alt="' . $e($image['alt']) . '"></figure>';
            return $body . '</div>';
        }
        if ($type === 'subscribe' && !empty($widget['subscribe_url']) && self::safeUrl((string)$widget['subscribe_url'])) {
            return '<a class="bp-button" href="' . $e((string)$widget['subscribe_url']) . '">Subscribe to newsletter <span aria-hidden="true">↗</span></a>';
        }
        if ($type === 'activity_calendar') {
            $month = new DateTimeImmutable('first day of this month midnight');
            $entries = [];
            foreach ($posts as $post) {
                try { $date = new DateTimeImmutable((string)($post['published_at'] ?? '')); } catch (\Exception) { continue; }
                if (empty($post['published_at']) || $date->format('Y-m') !== $month->format('Y-m')) continue;
                $url = (string)($urls[(string)($post['slug'] ?? '')] ?? '');
                if (self::safeUrl($url)) $entries[(int)$date->format('j')][] = ['title'=>(string)($post['title'] ?? 'Untitled'), 'url'=>$url];
            }
            $body = '<table class="bp-widget-calendar"><caption>' . $e($month->format('F Y')) . '</caption><thead><tr>';
            foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day) $body .= '<th scope="col">' . $day . '</th>';
            $body .= '</tr></thead><tbody><tr>';
            $offset = (int)$month->format('N') - 1;
            $days = (int)$month->format('t');
            $cells = (int)ceil(($offset + $days) / 7) * 7;
            for ($cell=0; $cell<$cells; $cell++) {
                if ($cell > 0 && $cell % 7 === 0) $body .= '</tr><tr>';
                $day = $cell - $offset + 1;
                $body .= '<td>';
                if ($day >= 1 && $day <= $days) {
                    $body .= '<span>' . $day . '</span>';
                    foreach ($entries[$day] ?? [] as $entry) $body .= '<a title="' . $e($entry['title']) . '" href="' . $e($entry['url']) . '">' . $e($entry['title']) . '</a>';
                }
                $body .= '</td>';
            }
            return $body . '</tr></tbody></table>';
        }
        return (new HtmlContent())->sanitize((string)($widget['body'] ?? ''));
    }
}
