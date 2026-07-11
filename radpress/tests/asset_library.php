<?php
declare(strict_types=1);

use Batoi\Press\Core\AssetLibraryManager;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Theme;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-assets-' . bin2hex(random_bytes(4));

try {
    foreach (['radpress/content/assets', 'radpress/content/media', 'radpress/data/tmp', 'radpress/theme/default/layouts'] as $directory) {
        mkdir($root . '/' . $directory, 0775, true);
    }
    $paths = new Paths($root, [
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ]);
    $assets = new AssetManager($paths);
    $date = new DateTimeImmutable('2026-07-11T10:00:00+05:30');
    assertSame('images/2026/07/cover-a1.png', $assets->relativeUploadPath('cover-a1.png', $date), 'Images should use dated image storage.');
    assertSame('documents/2026/07/guide-a1.pdf', $assets->relativeUploadPath('guide-a1.pdf', $date), 'Documents should use dated document storage.');
    assertSame('multimedia/audio/2026/07/theme-a1.mp3', $assets->relativeUploadPath('theme-a1.mp3', $date), 'Audio should use dated multimedia storage.');
    assertSame('multimedia/video/2026/07/demo-a1.mp4', $assets->relativeUploadPath('demo-a1.mp4', $date), 'Video should use dated multimedia storage.');
    assertSame('styles/custom/site-a1.css', $assets->relativeUploadPath('site-a1.css', $date), 'CSS should use custom style storage.');
    assertSame('scripts/custom/app-a1.mjs', $assets->relativeUploadPath('app-a1.mjs', $date), 'Scripts should use custom script storage.');

    $typedPath = $assets->prepareTarget('styles/custom/site-a1.css');
    file_put_contents($typedPath, "body{color:#111}\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/media/legacy.txt', "legacy\n", LOCK_EX);
    $records = $assets->all();
    assertTrue(count($records) === 2, 'Asset listing should combine typed and legacy files.');
    assertTrue($assets->resolveAsset('styles/custom/site-a1.css') === realpath($typedPath), 'Typed assets should resolve inside the asset root.');
    assertTrue($assets->resolveAsset('../media/legacy.txt') === null, 'Asset resolution should reject traversal.');

    $zipPath = $root . '/library.zip';
    createLibraryZip($zipPath, [
        'library.json' => json_encode([
            'name' => 'demo-kit',
            'version' => '2.1.0',
            'enabled' => true,
            'scope' => 'global',
            'styles' => [['file' => 'dist/demo.css', 'media' => 'screen']],
            'scripts' => [['file' => 'dist/demo.mjs', 'module' => true]],
        ], JSON_UNESCAPED_SLASHES),
        'dist/demo.css' => '@font-face{font-family:Demo;src:url(../fonts/demo.woff2)}',
        'dist/demo.mjs' => 'export const demo = true;',
        'fonts/demo.woff2' => 'font-data',
    ]);
    $libraries = new AssetLibraryManager($paths);
    $manifest = $libraries->installZip($zipPath);
    assertSame('demo-kit', $manifest['name'], 'Library installation should return the normalized manifest.');
    assertTrue(is_file($root . '/radpress/content/assets/libraries/demo-kit/2.1.0/fonts/demo.woff2'), 'Library installation should preserve dependency paths.');
    assertTrue(str_contains($libraries->tags('head', false), '/assets/libraries/demo-kit/2.1.0/dist/demo.css'), 'Enabled library CSS should render globally.');
    assertTrue(str_contains($libraries->tags('body', false), 'type="module"'), 'MJS entry points should render as modules.');
    file_put_contents($root . '/radpress/theme/default/layouts/page.php', '<?php echo "<main>Page</main>";', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/base.php', '<!doctype html><html><head></head><body><?php echo $content; ?></body></html>', LOCK_EX);
    $themeHtml = (new Theme($paths, ['theme' => 'default']))->render('page')->content();
    assertTrue(str_contains($themeHtml, '/assets/libraries/demo-kit/2.1.0/dist/demo.css'), 'Public theme output should inject enabled library styles.');
    assertTrue(str_contains($themeHtml, '/assets/libraries/demo-kit/2.1.0/dist/demo.mjs'), 'Public theme output should inject enabled library scripts.');
    assertThrows(fn () => $libraries->installZip($zipPath), 'already installed', 'Duplicate library versions should be rejected.');

    $newVersionZip = $root . '/library-new.zip';
    createLibraryZip($newVersionZip, [
        'library.json' => json_encode(['name' => 'demo-kit', 'version' => '2.2.0', 'enabled' => true, 'styles' => ['demo.css']]),
        'demo.css' => 'body{color:#222}',
    ]);
    $libraries->installZip($newVersionZip);
    $installedVersions = $libraries->all();
    $enabledVersions = array_values(array_filter($installedVersions, static fn (array $library): bool => (bool)$library['enabled']));
    assertTrue(count($enabledVersions) === 1 && $enabledVersions[0]['version'] === '2.2.0', 'Installing an enabled version should disable sibling versions.');
    $libraries->delete('demo-kit', '2.2.0');

    $libraries->setEnabled('demo-kit', '2.1.0', false);
    assertSame('', $libraries->tags('head', false), 'Disabled libraries should not render tags.');
    $libraries->setEnabled('demo-kit', '2.1.0', true);

    $unsafeZip = $root . '/unsafe.zip';
    createLibraryZip($unsafeZip, [
        'library.json' => json_encode(['name' => 'unsafe', 'version' => '1.0.0', 'styles' => ['safe.css']]),
        'safe.css' => 'body{}',
        '../escape.css' => 'body{}',
    ]);
    assertThrows(fn () => $libraries->installZip($unsafeZip), 'unsafe path', 'Traversal entries should be rejected.');
    assertTrue(!is_file($root . '/escape.css'), 'Rejected library ZIPs must not write outside staging.');

    $executableZip = $root . '/executable.zip';
    createLibraryZip($executableZip, [
        'library.json' => json_encode(['name' => 'executable', 'version' => '1.0.0', 'scripts' => ['safe.js']]),
        'safe.js' => 'window.safe=true;',
        'shell.php' => '<?php echo "unsafe";',
    ]);
    assertThrows(fn () => $libraries->installZip($executableZip), 'unsupported file type', 'Server-executable library contents should be rejected.');

    $missingZip = $root . '/missing.zip';
    createLibraryZip($missingZip, [
        'library.json' => json_encode(['name' => 'missing', 'version' => '1.0.0', 'styles' => ['missing.css']]),
        'readme.json' => '{}',
    ]);
    assertThrows(fn () => $libraries->installZip($missingZip), 'entry point is missing', 'Missing declared entry points should reject the package.');
    assertTrue(!is_dir($root . '/radpress/content/assets/libraries/missing'), 'Failed packages should not leave partial installations.');

    $libraries->delete('demo-kit', '2.1.0');
    assertTrue($libraries->all() === [], 'Deleted library versions should leave the registry clean.');

    echo "Asset and library checks passed\n";
} finally {
    removeTree($root);
}

function createLibraryZip(string $path, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test ZIP.');
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, (string)$contents);
    }
    $zip->close();
}

function assertThrows(callable $callback, string $messagePart, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        if (str_contains(strtolower($exception->getMessage()), strtolower($messagePart))) {
            return;
        }
        throw new RuntimeException($message . ' Unexpected error: ' . $exception->getMessage());
    }
    throw new RuntimeException($message . ' No exception was thrown.');
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
