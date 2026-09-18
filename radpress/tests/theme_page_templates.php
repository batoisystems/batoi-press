<?php
declare(strict_types=1);

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Theme;
use Batoi\Press\Core\ThemeManager;

require dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/url.php';
require_once dirname(__DIR__) . '/helpers/esc.php';
require_once dirname(__DIR__) . '/helpers/date.php';

$root = sys_get_temp_dir() . '/batoi-press-page-templates-' . bin2hex(random_bytes(4));

try {
    foreach (['radpress/theme/demo/layouts', 'radpress/content/pages', 'radpress/data/versions/pages', 'radpress/content/assets'] as $directory) {
        mkdir($root . '/' . $directory, 0775, true);
    }
    file_put_contents($root . '/radpress/theme/demo/theme.json', json_encode([
        'schema' => 1,
        'slug' => 'demo',
        'name' => 'Template Demo',
        'version' => '1.0.0',
        'author' => 'Batoi',
        'supports' => ['pages', 'ecommerce_pages'],
        'page_templates' => [
            'landing' => ['label' => 'Landing Page', 'layout' => 'landing'],
            'shop' => ['label' => 'Shop', 'layout' => 'shop'],
            '../unsafe' => ['label' => 'Unsafe', 'layout' => '../unsafe'],
        ],
        'assets' => ['styles' => [], 'scripts' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    foreach (['base', 'page', 'post', 'blog', 'archive', '404', 'landing', 'shop'] as $layout) {
        $source = $layout === 'base'
            ? '<!doctype html><html><head></head><body><?php echo $content; ?></body></html>'
            : '<div data-layout="' . $layout . '">Template fixture</div>';
        file_put_contents($root . '/radpress/theme/demo/layouts/' . $layout . '.php', $source, LOCK_EX);
    }

    $paths = new Paths($root, ['content' => 'radpress/content', 'data' => 'radpress/data', 'theme' => 'radpress/theme']);
    $manager = new ThemeManager($paths);
    $templates = $manager->pageTemplates('demo');
    assertTemplate(isset($templates['page'], $templates['landing'], $templates['shop']), 'standard and declared page templates should normalize');
    assertTemplate(!isset($templates['../unsafe']), 'unsafe page-template keys should be discarded');
    assertTemplate($manager->resolvePageLayout('demo', 'shop') === 'shop', 'declared shop template should resolve');
    assertTemplate($manager->resolvePageLayout('demo', 'missing') === 'page', 'unknown template should fall back to page');
    assertTemplate(str_ends_with($manager->assetUrl('demo', 'css/theme.css', false), '?v=1.0.0'), 'theme asset URLs should include the manifest version for cache invalidation');

    $pages = new PageRepository($paths, new FileStore(), new HtmlContent());
    $saved = $pages->save(['title' => 'Store', 'slug' => 'store', 'status' => 'published', 'template' => 'shop', 'show_latest_posts' => '1', 'latest_posts_limit' => '30', 'body' => '<h1>Store</h1>'], 'owner');
    assertTemplate(($saved['template'] ?? '') === 'shop', 'selected template should persist in page metadata');
    assertTemplate(($saved['show_latest_posts'] ?? false) === true, 'latest-posts visibility should persist in page metadata');
    assertTemplate(($saved['latest_posts_limit'] ?? 0) === 12, 'latest-posts limit should be constrained to the supported range');
    $unsafe = $pages->save(['title' => 'Unsafe', 'slug' => 'unsafe', 'status' => 'draft', 'template' => '../shop', 'body' => '<p>Unsafe</p>'], 'owner');
    assertTemplate(($unsafe['template'] ?? '') === 'page', 'unsafe template metadata should normalize to page');

    $theme = new Theme($paths, ['name' => 'Demo', 'theme' => 'demo']);
    $response = $theme->render($theme->pageLayout('shop'), ['page' => $saved, 'title' => 'Store']);
    assertTemplate(str_contains($response->content(), 'data-layout="shop"'), 'selected page template should render through the theme');

    // Exercise real encoded saves against an isolated custom theme, never site-owned templates.
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config', 'theme'=>'radpress/theme', 'data'=>'radpress/data', 'content'=>'radpress/content']);
    $config = \Batoi\Press\Core\Config::load($root);
    $csrf = new \Batoi\Press\Security\Csrf(new \Batoi\Press\Security\Session('press_custom_theme_test', $config->paths()->dataPath('sessions')));
    $controller = new \Batoi\Press\Admin\ThemeTemplateController($config, $files, $csrf, new \Batoi\Press\Core\AuditLog($config->paths(), $files), ['username'=>'owner', 'role'=>'owner']);
    $serverBefore = $_SERVER;
    try {
        $_SERVER['DOCUMENT_ROOT'] = $root;
        $_SERVER['SCRIPT_FILENAME'] = $root . '/testsite/public_html/index.php';
        foreach ([
            'header' => ['partials/header.php', '<?php echo "Header – café"; ?>'],
            'theme-css' => ['assets/css/theme.css', '.custom-header { color: #0e68b0; }'],
            'theme-js' => ['assets/js/theme.js', 'const preview = document.createElement("div"); preview.innerHTML = "<strong>café</strong>";'],
        ] as $key => [$relative, $source]) {
            $target = $root . '/radpress/theme/demo/' . $relative;
            $files->write($target, '/* original fixture */');
            $input = ['csrf_token'=>$csrf->token(), 'theme'=>'demo', 'template'=>$key, 'source_encoded'=>base64_encode($source)];
            $savedResponse = $controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, []));
            assertTemplate($savedResponse->status() === 302, 'custom theme encoded save should succeed: ' . $key);
            assertTemplate(($savedResponse->headers()['Location'] ?? '') === '/testsite/public_html/admin/theme-templates/edit/demo/' . $key, 'editor redirects should retain the installation subdirectory');
            assertTemplate(file_get_contents($target) === $source, 'encoded source should round-trip without UTF-8 loss');
            assertTemplate(count(glob($root . '/radpress/data/versions/theme/demo/' . $key . '/*') ?: []) === 1, 'saving should snapshot the original custom template');
            $input['source_encoded'] = 'invalid!base64';
            assertTemplate($controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, []))->status() === 400, 'invalid encoded source should be rejected');
            $input['csrf_token'] = 'invalid-token';
            $input['source_encoded'] = base64_encode('replacement');
            assertTemplate($controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, []))->status() === 400, 'invalid CSRF should be rejected');
            assertTemplate(file_get_contents($target) === $source, 'rejected saves must preserve the custom template');
        }
    } finally {
        $_SERVER = $serverBefore;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }

    echo "Theme page-template checks passed\n";
} finally {
    removeTemplateFixture($root);
}

function assertTemplate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTemplateFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
