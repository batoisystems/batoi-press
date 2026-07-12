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

    $pages = new PageRepository($paths, new FileStore(), new HtmlContent());
    $saved = $pages->save(['title' => 'Store', 'slug' => 'store', 'status' => 'published', 'template' => 'shop', 'body' => '<h1>Store</h1>'], 'owner');
    assertTemplate(($saved['template'] ?? '') === 'shop', 'selected template should persist in page metadata');
    $unsafe = $pages->save(['title' => 'Unsafe', 'slug' => 'unsafe', 'status' => 'draft', 'template' => '../shop', 'body' => '<p>Unsafe</p>'], 'owner');
    assertTemplate(($unsafe['template'] ?? '') === 'page', 'unsafe template metadata should normalize to page');

    $theme = new Theme($paths, ['name' => 'Demo', 'theme' => 'demo']);
    $response = $theme->render($theme->pageLayout('shop'), ['page' => $saved, 'title' => 'Store']);
    assertTemplate(str_contains($response->content(), 'data-layout="shop"'), 'selected page template should render through the theme');

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
