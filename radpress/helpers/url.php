<?php
declare(strict_types=1);

function bp_url(string $path = ''): string
{
    $path = trim($path);
    if ($path !== '' && (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1 || str_starts_with($path, '//') || str_starts_with($path, '#'))) {
        return $path;
    }

    $base = bp_base_path();
    $path = '/' . ltrim($path, '/');
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        return $path === '/' ? '/' : rtrim($path, '/');
    }
    $url = $base . $path;
    return $url === '' ? '/' : ($url === '/' ? '/' : rtrim($url, '/'));
}

function bp_base_path(): string
{
    if (($GLOBALS['bp_static_export_mode'] ?? false) === true) {
        return '';
    }
    return \Batoi\Press\Core\BasePath::detect($_SERVER);
}

function bp_localize_markup_urls(string $html): string
{
    $base = bp_base_path();
    if ($base === '') {
        return $html;
    }

    return preg_replace_callback(
        '/\b(href|src|action)=(["\'])(\/(?!\/)[^"\']*)\2/i',
        static function (array $match) use ($base): string {
            $path = (string)$match[3];
            if ($path === $base || str_starts_with($path, $base . '/')) {
                return $match[0];
            }

            return $match[1] . '=' . $match[2] . $base . $path . $match[2];
        },
        $html
    ) ?? $html;
}

function bp_is_current_url(string $url, ?string $requestUri = null): bool
{
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 || str_starts_with($url, '//')) {
        return false;
    }

    $candidate = (string)(parse_url($url, PHP_URL_PATH) ?? '/');
    $current = (string)(parse_url($requestUri ?? (string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $base = bp_base_path();
    foreach ([$candidate, $current] as $index => $path) {
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = '/' . ltrim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($index === 0) {
            $candidate = $path;
        } else {
            $current = $path;
        }
    }

    return $current === $candidate || ($candidate !== '/' && str_starts_with($current, $candidate . '/'));
}

function bp_theme_asset(string $path): string
{
    $resolver = $GLOBALS['bp_theme_asset_resolver'] ?? null;
    if (!is_callable($resolver)) {
        return '#';
    }

    return (string)$resolver($path);
}
