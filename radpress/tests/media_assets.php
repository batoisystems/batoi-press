<?php
declare(strict_types=1);

use Batoi\Press\Admin\MediaController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\UploadGuard;

require dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/url.php';

$root = dirname(__DIR__, 2);
$config = Config::load($root);
$files = new FileStore();
$mediaDir = $config->paths()->contentPath('media');
$cssFile = $mediaDir . '/asset-manager-test.css';
$jsFile = $mediaDir . '/asset-manager-test.js';
$oldGet = $_GET;

try {
    if (!is_dir($mediaDir)) {
        mkdir($mediaDir, 0775, true);
    }
    file_put_contents($cssFile, "body{color:#111}\n", LOCK_EX);
    file_put_contents($jsFile, "console.log('asset');\n", LOCK_EX);

    $uploads = (array)($config->security()['uploads'] ?? []);
    $guard = new UploadGuard((array)($uploads['allowed_extensions'] ?? []), (int)($uploads['max_bytes'] ?? 5242880));
    assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 32, 'name' => 'site.css']), 'CSS uploads should be allowed.');
    assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 32, 'name' => 'site.js']), 'JS uploads should be allowed.');

    $controller = new MediaController(
        $config,
        new Csrf(new Session('batoi_press_media_assets_test', $config->paths()->dataPath('sessions'))),
        new AuditLog($config->paths(), $files),
        ['username' => 'admin', 'role' => 'owner']
    );

    $_GET = ['type' => 'styles'];
    $cssHtml = $controller->index()->content();
    assertTrue(str_contains($cssHtml, 'asset-manager-test.css'), 'Media manager should list CSS assets.');
    assertTrue(str_contains($cssHtml, '&lt;link rel=&quot;stylesheet&quot; href=&quot;/media/asset-manager-test.css&quot;&gt;'), 'Media manager should provide CSS embed snippets.');
    assertTrue(str_contains($cssHtml, 'admin/media/delete') && str_contains($cssHtml, 'bp-inline-form'), 'Media manager should provide delete actions.');
    assertTrue(str_contains($cssHtml, 'name="file" value="asset-manager-test.css"'), 'Media manager should target delete actions to the selected asset.');
    assertTrue(!str_contains($cssHtml, 'asset-manager-test.js'), 'Style filter should exclude JavaScript assets.');

    $_GET = ['type' => 'scripts'];
    $jsHtml = $controller->index()->content();
    assertTrue(str_contains($jsHtml, 'asset-manager-test.js'), 'Media manager should list JavaScript assets.');
    assertTrue(str_contains($jsHtml, '&lt;script src=&quot;/media/asset-manager-test.js&quot; defer&gt;&lt;/script&gt;'), 'Media manager should provide JavaScript embed snippets.');
    assertTrue(!str_contains($jsHtml, 'asset-manager-test.css'), 'Script filter should exclude CSS assets.');

    echo "Media asset management checks passed\n";
} finally {
    $_GET = $oldGet;
    foreach ([$cssFile, $jsFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
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
