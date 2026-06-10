<?php
declare(strict_types=1);

function bp_url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

