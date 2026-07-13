<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../helpers/esc.php';
require __DIR__ . '/../helpers/url.php';
require __DIR__ . '/../helpers/date.php';

use Batoi\Press\Core\App;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\BrandAssetManager;
use Batoi\Press\Security\Auth;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\Session;

$root = dirname(__DIR__, 2);
$paths = ['/', '/about', '/blog', '/blog/first-blog-post', '/sitemap.xml', '/feed.xml', '/admin', '/admin/login', '/admin/pages', '/admin/posts', '/admin/media', '/admin/menus', '/admin/settings', '/admin/themes', '/admin/theme-templates', '/admin/users', '/admin/audit', '/admin/cache', '/admin/export-static', '/admin/aif', '/admin/updates'];
$mediaFile = $root . '/radpress/content/media/smoke-test.txt';
$mediaCssFile = $root . '/radpress/content/media/smoke-test.css';
$mediaJsFile = $root . '/radpress/content/media/smoke-test.js';
$assetCssFile = $root . '/radpress/content/assets/styles/custom/smoke-typed.css';
$assetMjsFile = $root . '/radpress/content/assets/scripts/custom/smoke-typed.mjs';
foreach ([dirname($assetCssFile), dirname($assetMjsFile)] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}
file_put_contents($mediaFile, 'media ok', LOCK_EX);
file_put_contents($mediaCssFile, 'body{background:#fff}', LOCK_EX);
file_put_contents($mediaJsFile, 'window.batoiPressSmoke=true;', LOCK_EX);
file_put_contents($assetCssFile, 'body{color:#111}', LOCK_EX);
file_put_contents($assetMjsFile, 'export const smoke=true;', LOCK_EX);
$paths[] = '/media/smoke-test.txt';
$paths[] = '/media/smoke-test.css';
$paths[] = '/media/smoke-test.js';
$paths[] = '/assets/styles/custom/smoke-typed.css';
$paths[] = '/assets/scripts/custom/smoke-typed.mjs';

