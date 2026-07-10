<?php
declare(strict_types=1);

use Batoi\Press\Core\Paths;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\UploadGuard;

require dirname(__DIR__) . '/autoload.php';

$root = dirname(__DIR__, 2);

assertSame('radpress/config/installed.lock', (string)(securityConfig($root)['installer_lock'] ?? ''), 'installer lock path should be configured');
assertTrue(str_contains((string)file_get_contents($root . '/public_html/install.php'), 'installed.lock'), 'installer should check installed.lock');

foreach ([
    'radpress/.htaccess',
    'radpress/app/.htaccess',
    'radpress/config/.htaccess',
    'radpress/content/.htaccess',
    'radpress/data/.htaccess',
] as $path) {
    $contents = (string)file_get_contents($root . '/' . $path);
    assertTrue(str_contains($contents, 'Require all denied'), "{$path} should deny direct access");
}

$publicHtaccess = (string)file_get_contents($root . '/public_html/.htaccess');
assertTrue(str_contains($publicHtaccess, 'Options -Indexes'), 'public_html should disable directory indexes');
assertTrue(str_contains($publicHtaccess, '<FilesMatch "\\.(php|phtml|phar)$">'), 'public_html should deny arbitrary PHP entrypoints');
foreach (['index.php', 'admin.php', 'install.php'] as $entrypoint) {
    assertTrue(str_contains($publicHtaccess, '<Files "' . $entrypoint . '">'), "{$entrypoint} should be an explicit public entrypoint");
}

$uploads = (array)(securityConfig($root)['uploads'] ?? []);
$guard = new UploadGuard((array)($uploads['allowed_extensions'] ?? []), (int)($uploads['max_bytes'] ?? 5242880));
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'asset.png']), 'allowed image upload should pass');
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'theme.css']), 'allowed stylesheet upload should pass');
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'theme.js']), 'allowed script upload should pass');
assertSame('File type is not allowed.', $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'shell.php']), 'PHP upload should be rejected');
assertSame('File size is not allowed.', $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 0, 'name' => 'asset.png']), 'empty upload should be rejected');
$safeName = $guard->safeName('My Unsafe File.PNG');
assertTrue((bool)preg_match('/^my-unsafe-file-[a-f0-9]{8}\.png$/', $safeName), 'safe upload name should be normalized');

$sessionRoot = sys_get_temp_dir() . '/batoi-press-security-baseline-' . bin2hex(random_bytes(4));
mkdir($sessionRoot . '/sessions', 0775, true);
try {
    $csrf = new Csrf(new Session('batoi_press_security_' . bin2hex(random_bytes(3)), $sessionRoot . '/sessions'));
    $token = $csrf->token();
    assertTrue(strlen($token) === 64, 'CSRF token should be 32 random bytes encoded as hex');
    assertTrue($csrf->validate($token), 'CSRF should validate current token');
    assertTrue(!$csrf->validate('invalid-token'), 'CSRF should reject invalid token');
    session_write_close();

    $paths = new Paths($sessionRoot, [
        'public_root' => 'public_html',
        'app' => 'radpress/app',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ]);
    mkdir($sessionRoot . '/radpress/data/tmp', 0775, true);
    $limiter = new RateLimiter($paths, 2, 300);
    assertTrue(!$limiter->tooManyAttempts('login:test'), 'rate limiter should start clear');
    $limiter->hit('login:test');
    $limiter->hit('login:test');
    assertTrue($limiter->tooManyAttempts('login:test'), 'rate limiter should block after configured attempts');
    $limiter->clear('login:test');
    assertTrue(!$limiter->tooManyAttempts('login:test'), 'rate limiter clear should remove attempts');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    removeTree($sessionRoot);
}

assertTrue(AdminAccess::canAccess(['role' => 'editor'], '/admin/pages/save', 'POST'), 'editors should keep publishing route access');
assertTrue(!AdminAccess::canAccess(['role' => 'viewer'], '/admin/pages', 'GET'), 'viewers should not access publishing routes');
assertTrue(AdminAccess::canManagePost(['username' => 'alice', 'role' => 'author'], ['author' => 'alice']), 'authors should manage own posts');
assertTrue(!AdminAccess::canManagePost(['username' => 'alice', 'role' => 'author'], ['author' => 'bob']), 'authors should not manage other users posts');

echo "Security baseline checks passed\n";

function securityConfig(string $root): array
{
    $decoded = json_decode((string)file_get_contents($root . '/radpress/config/security.json'), true);
    return is_array($decoded) ? $decoded : [];
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
