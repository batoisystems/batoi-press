<?php
declare(strict_types=1);

use Batoi\Press\Core\ThemeCompatibilityInspector;

require dirname(__DIR__) . '/autoload.php';

if (!class_exists(ZipArchive::class)) {
    echo "Theme compatibility checks skipped: ZipArchive unavailable\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/batoi-press-theme-inspector-' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$inspector = new ThemeCompatibilityInspector();

try {
    $press = $root . '/press.zip';
    createZip($press, pressFiles());
    $pressReport = $inspector->inspect($press, 'press.zip');
    assertThemeCompatibility(($pressReport['classification'] ?? '') === 'compatible', 'A complete safe Press theme should be compatible.');
    assertThemeCompatibility(($pressReport['installable'] ?? false) === true, 'A compatible Press theme should be installable.');
    assertThemeCompatibility(($pressReport['contract'] ?? '') === ThemeCompatibilityInspector::CONTRACT_VERSION, 'Reports should expose the versioned conversion contract.');

    $static = $root . '/static.zip';
    createZip($static, [
        'portfolio/index.html' => '<!doctype html><html><body><header><nav>Home</nav></header><main><h1>Portfolio</h1></main></body></html>',
        'portfolio/assets/theme.css' => 'body{font-family:sans-serif;color:#111}',
        'portfolio/assets/theme.js' => 'document.documentElement.classList.add("ready");',
    ]);
    $staticReport = $inspector->inspect($static, 'portfolio.zip');
    assertThemeCompatibility(($staticReport['classification'] ?? '') === 'convertible', 'A static site template should require conversion.');
    assertThemeCompatibility(($staticReport['source_type'] ?? '') === 'static_html', 'Static HTML should be classified explicitly.');
    assertThemeCompatibility(($staticReport['requires_platform'] ?? false) === true, 'Third-party presentation source should route to Platform.');

    $wordpress = $root . '/wordpress.zip';
    createZip($wordpress, [
        'sample/style.css' => "/*\nTheme Name: Sample\n*/\nbody{color:#111}",
        'sample/functions.php' => '<?php add_action("after_setup_theme", static function (): void {});',
        'sample/index.php' => '<?php echo "presentation";',
    ]);
    $wordpressReport = $inspector->inspect($wordpress, 'sample-wordpress.zip');
    assertThemeCompatibility(($wordpressReport['classification'] ?? '') === 'convertible', 'A conventional WordPress presentation theme should be convertible, not executable.');
    assertThemeCompatibility(($wordpressReport['source_type'] ?? '') === 'wordpress', 'WordPress source should be identified.');
    assertThemeCompatibility(hasCheck($wordpressReport, 'press.php_location', 'warn'), 'WordPress PHP outside Press layout paths should be reported as discarded executable source.');

    $repairable = $root . '/repairable.zip';
    $repairableFiles = pressFiles();
    unset($repairableFiles['candidate/layouts/archive.php']);
    createZip($repairable, $repairableFiles);
    $repairableReport = $inspector->inspect($repairable, 'repairable.zip');
    assertThemeCompatibility(($repairableReport['classification'] ?? '') === 'repairable', 'An incomplete Press-shaped package should require repair.');
    assertThemeCompatibility(in_array('layouts/archive.php', (array)($repairableReport['missing_required_files'] ?? []), true), 'Repair report should name missing Press files.');

    $unsafe = $root . '/unsafe.zip';
    createZip($unsafe, [
        '../escape.php' => '<?php echo "escape";',
        'candidate/theme.json' => '{}',
    ]);
    $unsafeReport = $inspector->inspect($unsafe, 'unsafe.zip');
    assertThemeCompatibility(($unsafeReport['classification'] ?? '') === 'unsafe', 'Path traversal should make the package unsafe.');
    assertThemeCompatibility(hasCheck($unsafeReport, 'archive.path', 'fail'), 'Unsafe paths should be included in the report.');

    $secret = $root . '/secret.zip';
    $secretFiles = pressFiles();
    $secretFiles['candidate/assets/js/theme.js'] = 'const api_key = "secret-value-123";';
    createZip($secret, $secretFiles);
    $secretReport = $inspector->inspect($secret, 'secret.zip');
    assertThemeCompatibility(($secretReport['classification'] ?? '') === 'unsafe', 'Embedded credential patterns should make a package unsafe.');
    assertThemeCompatibility(hasCheck($secretReport, 'content.secrets', 'fail'), 'Secret findings should be explicit without exposing the value.');

    echo "Theme compatibility checks passed\n";
} finally {
    removeThemeCompatibilityFixture($root);
}

function pressFiles(): array
{
    $manifest = [
        'schema' => 1,
        'slug' => 'candidate',
        'name' => 'Candidate',
        'version' => '1.0.0',
        'author' => 'Test',
        'supports' => ['pages', 'posts', 'menus'],
        'assets' => [
            'styles' => [['file' => 'css/theme.css']],
            'scripts' => [['file' => 'js/theme.js', 'defer' => true]],
        ],
    ];
    $files = ['candidate/theme.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"];
    foreach (['base', 'page', 'post', 'blog', 'archive', '404'] as $layout) {
        $files['candidate/layouts/' . $layout . '.php'] = '<?php declare(strict_types=1); ?><main><?php echo $content ?? ""; ?></main>';
    }
    $files['candidate/assets/css/theme.css'] = 'body{color:#111}';
    $files['candidate/assets/js/theme.js'] = 'document.documentElement.classList.add("theme-ready");';
    return $files;
}

function createZip(string $path, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create theme inspector fixture.');
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
}

function hasCheck(array $report, string $code, string $status): bool
{
    foreach ((array)($report['checks'] ?? []) as $check) {
        if (($check['code'] ?? '') === $code && ($check['status'] ?? '') === $status) return true;
    }
    return false;
}

function assertThemeCompatibility(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeThemeCompatibilityFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($path);
}
