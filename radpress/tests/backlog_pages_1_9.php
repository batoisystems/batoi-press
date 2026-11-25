<?php
declare(strict_types=1);

use Batoi\Press\Core\{App, AuditLog, Config, FileStore, HtmlContent, MaintenanceMode, PageBlockRenderer, Request};
use Batoi\Press\Content\{PageRepository, PostRepository, ProductRepository};
use Batoi\Press\Security\{Csrf, Session};
use Batoi\Press\Admin\{PageController, SettingsController};
require dirname(__DIR__) . '/autoload.php';
foreach (['url','esc','date'] as $helper) require_once dirname(__DIR__) . '/helpers/' . $helper . '.php';
function pages19check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function pages19reject(callable $action): void { try { $action(); } catch (RuntimeException) { return; } throw new RuntimeException('Expected rejection.'); }
$root = sys_get_temp_dir() . '/press-pages19-' . bin2hex(random_bytes(5));
$files = new FileStore();
$site = ['name'=>'Backlog fixture', 'base_url'=>'https://example.test', 'theme'=>'default', 'homepage'=>'home', 'appearance_mode'=>'system', 'archive_widgets'=>['news'=>'Recent posts']];
try {
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme','public_root'=>'public_html']);
    $files->writeJson($root . '/radpress/config/site.json', $site);
    $files->writeJson($root . '/radpress/config/users.json', ['users'=>[['username'=>'owner','role'=>'owner'], ['username'=>'writer','role'=>'author'], ['username'=>'disabled','role'=>'admin','status'=>'disabled']]]);
    $files->writeJson($root . '/radpress/config/security.json', ['session_name'=>'pages19test']);
    $source = dirname(__DIR__) . '/theme/default';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $files->write($root . '/radpress/theme/default/' . substr($file->getPathname(), strlen($source)+1), (string)file_get_contents($file->getPathname()));
    }
    $config = Config::load($root); $paths = $config->paths(); $html = new HtmlContent();
    $pages = new PageRepository($paths,$files,$html); $posts = new PostRepository($paths,$files,$html); $products = new ProductRepository($paths,$files,$html);
    $markup = '<div class="uif-carousel" data-uif="carousel" data-uif-autoplay="true" data-uif-interval="5000" data-uif-request="/danger" onclick="evil()"><div data-uif-role="slide"><p>Slide</p></div></div>';
    $clean = $html->sanitize($markup);
    pages19check(str_contains($clean,'data-uif-autoplay="true"') && str_contains($clean,'data-uif-interval="5000"'), 'Carousel timing must survive save.');
    pages19check(!str_contains($clean, 'onclick') && !str_contains($clean,'data-uif-request'), 'Active component attributes must remain forbidden.');
    pages19check(!str_contains($html->sanitize('<p data-uif-autoplay="true" data-uif-interval="5000">Text</p>'),'data-uif-'), 'Timing attributes only belong to carousel roots.');
    pages19check(!str_contains($html->sanitize(str_replace('5000','1',$markup)), 'data-uif-interval'), 'Unbounded timing rejected.');
    $posts->save(['title'=>'Blog item','slug'=>'blog-item','post_type'=>'blog','category'=>'Research','status'=>'published','body'=>'<p>Blog content</p>'], 'owner');
    $posts->save(['title'=>'News item','slug'=>'news-item','post_type'=>'news','category'=>'Events','status'=>'published','body'=>'<p>News content</p>'], 'owner');
    $products->save(['title'=>'Book','slug'=>'book','category'=>'Books','status'=>'published','body'=>'<p>Book</p>'], 'owner');
    $blocks = [['type'=>'grid','columns'=>3,'custom_class'=>'research-grid bad" onclick=x','cells'=>[
        ['type'=>'html','body'=>$markup],
        ['type'=>'posts','category'=>'Research','limit'=>1,'custom_class'=>'featured-posts','show_read_more'=>true],
        ['type'=>'products','category'=>'Books','limit'=>1,'custom_class'=>'featured-products'],
        ['type'=>'gallery','gallery_images'=>"/media/one.jpg | First picture\n/media/two.jpg | Second picture",'limit'=>1],
        ['type'=>'widget','widget'=>'Recent posts'],
    ]]];
    $pages->save(['title'=>'Home','slug'=>'home','status'=>'published','blocks'=>$blocks], 'owner');
    $home = $pages->findBySlug('home');
    pages19check(count($home['blocks'][0]['cells'])===5 && str_contains($home['body'],'Slide'), 'Grid cells and searchable body must survive reload.');
    $rendered = (new PageBlockRenderer($paths,$posts,$products,$site))->render($home['blocks']);
    foreach (['bp-block-grid-3','featured-posts','featured-products','Blog item','Book','First picture','data-uif="lightbox"'] as $needle) pages19check(str_contains($rendered,$needle), 'Missing rendered grid content: ' . $needle);
    pages19check(!str_contains($rendered,'Second picture') && !str_contains($rendered, 'onclick='), 'Gallery count and class safety must apply.');
    foreach ([[], array_fill(0,13,['type'=>'html']), [['type'=>'grid','cells'=>[['type'=>'html']]]], ['key'=>['type'=>'html']]] as $cells) pages19reject(fn()=> $pages->save(['title'=>'Bad','slug'=>'bad','blocks'=>[['type'=>'grid','cells'=>$cells]]], 'owner'));
    pages19reject(fn()=> $pages->save(['title'=>'Bad','slug'=>'bad','blocks'=>[['type'=>'gallery','gallery_images'=>"javascript:alert(1) | Bad"]]], 'owner'));
    $galleryBlocks = [['type'=>'gallery','body'=>'<p>Legacy gallery retained</p>', 'gallery_images'=>"/media/one.jpg | One\n/media/two.jpg | Two", 'limit'=>2]];
    $gallery = $pages->save(['title'=>'Gallery fixture','slug'=>'gallery-fixture','status'=>'published','blocks'=>$galleryBlocks], 'owner');
    $gallery = $pages->findBySlug('gallery-fixture');
    pages19check(str_contains($gallery['blocks'][0]['body'], 'Legacy gallery retained'), 'Media selection must retain legacy gallery HTML.');
    $galleryBlocks[0]['gallery_images'] = '';
    $pages->save(['title'=>'Gallery fixture','slug'=>'gallery-fixture','status'=>'published','blocks'=>$galleryBlocks], 'owner');
    pages19check(str_contains((new PageBlockRenderer($paths,$posts,$products,$site))->render($pages->findBySlug('gallery-fixture')['blocks']), 'Legacy gallery retained'), 'Clearing selection restores retained HTML.');
    if (class_exists(ZipArchive::class)) {
        $assetSource = dirname(__DIR__,2) . '/public_html/assets';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assetSource, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $files->write($root . '/public_html/assets/' . substr($file->getPathname(), strlen($assetSource)+1), (string)file_get_contents($file->getPathname()));
        }
        $export = (new \Batoi\Press\Core\StaticExporter($paths,$pages,$posts,$site))->export();
        pages19check(!empty($export['ok']), 'Static export must succeed with grid and archive widgets.');
        $zip = new ZipArchive(); $zip->open($export['path']);
        $homeExport = (string)$zip->getFromName('index.html'); $newsExport = (string)$zip->getFromName('news/index.html'); $zip->close();
        pages19check(str_contains($homeExport,'bp-block-grid-3') && str_contains($homeExport, 'data-bp-grid-layout') && str_contains($newsExport,'aria-label="Archive widget"'), 'Static export must include grid layout and archive widget.');
    }
    $session = new Session('pages19test', $paths->dataPath('sessions')); $csrf = new Csrf($session); $audit = new AuditLog($paths,$files);
    $ui = new PageController($config,$pages,$posts,$csrf,$audit,['username'=>'owner','role'=>'owner']);
    $_GET['editor']='html'; $editor = $ui->edit('home')->content(); $_GET=[];
    foreach (['data-bp-grid-source','data-bp-source-view="visual"','data-bp-source-view="preview"','block_custom_class[]','block_gallery_images[]','Choose from Media','>Research</option>','>Books</option>'] as $needle) pages19check(str_contains($editor,$needle), 'Missing editor control: ' . $needle);
    $settingsUi = new SettingsController($config,$files,$csrf,$audit,['username'=>'owner','role'=>'owner']);
    $settingsHtml = $settingsUi->edit()->content();
    foreach (['maintenance_enabled','maintenance_page','archive_widget_news'] as $needle) pages19check(str_contains($settingsHtml,'name="'.$needle.'"'), 'Missing setting: ' . $needle);
    $app = new App($root); $request = static fn(string $path, string $method='GET'): Request => new Request($method,$path,[],[],['HTTP_HOST'=>'example.test','REQUEST_URI'=>$path]);
    $archive = $app->handle($request('/news'));
    pages19check($archive->status()===200 && str_contains($archive->content(),'aria-label="Archive widget"') && str_contains($archive->content(), 'Blog item'), 'Configured archive widget must render.');
    $blog = $app->handle($request('/blog'));
    pages19check(!str_contains($blog->content(),'aria-label="Archive widget"'), 'Archive selections must be independent.');
    $pages->save(['title'=>'Maintenance notice','slug'=>'maintenance-notice','status'=>'draft','body'=>'<h1>Maintenance fixture notice</h1>'], 'owner');
    $site['maintenance_enabled']=true; $site['maintenance_page']='maintenance-notice'; $files->writeJson($paths->configPath('site.json'),$site);
    foreach (['/','/news','/sitemap.xml','/api/content'] as $path) {
        $response=$app->handle($request($path));
        pages19check($response->status()===503 && str_contains($response->content(),'Maintenance fixture notice'), 'Maintenance must replace every public route: ' . $path);
        pages19check(($response->headers()['X-Robots-Tag']??'')==='noindex, nofollow' && str_contains($response->headers()['Cache-Control']??'', 'no-store') && isset($response->headers()['Retry-After']), 'Maintenance discovery/cache guards missing.');
    }
    pages19check($app->handle($request('/theme-assets/default/css/theme.css'))->status()!==503, 'Maintenance assets must remain available.');
    pages19check($app->handle($request('/admin/login'))->status()!==503, 'Admin sign-in remains accessible.');
    $_COOKIE['pages19test']='present';
    foreach (['writer','disabled','missing'] as $username) { $session->set('auth_user',$username); pages19check($app->handle($request('/'))->status()===503,'Only authenticated active administrators bypass maintenance.'); }
    $session->set('auth_user','owner');
    $preview=$app->handle($request('/'));
    pages19check($preview->status()===200 && str_contains($preview->content(),'Blog item') && str_contains($preview->headers()['Cache-Control']??'','no-store'), 'Owner preview must show normal content privately.');
    $lock=new MaintenanceMode($paths); $lock->enable('Update fixture');
    pages19check($app->handle($request('/'))->status()===503, 'Updater lock must retain priority over admin preview.'); $lock->disable();
    $site['maintenance_enabled']=false; $files->writeJson($paths->configPath('site.json'),$site); unset($_COOKIE['pages19test']);
    pages19check($app->handle($request('/'))->status()===200,'Disabling maintenance must restore the site.');
    $session->destroy();
    echo "Backlog pages 1–9 checks passed\n";
} finally {
    $_GET=[]; unset($_COOKIE['pages19test']);
    if (is_dir($root)) { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); rmdir($root); }
}
