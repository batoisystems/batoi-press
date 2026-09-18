<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use DateTimeImmutable;

final class WidgetRenderer
{
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
