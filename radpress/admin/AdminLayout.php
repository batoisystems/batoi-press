<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Security\Csrf;

final class AdminLayout
{
    private static ?Csrf $csrf = null;

    public static function setCsrf(Csrf $csrf): void
    {
        self::$csrf = $csrf;
    }

    public static function render(string $title, string $body, string $mainClass = 'bp-admin bp-uif-surface'): string
    {
        $body = function_exists('bp_localize_markup_urls') ? \bp_localize_markup_urls($body) : $body;
        $content = self::isAuthRoute() ? self::authShell($title, $body, $mainClass) : self::adminShell($title, $body, $mainClass);
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title) . ' | Batoi Press</title><link rel="icon" type="image/svg+xml" href="' . self::e(self::assetUrl('/assets/img/batoi-press/press-color.svg')) . '"><link rel="apple-touch-icon" sizes="180x180" href="' . self::e(self::assetUrl('/assets/img/batoi-press/press-color-tile-180.png')) . '"><link rel="stylesheet" href="' . self::e(self::assetUrl('/assets/uif/uif.css')) . '"><link rel="stylesheet" href="' . self::e(self::assetUrl('/assets/css/style.css')) . '"><script src="' . self::e(self::assetUrl('/assets/uif/uif.iife.js')) . '" defer></script><script src="' . self::e(self::assetUrl('/assets/uif/uif.js')) . '" defer></script></head><body class="bp-admin-body">' . $content . '</body></html>';
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

    public static function buttonLink(string $label, string $href, string $icon, bool $secondary = false): string
    {
        $class = $secondary ? 'bp-button bp-button-secondary' : 'bp-button';
        return '<a class="' . $class . '" href="' . self::e($href) . '">' . self::buttonIcon($icon) . '<span>' . self::e($label) . '</span></a>';
    }

    public static function submitButton(string $label, string $icon, string $attributes = ''): string
    {
        $attributes = trim($attributes);
        $attributes = $attributes !== '' ? ' ' . $attributes : '';
        return '<button type="submit"' . $attributes . '>' . self::buttonIcon($icon) . '<span>' . self::e($label) . '</span></button>';
    }

    private static function assetUrl(string $path): string
    {
        $url = \bp_url($path);
        $file = dirname(__DIR__, 2) . '/public_html/' . ltrim($path, '/');
        return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
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
        $html = '<div class="bp-admin-stat"><div class="bp-admin-stat-head"><div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div><span class="bp-admin-stat-icon">' . self::icon(self::iconForLabel($label)) . '</span></div>';
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
        $siteName = self::siteName();
        $logout = self::$csrf !== null ? '<form method="post" action="' . self::e(\bp_url('/admin/logout')) . '" class="bp-topbar-logout">' . self::$csrf->field() . self::submitButton('Log Out', 'logout', 'class="bp-button bp-button-secondary bp-button-danger"') . '</form>' : '';
        return '<div class="bp-admin-shell"><aside class="bp-admin-sidebar"><div class="bp-admin-brand">' . self::brandMark() . '<div><span>' . self::e($siteName) . '</span><small>CMS Console</small></div></div>' . self::navigation() . '</aside><div class="bp-admin-workspace"><header class="bp-admin-topbar"><div class="bp-admin-topbar-title"><span>CMS Console</span><strong>' . self::e($siteName) . '</strong></div><nav aria-label="Admin actions">' . self::buttonLink('View site', \bp_url('/'), 'site', true) . self::buttonLink('Updates', \bp_url('/admin/updates'), 'refresh', true) . $logout . '</nav></header><main class="' . self::e($mainClass) . '">' . $body . '</main></div></div>';
    }

    private static function authShell(string $title, string $body, string $mainClass): string
    {
        return '<main class="bp-admin-auth"><section class="' . self::e($mainClass) . '"><div class="bp-admin-brand bp-admin-auth-brand">' . self::brandMark() . '<div><span>Batoi Press</span><small>' . self::e($title) . '</small></div></div>' . $body . '</section></main>';
    }

    private static function brandMark(): string
    {
        return '<span class="bp-admin-brand-mark"><img src="' . self::e(self::assetUrl('/assets/img/batoi-press/press-color.svg')) . '" alt="" aria-hidden="true"></span>';
    }

    private static function navigation(): string
    {
        $html = '<nav class="bp-admin-sidebar-nav" aria-label="Admin navigation">';
        foreach (self::navGroups() as $group => $items) {
            $html .= '<section><h2>' . self::e($group) . '</h2>';
            foreach ($items as $item) {
                $active = self::isActive((string)$item['href']) ? ' aria-current="page" class="is-active"' : '';
                $html .= '<a href="' . self::e(\bp_url((string)$item['href'])) . '"' . $active . '><span class="bp-nav-icon">' . self::icon((string)$item['icon']) . '</span><span class="bp-nav-label">' . self::e((string)$item['label']) . '</span></a>';
            }
            $html .= '</section>';
        }
        return $html . '</nav>';
    }

