<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

final class AdminLayout
{
    public static function render(string $title, string $body, string $mainClass = 'bp-admin bp-uif-surface'): string
    {
        $body = function_exists('bp_localize_markup_urls') ? \bp_localize_markup_urls($body) : $body;
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title) . ' | Batoi Press</title><link rel="stylesheet" href="' . self::e(\bp_url('/assets/uif/uif.css')) . '"><link rel="stylesheet" href="' . self::e(\bp_url('/assets/css/style.css')) . '"><script src="' . self::e(\bp_url('/assets/uif/uif.js')) . '" defer></script></head><body class="bp-admin-body"><main class="' . self::e($mainClass) . '">' . $body . '</main></body></html>';
    }

    public static function message(string $title, string $message, bool $error = false): string
    {
        $class = $error ? 'bp-error bp-uif-danger' : 'bp-notice bp-uif-notice';
        return self::render($title, '<p class="' . $class . '">' . self::e($message) . '</p>');
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
