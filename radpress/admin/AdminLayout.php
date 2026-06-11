<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

final class AdminLayout
{
    public static function render(string $title, string $body, string $mainClass = 'bp-admin bp-uif-surface'): string
    {
        $body = function_exists('bp_localize_markup_urls') ? \bp_localize_markup_urls($body) : $body;
        $content = self::isAuthRoute() ? self::authShell($title, $body, $mainClass) : self::adminShell($title, $body, $mainClass);
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title) . ' | Batoi Press</title><link rel="stylesheet" href="' . self::e(\bp_url('/assets/uif/uif.css')) . '"><link rel="stylesheet" href="' . self::e(\bp_url('/assets/css/style.css')) . '"><script src="' . self::e(\bp_url('/assets/uif/uif.js')) . '" defer></script></head><body class="bp-admin-body">' . $content . '</body></html>';
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

    public static function pageHeader(string $title, string $description = '', string $actions = ''): string
    {
        $html = '<header class="bp-admin-page-header"><div><p class="bp-section-kicker">Admin Console</p><h1>' . self::e($title) . '</h1>';
        if ($description !== '') {
            $html .= '<p>' . self::e($description) . '</p>';
        }
        $html .= '</div>';
        if ($actions !== '') {
            $html .= '<div class="bp-admin-page-actions">' . $actions . '</div>';
        }
        return $html . '</header>';
    }

    public static function statCard(string $label, string $value, string $note = ''): string
    {
        $html = '<div class="bp-admin-stat"><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd>';
        if ($note !== '') {
            $html .= '<p>' . self::e($note) . '</p>';
        }
        return $html . '</div>';
    }

    public static function section(string $title, string $body, string $description = ''): string
    {
        $html = '<section class="bp-admin-section"><header><div><h2>' . self::e($title) . '</h2>';
        if ($description !== '') {
            $html .= '<p>' . self::e($description) . '</p>';
        }
        $html .= '</div></header>' . $body . '</section>';
        return $html;
    }

    private static function adminShell(string $title, string $body, string $mainClass): string
    {
        return '<div class="bp-admin-shell"><aside class="bp-admin-sidebar"><div class="bp-admin-brand"><span>Batoi Press</span><small>Admin</small></div>' . self::navigation() . '</aside><div class="bp-admin-workspace"><header class="bp-admin-topbar"><div><strong>' . self::e($title) . '</strong><span>Business-ready publishing console</span></div><nav aria-label="Admin actions"><a class="bp-button bp-button-secondary" href="' . self::e(\bp_url('/')) . '">View site</a><a class="bp-button bp-button-secondary" href="' . self::e(\bp_url('/admin/updates')) . '">Updates</a></nav></header><main class="' . self::e($mainClass) . '">' . $body . '</main></div></div>';
    }

    private static function authShell(string $title, string $body, string $mainClass): string
    {
        return '<main class="bp-admin-auth"><section class="' . self::e($mainClass) . '"><div class="bp-admin-brand bp-admin-auth-brand"><span>Batoi Press</span><small>' . self::e($title) . '</small></div>' . $body . '</section></main>';
    }

    private static function navigation(): string
    {
        $html = '<nav class="bp-admin-sidebar-nav" aria-label="Admin navigation">';
        foreach (self::navGroups() as $group => $items) {
            $html .= '<section><h2>' . self::e($group) . '</h2>';
            foreach ($items as $item) {
                $active = self::isActive((string)$item['href']) ? ' aria-current="page" class="is-active"' : '';
                $html .= '<a href="' . self::e(\bp_url((string)$item['href'])) . '"' . $active . '><span>' . self::e((string)$item['icon']) . '</span>' . self::e((string)$item['label']) . '</a>';
            }
            $html .= '</section>';
        }
        return $html . '</nav>';
    }

    private static function navGroups(): array
    {
        return [
            'Overview' => [
                ['label' => 'Dashboard', 'href' => '/admin', 'icon' => 'O'],
            ],
            'Publish' => [
                ['label' => 'Pages', 'href' => '/admin/pages', 'icon' => 'P'],
                ['label' => 'Posts', 'href' => '/admin/posts', 'icon' => 'B'],
                ['label' => 'Media', 'href' => '/admin/media', 'icon' => 'M'],
                ['label' => 'Menus', 'href' => '/admin/menus', 'icon' => 'N'],
            ],
            'Site' => [
                ['label' => 'Settings', 'href' => '/admin/settings', 'icon' => 'S'],
                ['label' => 'Static Export', 'href' => '/admin/export-static', 'icon' => 'E'],
                ['label' => 'Cache', 'href' => '/admin/cache', 'icon' => 'C'],
            ],
            'Governance' => [
                ['label' => 'Users', 'href' => '/admin/users', 'icon' => 'U'],
                ['label' => 'Updates', 'href' => '/admin/updates', 'icon' => 'R'],
                ['label' => 'Audit Log', 'href' => '/admin/audit', 'icon' => 'L'],
            ],
            'Intelligence' => [
                ['label' => 'Batoi AIF', 'href' => '/admin/aif', 'icon' => 'A'],
            ],
        ];
    }

    private static function isActive(string $href): bool
    {
        $path = self::currentPath();
        if ($href === '/admin') {
            return $path === '/admin';
        }
        return $path === $href || str_starts_with($path, $href . '/');
    }

    private static function currentPath(): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $base = function_exists('bp_base_path') ? \bp_base_path() : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function isAuthRoute(): bool
    {
        return self::currentPath() === '/admin/login';
    }
}
