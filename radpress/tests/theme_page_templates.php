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
    $manifest=(new FileStore())->readJson($paths->themePath('demo/theme.json'));
    assertTemplate($manager->normalizeManifest('demo',$manifest)['contract']==='1.0.0','Legacy manifests must inherit the stable contract.');
    $contract=$manifest+['contract'=>'1.0.0','compatibility'=>['php'=>['min'=>'8.3.0','max_exclusive'=>'9.0.0'],'press'=>['min'=>'3.0.0','max_exclusive'=>'5.0.0']], 'tokens'=>['light'=>['primary_button'=>'#0E68B0']], 'partials'=>['email']];
    (new FileStore())->writeJson($paths->themePath('demo/theme.json'),$contract);
    assertTemplate(!$manager->validate('demo')['ok'],'Missing declared partial must fail compatibility.');
    (new FileStore())->write($paths->themePath('demo/partials/email.php'),'<?php echo "Email";');
    assertTemplate($manager->validate('demo')['ok'],'Supported contract/tokens/partial must validate.');
    $future=$contract;$future['compatibility']['press']['min']='99.0.0';$future['compatibility']['press']['max_exclusive']='100.0.0';
    (new FileStore())->writeJson($paths->themePath('demo/theme.json'),$future);
    assertTemplate(!$manager->validate('demo')['ok'] && (new Theme($paths,['theme'=>'demo']))->render('page')->status()===503,'Incompatible runtime theme must not execute its templates.');
    foreach([['contract'=>'2.0.0'],['tokens'=>['light'=>['primary_button'=>'red;url(evil)']]],['partials'=>['../unsafe']]] as $bad) {
        $rejected=false;try{$manager->normalizeManifest('demo',array_replace($contract,$bad));}catch(RuntimeException){$rejected=true;}
        assertTemplate($rejected,'Invalid theme contract, token or partial was accepted.');
    }
    (new FileStore())->writeJson($paths->themePath('demo/theme.json'),$manifest);
    (new FileStore())->write($root . '/radpress/theme/demo/assets/css/theme.css', 'body { color: #111; }');
    $assetUrl = $manager->assetUrl('demo', 'css/theme.css', false);
    assertTemplate(str_contains($assetUrl, '?v=1.0.0&h='), 'theme asset URLs should include version and content fingerprint');
    $assetFile = $manager->resolveAsset('demo', 'css/theme.css');
    $originalAsset = file_get_contents($assetFile);
    file_put_contents($assetFile, $originalAsset . '\n/* cache invalidation regression */');
    assertTemplate($manager->assetUrl('demo', 'css/theme.css', false) !== $assetUrl, 'editing an asset without changing theme version must invalidate its URL');
    file_put_contents($assetFile, $originalAsset);

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
    foreach (\Batoi\Press\Core\ThemePartial::FILES as $key => $relative) {
        $edit = $controller->edit('demo/' . $key);
        assertTemplate($edit->status() === 200 && str_contains($edit->content(), '$escape'), 'Missing optional partial must open with documented starter source: ' . $key);
    }
    $viewer = new \Batoi\Press\Admin\ThemeTemplateController($config, $files, $csrf, new \Batoi\Press\Core\AuditLog($config->paths(), $files), ['username'=>'viewer', 'role'=>'viewer']);
    assertTemplate($viewer->edit('demo/email')->status() === 403 && $viewer->edit('demo/block-posts')->status() === 403, 'Optional templates must retain source-editor permissions');
    $serverBefore = $_SERVER;
    try {
        $_SERVER['DOCUMENT_ROOT'] = $root;
        $_SERVER['SCRIPT_FILENAME'] = $root . '/testsite/public_html/index.php';
        foreach ([
            'email' => ['partials/email.php', '<?php echo $escape($message); ?>'],
            'block-posts' => ['partials/blocks/posts.php', '<?php foreach ($items as $item) echo $escape($item["title"]); ?>'],
            'block-gallery' => ['partials/blocks/gallery.php', '<?php echo $body; ?>'],
            'block-products' => ['partials/blocks/products.php', '<?php echo count($items); ?>'],
            'block-widget' => ['partials/blocks/widget.php', '<?php echo $escape($title); ?>'],
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
            if ($key === 'header') {
                $input['csrf_token'] = $csrf->token();
                $input['source_encoded'] = base64_encode('<?php echo ; // recover-validation');
                $invalid = $controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, []));
                assertTemplate($invalid->status() === 400 && str_contains($invalid->content(), 'recover-validation') && file_get_contents($target) === $source, 'syntax failure preserves editor content and stored source');
            }
            $input['csrf_token'] = $csrf->token();
            unset($input['source_encoded']);
            $input['source'] = "  " . $source . "\n\n";
            $normal = $controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, []));
            assertTemplate($normal->status() === 302 && file_get_contents($target) === $input['source'], 'native form without JavaScript preserves exact source');
            $input['csrf_token'] = 'expired';
            $input['source'] = 'RECOVERABLE </textarea><script>privateTemplate()</script>';
            $failed = $controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, []));
            assertTemplate($failed->status() === 400 && str_contains($failed->content(), 'RECOVERABLE &lt;/textarea&gt;') && str_contains($failed->content(), 'data-bp-source-download'), 'failure returns escaped recoverable editor');
            assertTemplate(str_contains($failed->content(), $failed->headers()['X-Request-ID']), 'visible failure correlation ID');
            $input['csrf_token'] = $csrf->token();
            $input['source'] = $source;
            $retry = $controller->save(new \Batoi\Press\Core\Request('POST', '/admin/theme-templates/save', [], $input, ['HTTP_X_PRESS_EDITOR'=>'1']));
            assertTemplate($retry->status() === 200 && json_decode($retry->content(), true)['ok'] === true, 'AJAX recovery retry succeeds');
            $auditText = file_get_contents($root . '/radpress/data/log/audit.jsonl');
            assertTemplate(!str_contains($auditText, 'privateTemplate') && !str_contains($auditText, $csrf->token()), 'audit excludes source and tokens');

        }
    } finally {
        $_SERVER = $serverBefore;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }

    $paths = $config->paths();
    $posts = new \Batoi\Press\Content\PostRepository($paths, $files, new HtmlContent());
    $products = new \Batoi\Press\Content\ProductRepository($paths, $files, new HtmlContent());
    $posts->save(['title'=>'Public <post>', 'slug'=>'public-post', 'body'=>'Public', 'status'=>'published', 'category'=>'news'], 'owner');
    $posts->save(['title'=>'Private post', 'slug'=>'private-post', 'body'=>'Private', 'status'=>'draft', 'category'=>'news'], 'owner');
    $posts->save(['title'=>'Other category', 'slug'=>'other-post', 'body'=>'Other', 'status'=>'published', 'category'=>'other'], 'owner');
    $products->save(['title'=>'Public product', 'slug'=>'public-product', 'status'=>'published', 'category'=>'shop', 'price'=>'12.50'], 'owner');
    $products->save(['title'=>'Private product', 'slug'=>'private-product', 'status'=>'draft', 'category'=>'shop'], 'owner');
    $files->writeJson($paths->contentPath('widgets/sidebar.json'), ['widgets'=>[['title'=>'Test widget', 'type'=>'html', 'body'=>'<p>Widget body</p>']]]);
    $blocks = [
        ['type'=>'posts', 'title'=>'Posts', 'category'=>'news', 'limit'=>1],
        ['type'=>'gallery', 'title'=>'Gallery', 'body'=>'<p>Gallery body</p>'],
        ['type'=>'products', 'title'=>'Products', 'category'=>'shop', 'limit'=>1],
        ['type'=>'widget', 'widget'=>'Test widget'],
    ];
    foreach (['posts','gallery','products','widget'] as $type) {
        $source = '<?php echo "<section data-custom-block=\"' . $type . '\">" . $escape($title); '
            . (in_array($type, ['posts','products'], true) ? 'foreach ($items as $item) echo "<a href=\"" . $escape($item["url"]) . "\">" . $escape($item["title"]) . "</a>";' : 'echo $body;')
            . ' echo "</section>";';
        $files->write($paths->themePath('demo/partials/blocks/' . $type . '.php'), $source);
    }
    $site = ['name'=>'Demo', 'theme'=>'demo', 'homepage'=>'blocks', 'base_url'=>'https://example.test/sub'];
    $files->writeJson($paths->configPath('site.json'), $site);
    $renderer = new \Batoi\Press\Core\PageBlockRenderer($paths, $posts, $products, $site);
    $rendered = $renderer->render($blocks);
    foreach (['posts','gallery','products','widget'] as $type) assertTemplate(str_contains($rendered, 'data-custom-block="' . $type . '"'), 'Each block must select the active-theme partial');
    assertTemplate(str_contains($rendered, 'Public &lt;post&gt;') && !str_contains($rendered, 'Private') && !str_contains($rendered, 'Other category'), 'Template data must retain publication/category/limit checks and escaping');
    assertTemplate(str_contains($rendered, 'Gallery body') && str_contains($rendered, 'Widget body'), 'Gallery and widget bodies must reach their presentation templates');
    $fallback = (new \Batoi\Press\Core\PageBlockRenderer($paths, $posts, $products, ['theme'=>'default']))->render($blocks);
    assertTemplate(str_contains($fallback, 'bp-post-grid') && str_contains($fallback, 'bp-content-gallery') && str_contains($fallback, 'bp-product-grid') && str_contains($fallback, 'bp-sidebar-widget'), 'Missing custom partials must preserve default block markup');
    $files->write($paths->themePath('demo/layouts/page.php'), '<?php echo $page["body"];');
    $pages = new PageRepository($paths, $files, new HtmlContent());
    $pages->save(['title'=>'Blocks', 'slug'=>'blocks', 'status'=>'published', 'blocks'=>$blocks], 'owner');
    $public = (new \Batoi\Press\Core\App($root))->handle(new \Batoi\Press\Core\Request('GET', '/blocks', [], [], []));
    assertTemplate($public->status() === 200 && str_contains($public->content(), 'data-custom-block="posts"'), 'Public route must use the active-theme block partial');
    $export = (new \Batoi\Press\Core\StaticExporter($paths, $pages, $posts, $site, $products))->export();
    assertTemplate($export['ok'] ?? false, 'Custom block export must succeed');
    $zip = new ZipArchive();
    $zip->open($export['path']);
    assertTemplate(str_contains((string)$zip->getFromName('index.html'), 'data-custom-block="products"'), 'Static export must use the same theme presentation');
    $zip->close();
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
