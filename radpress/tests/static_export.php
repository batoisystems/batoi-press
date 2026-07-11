<?php
declare(strict_types=1);

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\StaticExporter;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-static-export-' . bin2hex(random_bytes(4));

try {
    createFixture($root);
    $paths = new Paths($root, [
        'public_root' => 'public_html',
        'app' => 'radpress/app',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ]);

    $files = new FileStore();
    $html = new HtmlContent();
    $exporter = new StaticExporter(
        $paths,
        new PageRepository($paths, $files, $html),
        new PostRepository($paths, $files, $html),
        ['name' => 'Static Test', 'base_url' => 'https://example.test']
    );

    $status = $exporter->status();
    assertTrue((int)($status['media_files'] ?? 0) === 8, 'static export status should count typed assets and legacy media files');
    assertTrue((int)($status['asset_files'] ?? 0) === 5, 'static export status should count recursive typed assets');

    $result = $exporter->export();
    assertTrue((bool)($result['ok'] ?? false), 'static export should succeed');
    assertTrue((bool)($result['verification']['ok'] ?? false), 'static export verification should pass');

    $zipPath = (string)$result['path'];
    assertTrue(is_file($zipPath), 'static export ZIP should exist');

    $zip = new ZipArchive();
    assertTrue($zip->open($zipPath) === true, 'static export ZIP should open');
    foreach ([
        'index.html',
        'about/index.html',
        'blog/index.html',
        'blog/first-post/index.html',
        'sitemap.xml',
        'feed.xml',
        'media/sample-media.txt',
        'media/site.css',
        'media/site.js',
        'assets/styles/custom/site-custom.css',
        'assets/libraries/demo-lib/1.0.0/library.json',
        'assets/libraries/demo-lib/1.0.0/dist/demo.css',
        'assets/libraries/demo-lib/1.0.0/dist/demo.js',
        'assets/libraries/demo-lib/1.0.0/fonts/demo.woff2',
    ] as $entry) {
        assertTrue($zip->locateName($entry) !== false, "static export ZIP should include {$entry}");
    }

    assertTrue((string)$zip->getFromName('media/sample-media.txt') === "sample media\n", 'static export ZIP should include uploaded media file contents');
    assertTrue((string)$zip->getFromName('media/site.css') === "body{color:#111}\n", 'static export ZIP should include uploaded CSS file contents');
    assertTrue((string)$zip->getFromName('media/site.js') === "console.log('asset');\n", 'static export ZIP should include uploaded JS file contents');
    assertTrue((string)$zip->getFromName('assets/styles/custom/site-custom.css') === "body{margin:0}\n", 'static export ZIP should preserve typed asset paths.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), '/assets/libraries/demo-lib/1.0.0/dist/demo.css'), 'Static HTML should load enabled library styles.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), '/assets/libraries/demo-lib/1.0.0/dist/demo.js'), 'Static HTML should load enabled library scripts.');
    assertTrue($zip->locateName('admin/index.html') === false, 'static export ZIP should not include admin output');
    $zip->close();

    echo "Static export checks passed\n";
} finally {
    removeTree($root);
}

function createFixture(string $root): void
{
    foreach ([
        'public_html',
        'radpress/content/pages/home',
        'radpress/content/pages/about',
        'radpress/content/posts/first-post',
        'radpress/content/media',
        'radpress/content/assets/styles/custom',
        'radpress/content/assets/libraries/demo-lib/1.0.0/dist',
        'radpress/content/assets/libraries/demo-lib/1.0.0/fonts',
        'radpress/data/export',
        'radpress/data/tmp',
    ] as $dir) {
        mkdir($root . '/' . $dir, 0775, true);
    }

    writeContent($root . '/radpress/content/pages/home', [
        'title' => 'Home',
        'slug' => 'home',
        'status' => 'published',
    ], '<h1>Home</h1>');
    writeContent($root . '/radpress/content/pages/about', [
        'title' => 'About',
        'slug' => 'about',
        'status' => 'published',
    ], '<h1>About</h1>');
    writeContent($root . '/radpress/content/posts/first-post', [
        'title' => 'First Post',
        'slug' => 'first-post',
        'status' => 'published',
        'published_at' => date(DATE_ATOM),
    ], '<h1>First Post</h1>');
    file_put_contents($root . '/radpress/content/media/sample-media.txt', "sample media\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/media/site.css', "body{color:#111}\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/media/site.js', "console.log('asset');\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/assets/styles/custom/site-custom.css', "body{margin:0}\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/assets/libraries/demo-lib/1.0.0/library.json', json_encode([
        'name' => 'demo-lib',
        'version' => '1.0.0',
        'enabled' => true,
        'scope' => 'global',
        'styles' => ['dist/demo.css'],
        'scripts' => [['file' => 'dist/demo.js', 'defer' => true]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/assets/libraries/demo-lib/1.0.0/dist/demo.css', "@font-face{src:url(../fonts/demo.woff2)}\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/assets/libraries/demo-lib/1.0.0/dist/demo.js', "window.demo=true;\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/assets/libraries/demo-lib/1.0.0/fonts/demo.woff2', 'font-data', LOCK_EX);
}

function writeContent(string $dir, array $meta, string $body): void
{
    file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($dir . '/body.html', $body, LOCK_EX);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
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
