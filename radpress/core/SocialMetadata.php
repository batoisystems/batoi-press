<?php
declare(strict_types=1);
namespace Batoi\Press\Core;

final class SocialMetadata
{
    public const FIELDS = ['og_type', 'og_site_name', 'og_title', 'og_description', 'og_url', 'og_image', 'twitter_card', 'twitter_title', 'twitter_description', 'twitter_image'];

    public static function normalize(array $input, array $existing = []): array
    {
        $result = [];
        foreach (self::FIELDS as $field) {
            $value = $input[$field] ?? $existing[$field] ?? '';
            if (!is_string($value) || strlen($value) > (in_array($field, ['og_url', 'og_image', 'twitter_image'], true) ? 2048 : 500)) throw new \RuntimeException('Invalid social metadata: ' . $field);
            $value = trim($value);
            if ($value !== '' && in_array($field, ['og_url', 'og_image', 'twitter_image'], true) && self::safeUrl($value) === '') throw new \RuntimeException('Social URLs must be absolute HTTPS URLs without credentials.');
            if ($field === 'og_type' && $value !== '' && !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value)) throw new \RuntimeException('Enter a valid Open Graph type.');
            if ($field === 'twitter_card' && $value !== '' && !in_array($value, ['summary', 'summary_large_image'], true)) throw new \RuntimeException('Twitter card must be summary or summary_large_image.');
            $result[$field] = $value;
        }
        return $result;
    }

    public static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) && $parts !== false && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && !preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url)) ? $url : '';
    }

    public static function canonical(string $base, string $requestUri): string
    {
        $path = '/' . ltrim((string)(parse_url($requestUri, PHP_URL_PATH) ?? ''), '/');
        $base = rtrim($base, '/');
        $prefix = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) $path = substr($path, strlen($prefix)) ?: '/';
        return $base . $path;
    }

    public static function siteBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) && $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && !preg_match('/[[:cntrl:] ]/', $url) && !str_contains($url, '\\') ? $url : '';
    }

    public static function searchDefaults(array $record, array $site, bool $homepage): array
    {
        $title = trim((string)($record['seo_title'] ?? ''));
        $description = trim((string)($record['seo_description'] ?? ''));
        $siteName = trim((string)($site['name'] ?? ''));
        $label = trim((string)($record['title'] ?? ''));
        if ($homepage && $siteName !== '' && ($title === '' || in_array(strtolower($title), ['home', 'homepage'], true))) {
            $record['seo_title'] = $siteName;
        } elseif ($title === '' && $label !== '' && $siteName !== '' && strcasecmp($label, $siteName) !== 0) {
            $record['seo_title'] = $label . ' | ' . $siteName;
        }
        if ($description === '' || ($homepage && in_array(strtolower($description), ['home', 'homepage'], true))) {
            $body = (string)($record['body'] ?? '');
            $body = preg_replace('~<(script|style|pre|form)\b[^>]*>.*?</\1\s*>~is', '', $body) ?? '';
            $body = preg_replace('~</(?:p|div|section|article|h[1-6])>~i', '$0 ', $body) ?? '';
            preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $body, $paragraphs);
            $tagline = (string)($site['tagline'] ?? '');
            $candidates = [$homepage ? $tagline : '', (string)($record['subtitle'] ?? ''), ...($paragraphs[1] ?? []), $tagline, $body];
            foreach ($candidates as $candidate) {
                $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ($plain === '' || strcasecmp($plain, $label) === 0) continue;
                preg_match('/^.{1,155}/us', $plain, $excerpt);
                $short = $excerpt[0] ?? '';
                if ($short !== $plain) $short = rtrim(preg_replace('/\s+\S*$/u', '', $short) ?? $short) . '…';
                $record['seo_description'] = $short;
                break;
            }
        }
        return $record;
    }

    public static function missingSearchTags(string $html, array $record, string $canonical): string
    {
        if (!preg_match('~<head\b[^>]*>(.*?)</head\s*>~is', $html, $match)) return '';
        $head = $match[1];
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tags = '';
        if ($canonical !== '' && !preg_match('~<link\b[^>]*\brel\s*=\s*(?:"[^"]*\bcanonical\b[^"]*"|\'[^\']*\bcanonical\b[^\']*\'|canonical\b)~i', $head)) {
            $tags .= '<link rel="canonical" href="' . $escape($canonical) . '">' . "\n";
        }
        if (!empty($record['seo_title']) && !preg_match('~<title\b~i', $head)) {
            $tags .= '<title>' . $escape((string)$record['seo_title']) . '</title>' . "\n";
        }
        if (!empty($record['seo_description']) && !preg_match('~<meta\b[^>]*\bname\s*=\s*["\']?description\b~i', $head)) {
            $tags .= '<meta name="description" content="' . $escape((string)$record['seo_description']) . '">' . "\n";
        }
        return $tags;
    }

    public static function render(array $record, array $site, string $title, string $description, string $url, bool $post): string
    {
        $pick = static fn(string $key, string $fallback): string => trim((string)($record[$key] ?? '')) ?: $fallback;
        $values = [
            'og:type' => $pick('og_type', $post ? 'article' : 'website'),
            'og:site_name' => $pick('og_site_name', (string)($site['name'] ?? '')),
            'og:title' => $pick('og_title', $title), 'og:description' => $pick('og_description', $description),
            'og:url' => self::safeUrl($pick('og_url', $url)), 'og:image' => self::safeUrl($pick('og_image', '')),
            'twitter:card' => $pick('twitter_card', 'summary'),
            'twitter:title' => $pick('twitter_title', $pick('og_title', $title)),
            'twitter:description' => $pick('twitter_description', $pick('og_description', $description)),
            'twitter:image' => self::safeUrl($pick('twitter_image', $pick('og_image', ''))),
        ];
        $html = '';
        foreach ($values as $name => $value) {
            if ($value === '') continue;
            $html .= '<meta ' . (str_starts_with($name, 'og:') ? 'property' : 'name') . '="' . $name . '" content="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . "\n";
        }
        return $html;
    }
}
