<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

final class AdminAccess
{
    public const ROLES = ['owner', 'admin', 'editor', 'author', 'viewer'];

    public static function canAccess(array $user, string $path, string $method = 'GET'): bool
    {
        $role = self::role($user);
        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }

        $path = self::normalizePath($path);
        if ($path === '/admin' || $path === '/admin/logout') {
            return true;
        }

        if ($role === 'editor') {
            return self::matches($path, [
                '/admin/pages',
                '/admin/pages/new',
                '/admin/pages/edit/',
                '/admin/pages/save',
                '/admin/posts',
                '/admin/posts/new',
                '/admin/posts/edit/',
                '/admin/posts/save',
                '/admin/media',
                '/admin/media/upload',
                '/admin/menus',
                '/admin/menus/save',
                '/admin/aif',
                '/admin/aif/assist',
            ]);
        }

        if ($role === 'author') {
            return self::matches($path, [
                '/admin/posts',
                '/admin/posts/new',
                '/admin/posts/edit/',
                '/admin/posts/save',
            ]);
        }

        return false;
    }

    public static function canSeeNav(array $user, string $href): bool
    {
        return self::canAccess($user, $href, 'GET');
    }

    public static function role(array $user): string
    {
        $role = strtolower((string)($user['role'] ?? 'viewer'));
        return in_array($role, self::ROLES, true) ? $role : 'viewer';
    }

    public static function roleSummary(): array
    {
        return [
            'owner' => 'Full installation governance and recovery access.',
            'admin' => 'Full administration except owner safeguards enforced by user lifecycle controls.',
            'editor' => 'Publishing access for pages, posts, media, menus, and AIF assist.',
            'author' => 'Post workflow access for drafting and maintaining posts.',
            'viewer' => 'Dashboard-only access for review and support.',
        ];
    }

    private static function matches(string $path, array $allowed): bool
    {
        foreach ($allowed as $rule) {
            if (str_ends_with($rule, '/')) {
                if (str_starts_with($path, rtrim($rule, '/') . '/')) {
                    return true;
                }
                continue;
            }
            if ($path === $rule) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
