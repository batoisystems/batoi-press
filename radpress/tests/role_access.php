<?php
declare(strict_types=1);

use Batoi\Press\Security\AdminAccess;

require dirname(__DIR__) . '/autoload.php';

$cases = [
    ['owner', '/admin/users', 'GET', true],
    ['admin', '/admin/updates/apply', 'POST', true],
    ['editor', '/admin/pages/save', 'POST', true],
    ['editor', '/admin/media/upload', 'POST', true],
    ['editor', '/admin/media/edit', 'GET', true],
    ['editor', '/admin/media/update-text', 'POST', true],
    ['editor', '/admin/media/replace', 'POST', true],
    ['editor', '/admin/media/delete', 'POST', true],
    ['editor', '/admin/media/libraries/upload', 'POST', false],
    ['editor', '/admin/media/libraries/toggle', 'POST', false],
    ['admin', '/admin/media/libraries/upload', 'POST', true],
    ['owner', '/admin/media/libraries/delete', 'POST', true],
    ['editor', '/admin/users', 'GET', false],
    ['editor', '/admin/updates', 'GET', false],
    ['author', '/admin/posts', 'GET', true],
    ['author', '/admin/posts/save', 'POST', true],
    ['author', '/admin/pages', 'GET', false],
    ['author', '/admin/media', 'GET', false],
    ['author', '/admin/media/edit', 'GET', false],
    ['viewer', '/admin', 'GET', true],
    ['viewer', '/admin/pages', 'GET', false],
    ['viewer', '/admin/logout', 'POST', true],
    ['unknown', '/admin', 'GET', true],
    ['unknown', '/admin/posts', 'GET', false],
];

foreach ($cases as [$role, $path, $method, $expected]) {
    $actual = AdminAccess::canAccess(['role' => $role], $path, $method);
    assertSame($expected, $actual, "{$role} {$method} {$path}");
}

assertSame('viewer', AdminAccess::role(['role' => 'unexpected']), 'unexpected roles should fall back to viewer');
assertSame('admin', AdminAccess::role(['role' => 'ADMIN']), 'role normalization should be case-insensitive');

echo "Role access checks passed\n";

function assertSame(bool|string $expected, bool|string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}
