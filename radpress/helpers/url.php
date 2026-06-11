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
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($script);

    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        return '';
    }

    return rtrim($dir, '/');
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
