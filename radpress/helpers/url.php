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
