<?php
declare(strict_types=1);

use Batoi\Press\Core\BrandAssetManager;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\ThemeManager;
use Batoi\Press\Core\Request;
use Batoi\Press\Admin\ThemeTemplateController;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/esc.php';
require dirname(__DIR__) . '/helpers/date.php';

$root = sys_get_temp_dir() . '/batoi-press-theme-branding-' . bin2hex(random_bytes(4));

try {
    foreach ([
        'public_html/assets/img/batoi-press',
        'radpress/content/assets/images/site',
        'radpress/config',
        'radpress/data/sessions',
        'radpress/data/tmp',
        'radpress/theme/demo/layouts',
        'radpress/theme/demo/assets/css',
        'radpress/theme/demo/assets/js',
    ] as $directory) {
        mkdir($root . '/' . $directory, 0775, true);
    }

    $manifest = [
        'schema' => 1,
        'slug' => 'demo',
        'name' => 'Demo Theme',
        'version' => '1.2.3',
        'author' => 'Batoi',
        'supports' => ['pages', 'brand_logo'],
        'assets' => [
            'styles' => [['file' => 'css/theme.css', 'media' => 'screen']],
            'scripts' => [['file' => 'js/theme.mjs', 'module' => true]],
        ],
    ];
    file_put_contents($root . '/radpress/theme/demo/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    foreach (['base', 'page', 'post', 'blog', 'archive', '404'] as $layout) {
        $source = $layout === 'base'
            ? '<!doctype html><html><head></head><body class="custom-preview-body"><?php echo $content; ?></body></html>'
            : '<?php echo "layout-' . $layout . '";';
        file_put_contents($root . '/radpress/theme/demo/layouts/' . $layout . '.php', $source, LOCK_EX);
    }
    file_put_contents($root . '/radpress/theme/demo/assets/css/theme.css', 'body{color:#111}', LOCK_EX);
    file_put_contents($root . '/radpress/theme/demo/assets/js/theme.mjs', 'export const ready=true;', LOCK_EX);
    copy(dirname(__DIR__, 2) . '/public_html/assets/img/batoi-press/press-color-tile-32.png', $root . '/radpress/content/assets/images/site/logo.png');

    $paths = new Paths($root, [
        'public_root' => 'public_html',
        'content' => 'radpress/content',
        'theme' => 'radpress/theme',
    ]);
    $themes = new ThemeManager($paths, new FileStore());
    $validation = $themes->validate('demo');
    assertTheme((bool)($validation['ok'] ?? false), 'valid theme should pass manifest and asset validation');
    assertTheme($themes->activeSlug(['theme' => 'demo']) === 'demo', 'configured valid theme should resolve as active');
    assertTheme($themes->resolveAsset('demo', 'css/theme.css') !== null, 'declared theme asset should resolve');
    assertTheme($themes->resolveAsset('demo', '../theme.json') === null, 'theme asset traversal should be rejected');
    assertTheme(str_contains($themes->tags('demo', 'head', false), '/theme-assets/demo/css/theme.css'), 'theme stylesheet tag should use public theme asset route');
    assertTheme(str_contains($themes->tags('demo', 'body', false), 'type="module"'), 'module theme script should retain module metadata');

    $branding = (new BrandAssetManager($paths))->branding([
        'name' => 'Example Company',
        'brand_display' => 'logo',
        'brand_logo' => '/assets/images/site/logo.png',
        'brand_logo_alt' => 'Example logo',
    ]);
    assertTheme($branding['display'] === 'logo', 'existing logo should preserve logo display mode');
    assertTheme($branding['logo_url'] === '/assets/images/site/logo.png', 'existing site logo should resolve');
    assertTheme($branding['logo_alt'] === 'Example logo', 'configured logo alt text should be preserved');

    $fallback = (new BrandAssetManager($paths))->branding([
        'name' => 'Example Company',
        'brand_display' => 'logo',
        'brand_logo' => '/assets/images/site/missing.png',
    ]);
    assertTheme($fallback['display'] === 'text' && $fallback['logo_url'] === null, 'missing logo should fall back to text without a broken image');

    $unsafeSvg = $root . '/unsafe.svg';
    file_put_contents($unsafeSvg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', LOCK_EX);
    assertTheme((new BrandAssetManager($paths))->validateSvg($unsafeSvg) !== null, 'active SVG content should be rejected');
    $safeSvg = $root . '/safe.svg';
    file_put_contents($safeSvg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>', LOCK_EX);
    assertTheme((new BrandAssetManager($paths))->validateSvg($safeSvg) === null, 'standard SVG namespace and local geometry should be accepted');
    $brandManager = new BrandAssetManager($paths);
    $storeBrand = new ReflectionMethod(BrandAssetManager::class, 'storeValidatedFile');
    $storedLogoOne = (string)$storeBrand->invoke($brandManager, $safeSvg, 'company-logo.svg', filesize($safeSvg), 'logo', false);
    assertTheme($brandManager->resolveUrl($storedLogoOne) === $storedLogoOne, 'validated logo fixture should be stored in the owned branding path');
    $storedLogoTwo = (string)$storeBrand->invoke($brandManager, $safeSvg, 'company-logo.svg', filesize($safeSvg), 'logo', false);
    assertTheme($storedLogoTwo !== $storedLogoOne && $brandManager->resolveUrl($storedLogoTwo) === $storedLogoTwo, 'replacement logo should use a new immutable path');
    assertTheme($brandManager->removeOwned($storedLogoOne) && $brandManager->resolveUrl($storedLogoOne) === null, 'superseded logo should be removable after replacement');
    assertTheme($brandManager->removeOwned($storedLogoTwo) && $brandManager->resolveUrl($storedLogoTwo) === null, 'current owned logo should support explicit removal');

    file_put_contents($root . '/radpress/config/paths.json', json_encode([
        'public_root' => 'public_html',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/config/site.json', json_encode(['name' => 'Theme Test', 'theme' => 'demo'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    $config = Config::load($root);
    $files = new FileStore();
    $session = new Session('theme_test', $root . '/radpress/data/sessions');
    $csrf = new Csrf($session);
    $controller = new ThemeTemplateController($config, $files, $csrf, new AuditLog($config->paths(), $files), ['username' => 'owner', 'role' => 'owner']);
    foreach (['home' => 'layout-page', 'page' => 'layout-page', 'post' => 'layout-post', 'blog' => 'layout-blog', 'archive' => 'layout-archive', '404' => 'layout-404'] as $previewTarget => $marker) {
        $preview = $controller->preview('demo', (string)$previewTarget)->content();
        assertTheme(str_contains($preview, 'bp-preview-banner'), 'preview banner should render for ' . $previewTarget);
        assertTheme(str_contains($preview, $marker), 'preview should render the intended ' . $previewTarget . ' layout');
        assertTheme(str_contains($preview, 'custom-preview-body'), 'preview should preserve custom body classes');
    }
    $installer = new ReflectionMethod(ThemeTemplateController::class, 'installThemeZip');

    $packageOne = $root . '/package-1.0.0.zip';
    createThemePackage($packageOne, '1.0.0', false);
    assertTheme($installer->invoke($controller, $packageOne, 'package.zip') === 'package', 'valid theme package should install');
    assertTheme(is_file($root . '/radpress/theme/package/layouts/base.php'), 'installed package should publish required layouts');

    $packageTwo = $root . '/package-1.1.0.zip';
    createThemePackage($packageTwo, '1.1.0', false);
    assertTheme($installer->invoke($controller, $packageTwo, 'package.zip') === 'package', 'existing theme slug should upgrade');
    assertTheme(str_contains((string)file_get_contents($root . '/radpress/theme/package/theme.json'), '1.1.0'), 'theme upgrade should publish the new manifest');
    assertTheme((glob($root . '/radpress/data/versions/theme-packages/package/*/theme.json') ?: []) !== [], 'theme upgrade should retain a package backup');
    $activation = $controller->activate(new Request('POST', '/admin/themes/activate', [], ['csrf_token' => $csrf->token(), 'theme' => 'package'], []));
    assertTheme($activation->status() === 302, 'valid installed theme should activate');
    $activatedSite = json_decode((string)file_get_contents($root . '/radpress/config/site.json'), true);
    assertTheme(($activatedSite['theme'] ?? '') === 'package', 'theme activation should persist the selected slug');

    $unsafePackage = $root . '/package-unsafe.zip';
    createThemePackage($unsafePackage, '1.2.0', true);
    $unsafeRejected = false;
    try {
        $installer->invoke($controller, $unsafePackage, 'package.zip');
    } catch (ReflectionException|RuntimeException $exception) {
        $unsafeRejected = str_contains($exception->getMessage(), 'restricted to layouts and partials');
    }
    assertTheme($unsafeRejected, 'theme package PHP outside approved directories should be rejected');

    echo "Theme and branding checks passed\n";
} finally {
    removeThemeFixture($root);
}

function createThemePackage(string $path, string $version, bool $unsafe): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create theme package fixture.');
    }
    $manifest = [
        'schema' => 1,
        'slug' => 'package',
        'name' => 'Package Theme',
        'version' => $version,
        'author' => 'Batoi',
        'supports' => ['pages'],
        'assets' => ['styles' => [['file' => 'css/theme.css']], 'scripts' => []],
    ];
    $zip->addFromString('package/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    foreach (['base', 'page', 'post', 'blog', 'archive', '404'] as $layout) {
        $zip->addFromString('package/layouts/' . $layout . '.php', '<?php echo "' . $layout . '-' . $version . '";');
    }
    $zip->addFromString('package/assets/css/theme.css', 'body{color:#111}');
    if ($unsafe) {
        $zip->addFromString('package/boot.php', '<?php echo "unsafe";');
    }
    $zip->close();
}

function assertTheme(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeThemeFixture(string $path): void
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
