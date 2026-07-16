<?php
declare(strict_types=1);

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Admin\ExportController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\StaticExporter;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/esc.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/date.php';

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
        [
            'name' => 'Static Test',
            'tagline' => 'Rendered through the active theme.',
            'base_url' => 'https://example.test',
            'homepage' => 'about',
            'theme' => 'default',
            'brand_display' => 'logo',
            'brand_logo' => '/assets/images/site/logo.png',
            'brand_logo_alt' => 'Static Test logo',
        ]
    );

    $status = $exporter->status();
    assertTrue((int)($status['media_files'] ?? 0) === 9, 'static export status should count typed assets and legacy media files');
    assertTrue((int)($status['asset_files'] ?? 0) === 6, 'static export status should count recursive typed assets');

    chmod($root . '/radpress/data/export', 0555);
    chmod($root . '/radpress/data/tmp', 0555);
    $session = new Session('static_export_test', $root . '/radpress/data/sessions');
    $controller = new ExportController($exporter, new Csrf($session), new AuditLog($paths, $files), ['username' => 'owner', 'role' => 'owner']);
    $exportPage = $controller->index()->content();
    assertTrue(str_contains($exportPage, '<span>Generate Export</span>'), 'static export action should render as a button');
    assertTrue(!preg_match('/<button[^>]*disabled[^>]*>.*?<span>Generate Export<\/span>/s', $exportPage), 'static export action should remain clickable when readiness checks report a problem');
    chmod($root . '/radpress/data/export', 0775);
    chmod($root . '/radpress/data/tmp', 0775);

    $result = $exporter->export();
    assertTrue((bool)($result['ok'] ?? false), 'static export should succeed');
    assertTrue((bool)($result['verification']['ok'] ?? false), 'static export verification should pass');

    $zipPath = (string)$result['path'];
    assertTrue(is_file($zipPath), 'static export ZIP should exist');

    $zip = new ZipArchive();
    assertTrue($zip->open($zipPath) === true, 'static export ZIP should open');
    foreach ([
        'index.html',
        'home/index.html',
        'blog/index.html',
        'blog/first-post/index.html',
        'archive/index.html',
        '404.html',
        'sitemap.xml',
        'feed.xml',
        'media/sample-media.txt',
        'media/site.css',
        'media/site.js',
        'assets/styles/custom/site-custom.css',
        'assets/images/site/logo.png',
        'assets/libraries/demo-lib/1.0.0/library.json',
        'assets/libraries/demo-lib/1.0.0/dist/demo.css',
        'assets/libraries/demo-lib/1.0.0/dist/demo.js',
        'assets/libraries/demo-lib/1.0.0/fonts/demo.woff2',
        'theme-assets/default/css/theme.css',
        'theme-assets/default/js/theme.js',
    ] as $entry) {
        assertTrue($zip->locateName($entry) !== false, "static export ZIP should include {$entry}");
    }

    assertTrue((string)$zip->getFromName('media/sample-media.txt') === "sample media\n", 'static export ZIP should include uploaded media file contents');
    assertTrue((string)$zip->getFromName('media/site.css') === "body{color:#111}\n", 'static export ZIP should include uploaded CSS file contents');
    assertTrue((string)$zip->getFromName('media/site.js') === "console.log('asset');\n", 'static export ZIP should include uploaded JS file contents');
    assertTrue((string)$zip->getFromName('assets/styles/custom/site-custom.css') === "body{margin:0}\n", 'static export ZIP should preserve typed asset paths.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), './assets/libraries/demo-lib/1.0.0/dist/demo.css'), 'Static HTML should load enabled library styles.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), './assets/libraries/demo-lib/1.0.0/dist/demo.js'), 'Static HTML should load enabled library scripts.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), 'class="bp-header"'), 'Static HTML should use the active theme header.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), 'bp-template-landing'), 'Static HTML should preserve the selected page template.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), 'bp-page-landing'), 'Static HTML should render the selected landing layout.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), './assets/images/site/logo.png'), 'Static HTML should render the configured brand logo.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), './theme-assets/default/css/theme.css'), 'Static HTML should load declared theme styles.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), './theme-assets/default/js/theme.js'), 'Static HTML should load declared theme scripts.');
    assertTrue(!str_contains((string)$zip->getFromName('index.html'), '../assets/'), 'Configured homepage assets should resolve from the export root.');
    assertTrue(str_contains((string)$zip->getFromName('sitemap.xml'), '<loc>https://example.test/</loc>'), 'Configured homepage should use the root sitemap URL.');
    assertTrue(!str_contains((string)$zip->getFromName('sitemap.xml'), '<loc>https://example.test/about/</loc>'), 'Configured homepage slug should not be duplicated in the sitemap.');
    assertTrue(str_contains((string)$zip->getFromName('blog/first-post/index.html'), 'alt="Featured release artwork"'), 'Static posts should preserve featured image alt text.');
    assertTrue(str_contains((string)$zip->getFromName('blog/first-post/index.html'), '<h2>Call to action</h2>'), 'Static sidebar posts should include configured widgets.');
    assertTrue(str_contains((string)$zip->getFromName('blog/first-post/index.html'), '<h2>Recent posts</h2>'), 'Static sidebar posts should include recent posts.');
    assertTrue(str_contains((string)$zip->getFromName('blog/first-post/index.html'), '../../assets/images/site/logo.png'), 'Nested static posts should resolve root assets with a depth-aware relative path.');
    assertTrue(str_contains((string)$zip->getFromName('index.html'), 'Rendered through the active theme.'), 'Static HTML should use the active theme footer.');
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
        'radpress/content/widgets',
        'radpress/content/media',
        'radpress/content/assets/styles/custom',
        'radpress/content/assets/images/site',
        'radpress/content/assets/libraries/demo-lib/1.0.0/dist',
        'radpress/content/assets/libraries/demo-lib/1.0.0/fonts',
        'radpress/data/export',
        'radpress/data/tmp',
        'radpress/theme',
    ] as $dir) {
        mkdir($root . '/' . $dir, 0775, true);
    }

    writeContent($root . '/radpress/content/pages/home', [
        'title' => 'Home',
        'slug' => 'home',
        'status' => 'published',
        'template' => 'landing',
    ], '<h1>Home</h1>');
    writeContent($root . '/radpress/content/pages/about', [
        'title' => 'About',
        'slug' => 'about',
        'status' => 'published',
        'template' => 'landing',
    ], '<h1>About</h1>');
    writeContent($root . '/radpress/content/posts/first-post', [
        'title' => 'First Post',
        'slug' => 'first-post',
        'status' => 'published',
        'published_at' => date(DATE_ATOM),
        'featured_image' => '/assets/images/site/logo.png',
        'featured_image_alt' => 'Featured release artwork',
        'layout' => 'sidebar-right',
    ], '<h1>First Post</h1>');
    file_put_contents($root . '/radpress/content/widgets/sidebar.json', json_encode(['widgets' => [[
        'title' => 'Call to action',
        'body' => '<p>Contact our team.</p>',
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/media/sample-media.txt', "sample media\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/media/site.css', "body{color:#111}\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/media/site.js', "console.log('asset');\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/assets/styles/custom/site-custom.css', "body{margin:0}\n", LOCK_EX);
    copy(dirname(__DIR__, 2) . '/public_html/assets/img/batoi-press/press-color-tile-32.png', $root . '/radpress/content/assets/images/site/logo.png');
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

    copyTree(dirname(__DIR__) . '/theme/default', $root . '/radpress/theme/default');
    if (!is_dir($root . '/radpress/theme/default/assets/css')) {
        mkdir($root . '/radpress/theme/default/assets/css', 0775, true);
    }
    if (!is_dir($root . '/radpress/theme/default/assets/js')) {
        mkdir($root . '/radpress/theme/default/assets/js', 0775, true);
    }
    file_put_contents($root . '/radpress/theme/default/assets/css/theme.css', '.theme{display:block}', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/assets/js/theme.js', 'window.themeReady=true;', LOCK_EX);
    $manifest = json_decode((string)file_get_contents($root . '/radpress/theme/default/theme.json'), true);
    $manifest['assets'] = [
        'styles' => [['file' => 'css/theme.css', 'media' => 'all']],
        'scripts' => [['file' => 'js/theme.js', 'defer' => true]],
    ];
    file_put_contents($root . '/radpress/theme/default/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

function copyTree(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0775, true);
    }
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        is_dir($source . '/' . $entry)
            ? copyTree($source . '/' . $entry, $target . '/' . $entry)
            : copy($source . '/' . $entry, $target . '/' . $entry);
    }
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
