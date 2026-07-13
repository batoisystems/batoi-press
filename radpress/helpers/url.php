<?php
declare(strict_types=1);

function bp_url(string $path = ''): string
{
    $base = bp_base_path();
    $path = '/' . ltrim($path, '/');
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
        '/\b(href|src|action)=(["\'])\/(?!\/)/i',
        static fn(array $match): string => $match[1] . '=' . $match[2] . $base . '/',
        $html
    ) ?? $html;
}

function bp_theme_asset(string $path): string
{
    $resolver = $GLOBALS['bp_theme_asset_resolver'] ?? null;
    if (!is_callable($resolver)) {
        return '#';
    }

    return (string)$resolver($path);
}