try {
    $brandAssets = new BrandAssetManager(new Paths($root));
    $faviconValidator = new ReflectionMethod(BrandAssetManager::class, 'validateRaster');
    $icoPath = sys_get_temp_dir() . '/batoi-press-valid-' . bin2hex(random_bytes(4)) . '.ico';
    $invalidIcoPath = sys_get_temp_dir() . '/batoi-press-invalid-' . bin2hex(random_bytes(4)) . '.ico';
    $icoPayload = str_repeat("\x00", 40);
    $icoHeader = pack('vvv', 0, 1, 1);
    $icoEntry = pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($icoPayload), 22);
    file_put_contents($icoPath, $icoHeader . $icoEntry . $icoPayload, LOCK_EX);
    file_put_contents($invalidIcoPath, 'not an icon', LOCK_EX);
    try {
        if ($faviconValidator->invoke($brandAssets, $icoPath, 'ico', 'favicon') !== null) {
            throw new RuntimeException('A structurally valid ICO favicon should pass validation.');
        }
        if ($faviconValidator->invoke($brandAssets, $root . '/public_html/assets/img/batoi-press/press-color-tile-32.png', 'ico', 'favicon') !== null) {
            throw new RuntimeException('A browser-compatible PNG favicon with an ICO filename should pass validation.');
        }
        if ($faviconValidator->invoke($brandAssets, $invalidIcoPath, 'ico', 'favicon') === null) {
            throw new RuntimeException('An invalid ICO favicon should fail validation.');
        }
    } finally {
        @unlink($icoPath);
        @unlink($invalidIcoPath);
    }

    if (!is_file($root . '/public_html/assets/uif/uif.css')) {
        throw new RuntimeException('Batoi UIF stylesheet is missing.');
    }
    foreach ([
        'public_html/assets/uif/uif.css' => 50000,
        'public_html/assets/uif/uif.iife.js' => 250000,
        'public_html/assets/uif/uif.life.js' => 250000,
        'public_html/assets/uif/uif.esm.js' => 250000,
        'public_html/assets/img/press-color.svg' => 100,
        'public_html/assets/img/batoi-press/press-color.svg' => 100,
        'public_html/assets/img/batoi-press/press-color-tile-180.png' => 1000,
        'public_html/assets/img/batoi-press/press-color-tile-512.png' => 5000,
        'public_html/assets/img/batoi-press/press-mono.svg' => 100,
    ] as $asset => $minimumBytes) {
        $path = $root . '/' . $asset;
        if (!is_file($path) || filesize($path) < $minimumBytes) {
            throw new RuntimeException("Batoi UIF release asset is missing or incomplete: {$asset}");
        }
    }

    $aifStatus = (new AifManager(Config::load($root)->aif()))->status();
    if (($aifStatus['enabled'] ?? true) !== false || ($aifStatus['available'] ?? true) !== false) {
        throw new RuntimeException('Batoi AIF should be disabled and unavailable by default.');
    }

    $authRoot = sys_get_temp_dir() . '/batoi-press-auth-smoke-' . bin2hex(random_bytes(4));
    mkdir($authRoot . '/config', 0775, true);
    mkdir($authRoot . '/data/sessions', 0775, true);
    file_put_contents($authRoot . '/config/users.json', json_encode([
        'users' => [
            [
                'username' => 'enabled-user',
                'role' => 'owner',
                'password_hash' => Password::hash('password-12345'),
                'created_at' => date(DATE_ATOM),
            ],
            [
                'username' => 'disabled-user',
                'role' => 'admin',
                'password_hash' => Password::hash('password-12345'),
                'disabled_at' => date(DATE_ATOM),
                'created_at' => date(DATE_ATOM),
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    $auth = new Auth(new Paths($authRoot), new Session('batoi_press_auth_smoke', $authRoot . '/data/sessions'), new FileStore());
    if (!$auth->attempt('enabled-user', 'password-12345')) {
        throw new RuntimeException('Enabled users should be able to authenticate.');
    }
    $auth->logout();
    if ($auth->attempt('disabled-user', 'password-12345')) {
        throw new RuntimeException('Disabled users should not be able to authenticate.');
    }

    foreach ($paths as $path) {
        $response = (new App($root))->handle(new Request('GET', $path, [], [], []));
        ob_start();
        $response->send();
        $body = (string)ob_get_clean();
        if ($body === '') {
            throw new RuntimeException("Empty response for {$path}");
        }

        if ($path === '/about' && !str_contains($body, '<title>About Batoi Press</title>')) {
            throw new RuntimeException("SEO title did not render for {$path}");
        }

        if ($path === '/media/smoke-test.txt' && $body !== 'media ok') {
            throw new RuntimeException("Media response mismatch for {$path}");
        }
        if ($path === '/media/smoke-test.css' && $body !== 'body{background:#fff}') {
            throw new RuntimeException("Media response mismatch for {$path}");
        }
        if ($path === '/media/smoke-test.css') {
            assertMediaHeaders($response, 'text/css; charset=UTF-8');
        }
        if ($path === '/media/smoke-test.js' && $body !== 'window.batoiPressSmoke=true;') {
            throw new RuntimeException("Media response mismatch for {$path}");
        }
        if ($path === '/media/smoke-test.js') {
            assertMediaHeaders($response, 'application/javascript; charset=UTF-8');
        }
        if ($path === '/assets/styles/custom/smoke-typed.css' && $body !== 'body{color:#111}') {
            throw new RuntimeException("Typed asset response mismatch for {$path}");
        }
        if ($path === '/assets/styles/custom/smoke-typed.css') {
            assertMediaHeaders($response, 'text/css; charset=UTF-8');
        }
        if ($path === '/assets/scripts/custom/smoke-typed.mjs' && $body !== 'export const smoke=true;') {
            throw new RuntimeException("Typed asset response mismatch for {$path}");
        }
        if ($path === '/assets/scripts/custom/smoke-typed.mjs') {
            assertMediaHeaders($response, 'application/javascript; charset=UTF-8');
        }
    }
} finally {
    foreach ([$mediaFile, $mediaCssFile, $mediaJsFile, $assetCssFile, $assetMjsFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (isset($authRoot) && is_dir($authRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($authRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
        }
        rmdir($authRoot);
    }
}

echo "Smoke checks passed\n";

function assertMediaHeaders(Batoi\Press\Core\Response $response, string $contentType): void
{
    $headers = $response->headers();
    if (($headers['Content-Type'] ?? '') !== $contentType) {
        throw new RuntimeException('Unexpected media content type.');
    }
    if (($headers['X-Content-Type-Options'] ?? '') !== 'nosniff') {
        throw new RuntimeException('Media responses should disable content sniffing.');
    }
    $cacheControl = (string)($headers['Cache-Control'] ?? '');
    if (!str_contains($cacheControl, 'must-revalidate') || str_contains($cacheControl, 'immutable')) {
        throw new RuntimeException('Replaceable media should be revalidated at its stable public URL.');
    }
}
