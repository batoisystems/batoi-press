<?php
declare(strict_types=1);

use Batoi\Press\Admin\MediaController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
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
$txtFile = $mediaDir . '/asset-manager-test.txt';
$assetRoot = $config->paths()->contentPath('assets');
$typedCssFile = $assetRoot . '/styles/custom/typed-asset-test.css';
$audioFile = $assetRoot . '/multimedia/audio/2026/07/typed-audio-test.mp3';
$versionDir = $config->paths()->dataPath('versions/assets/' . substr(hash('sha256', 'assets:styles/custom/typed-asset-test.css'), 0, 20));
$replacementFile = sys_get_temp_dir() . '/typed-asset-replacement-' . bin2hex(random_bytes(4)) . '.css';
$oldGet = $_GET;

try {
    if (!is_dir($mediaDir)) {
        mkdir($mediaDir, 0775, true);
    }
    file_put_contents($cssFile, "body{color:#111}\n", LOCK_EX);
    file_put_contents($jsFile, "console.log('asset');\n", LOCK_EX);
    file_put_contents($txtFile, "asset notes\n", LOCK_EX);
    foreach ([dirname($typedCssFile), dirname($audioFile)] as $directory) {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
    file_put_contents($typedCssFile, "body{color:#222}\n", LOCK_EX);
    file_put_contents($audioFile, 'audio-fixture', LOCK_EX);

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
    assertTrue(str_contains($cssHtml, 'typed-asset-test.css'), 'Media manager should list typed CSS assets.');
    assertTrue(str_contains($cssHtml, 'styles/custom/typed-asset-test.css'), 'Media manager should show typed relative paths.');
    assertTrue(str_contains($cssHtml, '&lt;link rel=&quot;stylesheet&quot; href=&quot;/assets/styles/custom/typed-asset-test.css&quot;&gt;'), 'Media manager should provide typed asset embed snippets.');
    assertTrue(str_contains($cssHtml, '&lt;link rel=&quot;stylesheet&quot; href=&quot;/media/asset-manager-test.css&quot;&gt;'), 'Media manager should provide CSS embed snippets.');
    assertTrue(str_contains($cssHtml, 'admin/media/delete') && str_contains($cssHtml, 'bp-inline-form'), 'Media manager should provide delete actions.');
    assertTrue(str_contains($cssHtml, '/admin/media/edit?storage=assets&amp;file=styles%2Fcustom%2Ftyped-asset-test.css'), 'Media manager should provide edit actions for managed assets.');
    assertTrue(str_contains($cssHtml, 'name="storage" value="media"') && str_contains($cssHtml, 'name="file" value="asset-manager-test.css"'), 'Media manager should target legacy delete actions safely.');
    assertTrue(str_contains($cssHtml, 'data-confirm="Delete asset-manager-test.css?'), 'Media manager should confirm destructive actions.');
    assertTrue(str_contains($cssHtml, 'data-copy-target="bp-media-'), 'Media manager should provide copy controls.');
    assertTrue(str_contains($cssHtml, 'accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.css,.js,.mjs,.mp3,.wav,.ogg,.m4a,.mp4,.webm,.mov"'), 'Upload input should advertise configured file types.');
    assertTrue(str_contains($cssHtml, 'Install Library'), 'Owners should see the versioned library installation workflow.');
    assertTrue(!str_contains($cssHtml, 'asset-manager-test.js'), 'Style filter should exclude JavaScript assets.');

    $_GET = ['type' => 'scripts'];
    $jsHtml = $controller->index()->content();
    assertTrue(str_contains($jsHtml, 'asset-manager-test.js'), 'Media manager should list JavaScript assets.');
    assertTrue(str_contains($jsHtml, '&lt;script src=&quot;/media/asset-manager-test.js&quot; defer&gt;&lt;/script&gt;'), 'Media manager should provide JavaScript embed snippets.');
    assertTrue(!str_contains($jsHtml, 'asset-manager-test.css'), 'Script filter should exclude CSS assets.');

    $_GET = ['type' => 'documents'];
    $documentHtml = $controller->index()->content();
    assertTrue(str_contains($documentHtml, '&lt;a href=&quot;/media/asset-manager-test.txt&quot;&gt;Download asset-manager-test.txt&lt;/a&gt;'), 'Media manager should provide document embed snippets.');

    $_GET = ['type' => 'audio'];
    $audioHtml = $controller->index()->content();
    assertTrue(str_contains($audioHtml, '&lt;audio controls src=&quot;/assets/multimedia/audio/2026/07/typed-audio-test.mp3&quot;&gt;&lt;/audio&gt;'), 'Media manager should provide audio embed snippets.');

    $request = new Request('GET', '/admin/media/edit', ['storage' => 'assets', 'file' => 'styles/custom/typed-asset-test.css'], [], []);
    $editHtml = $controller->edit($request)->content();
    assertTrue(str_contains($editHtml, 'Edit source') && str_contains($editHtml, 'name="source"'), 'Text assets should provide a source editor.');
    assertTrue(str_contains($editHtml, 'admin/media/replace'), 'Asset editor should provide a replacement form.');
    assertTrue(str_contains($editHtml, 'accept=".css"'), 'Asset editor should restrict replacement to the existing extension.');

    $audioRequest = new Request('GET', '/admin/media/edit', ['storage' => 'assets', 'file' => 'multimedia/audio/2026/07/typed-audio-test.mp3'], [], []);
    $audioEditHtml = $controller->edit($audioRequest)->content();
    assertTrue(!str_contains($audioEditHtml, 'name="source"') && str_contains($audioEditHtml, 'accept=".mp3"'), 'Binary assets should provide replacement without a source editor.');

    $manager = new AssetManager($config->paths());
    assertSame(null, $manager->find('assets', '../config/security.php'), 'Asset lookup should reject traversal.');
    assertSame(null, $manager->find('assets', 'libraries/vendor/file.js'), 'Library files should not be individually editable.');
    $manager->updateText('assets', 'styles/custom/typed-asset-test.css', "body{color:#333}\n");
    assertSame("body{color:#333}\n", file_get_contents($typedCssFile), 'Source editing should preserve the asset path and update its contents.');
    assertTrue(is_dir($versionDir) && count(scandir($versionDir) ?: []) > 2, 'Source editing should retain a private previous version.');
    file_put_contents($replacementFile, "body{color:#444}\n", LOCK_EX);
    $manager->replace('assets', 'styles/custom/typed-asset-test.css', $replacementFile, false);
    assertSame("body{color:#444}\n", file_get_contents($typedCssFile), 'File replacement should preserve the asset path and publish new contents.');

    $editorController = new MediaController(
        $config,
        new Csrf(new Session('batoi_press_media_assets_editor_test', $config->paths()->dataPath('sessions'))),
        new AuditLog($config->paths(), $files),
        ['username' => 'editor', 'role' => 'editor']
    );
    assertTrue(!str_contains($editorController->index()->content(), 'Install Library'), 'Editors should not see executable library governance controls.');

    echo "Media asset management checks passed\n";
} finally {
    $_GET = $oldGet;
    foreach ([$cssFile, $jsFile, $txtFile, $typedCssFile, $audioFile, $replacementFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($versionDir)) {
        foreach (scandir($versionDir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_file($versionDir . '/' . $name)) {
                unlink($versionDir . '/' . $name);
            }
        }
        rmdir($versionDir);
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