    private static function navGroups(): array
    {
        return [
            'Overview' => [
                ['label' => 'Dashboard', 'href' => '/admin', 'icon' => 'dashboard'],
            ],
            'Publish' => [
                ['label' => 'Pages', 'href' => '/admin/pages', 'icon' => 'file'],
                ['label' => 'Posts', 'href' => '/admin/posts', 'icon' => 'edit'],
                ['label' => 'Media', 'href' => '/admin/media', 'icon' => 'image'],
                ['label' => 'Menus', 'href' => '/admin/menus', 'icon' => 'menu'],
            ],
            'Site' => [
                ['label' => 'Settings', 'href' => '/admin/settings', 'icon' => 'settings'],
                ['label' => 'Themes', 'href' => '/admin/themes', 'icon' => 'code'],
                ['label' => 'Static Export', 'href' => '/admin/export-static', 'icon' => 'download'],
                ['label' => 'Cache', 'href' => '/admin/cache', 'icon' => 'database'],
            ],
            'Governance' => [
                ['label' => 'Users', 'href' => '/admin/users', 'icon' => 'users'],
                ['label' => 'Updates', 'href' => '/admin/updates', 'icon' => 'refresh'],
                ['label' => 'Audit Log', 'href' => '/admin/audit', 'icon' => 'shield'],
            ],
            'Intelligence' => [
                ['label' => 'Batoi AIF', 'href' => '/admin/aif', 'icon' => 'spark'],
            ],
        ];
    }

    private static function isActive(string $href): bool
    {
        $path = self::currentPath();
        if ($href === '/admin') {
            return $path === '/admin';
        }

        foreach (self::activePaths($href) as $activePath) {
            if ($path === $activePath || str_starts_with($path, $activePath . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function activePaths(string $href): array
    {
        return match ($href) {
            '/admin/themes' => ['/admin/themes', '/admin/theme-templates'],
            default => [$href],
        };
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

    private static function siteName(): string
    {
        $path = dirname(__DIR__) . '/config/site.json';
        if (is_file($path)) {
            $site = json_decode((string)file_get_contents($path), true);
            if (is_array($site)) {
                $name = trim((string)($site['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return 'Batoi Press';
    }

    private static function initials(string $label): string
    {
        $words = preg_split('/\s+/', trim($label)) ?: [];
        $letters = '';
        foreach ($words as $word) {
            if ($word !== '') {
                $letters .= strtoupper(substr($word, 0, 1));
            }
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters !== '' ? $letters : 'BP';
    }

    public static function icon(string $name): string
    {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>',
            'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"></path><path d="M14 3v5h5"></path><path d="M8 13h8"></path><path d="M8 17h5"></path>',
            'edit' => '<path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16z"></path><path d="M13.5 6.5l4 4"></path>',
            'image' => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="8" cy="10" r="1.5"></circle><path d="M21 16l-5-5L5 19"></path>',
            'menu' => '<path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h16"></path>',
            'settings' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.1 2.1-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-3v-.7a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L6.6 17l.1-.1A1.7 1.7 0 0 0 7 15a1.7 1.7 0 0 0-1.6-1H5v-3h.4A1.7 1.7 0 0 0 7 10a1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.1-2.1.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V4h3v.7a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 8l-.1.1A1.7 1.7 0 0 0 19.4 10a1.7 1.7 0 0 0 1.6 1h.4v3H21a1.7 1.7 0 0 0-1.6 1z"></path>',
            'download' => '<path d="M12 3v12"></path><path d="M7 10l5 5 5-5"></path><path d="M5 21h14"></path>',
            'database' => '<ellipse cx="12" cy="5" rx="7" ry="3"></ellipse><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5"></path><path d="M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"></path>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path><circle cx="9.5" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.9"></path><path d="M16 3.1a4 4 0 0 1 0 7.8"></path>',
            'refresh' => '<path d="M20 12a8 8 0 0 1-13.7 5.7"></path><path d="M4 12A8 8 0 0 1 17.7 6.3"></path><path d="M7 18H4v3"></path><path d="M17 6h3V3"></path>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="M9 12l2 2 4-5"></path>',
            'spark' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"></path><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"></path>',
            'chart' => '<path d="M4 19V5"></path><path d="M4 19h16"></path><rect x="7" y="12" width="2.8" height="4"></rect><rect x="11" y="8" width="2.8" height="8"></rect><rect x="15" y="10" width="2.8" height="6"></rect>',
            'site' => '<circle cx="12" cy="12" r="9"></circle><path d="M3 12h18"></path><path d="M12 3c2.4 2.6 3.5 5.6 3.5 9S14.4 18.4 12 21c-2.4-2.6-3.5-5.6-3.5-9S9.6 5.6 12 3z"></path>',
            'plus' => '<path d="M12 5v14"></path><path d="M5 12h14"></path>',
            'back' => '<path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path>',
            'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><path d="M17 21v-8H7v8"></path><path d="M7 3v5h8"></path>',
            'logout' => '<path d="M10 17l5-5-5-5"></path><path d="M15 12H3"></path><path d="M21 3v18"></path>',
            'upload' => '<path d="M12 16V4"></path><path d="M7 9l5-5 5 5"></path><path d="M5 20h14"></path>',
            'check' => '<path d="M20 6L9 17l-5-5"></path>',
            'code' => '<path d="M8 9l-4 3 4 3"></path><path d="M16 9l4 3-4 3"></path><path d="M14 5l-4 14"></path>',
        ];
        $body = $paths[$name] ?? $paths['dashboard'];
        return '<svg class="uif-icon bp-svg-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    private static function buttonIcon(string $name): string
    {
        return '<span class="bp-button-icon">' . self::icon($name) . '</span>';
    }

    private static function iconForLabel(string $label): string
    {
        $key = strtolower($label);
        return match (true) {
            str_contains($key, 'site') => 'site',
            str_contains($key, 'page'), str_contains($key, 'config') => 'file',
            str_contains($key, 'post') => 'edit',
            str_contains($key, 'version'), str_contains($key, 'cache') => 'refresh',
            str_contains($key, 'user'), str_contains($key, 'signed') => 'users',
            default => 'chart',
        };
    }
}
