<?php
declare(strict_types=1);

use Batoi\Press\Core\{App, FileStore, PublicDelivery, Request};

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/esc.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/date.php';

function deliveryCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/press-public-delivery-' . bin2hex(random_bytes(5));
$files = new FileStore();
$base = 'https://www.chamber.example/press';
$request = static function (string $path, array $server = [], array $query = [], string $method = 'GET'): Request {
    return new Request($method, $path, $query, [], $server + [
        'HTTP_HOST' => 'www.chamber.example',
        'SCRIPT_NAME' => '/press/index.php',
        'REQUEST_URI' => '/press' . $path,
    ]);
};

try {
    $config = $root . '/radpress/config';
    $theme = $root . '/radpress/theme/custom';
    $content = $root . '/radpress/content/pages';
    $files->writeJson($config . '/paths.json', [
        'config' => 'radpress/config', 'data' => 'radpress/data', 'content' => 'radpress/content',
        'theme' => 'radpress/theme', 'public_root' => 'public_html',
    ]);
    $files->writeJson($config . '/site.json', ['name' => 'Chamber & Industry', 'base_url' => $base, 'homepage' => 'welcome', 'theme' => 'custom']);
    $files->writeJson($theme . '/theme.json', ['name' => 'Custom', 'author' => 'Fixture', 'version' => '1.0.0']);
    $files->write($theme . '/layouts/base.php', '<html><head></head><body><main id="custom"><?php echo $content; ?></main></body></html>');
    $files->write($theme . '/layouts/page.php', '<?php echo $page["body"];');
    $files->writeJson($content . '/welcome/meta.json', ['title' => 'Home', 'slug' => 'welcome', 'status' => 'published', 'seo_title' => 'Home', 'seo_description' => 'Home']);
    $files->write($content . '/welcome/body.html', '<h1>Chamber</h1><p>Meet the local businesses and discover upcoming events.</p>' . str_repeat('<p>Membership information.</p>', 80));
    $files->writeJson($content . '/news/meta.json', ['title' => 'News', 'slug' => 'news', 'status' => 'published', 'seo_title' => 'Our news', 'seo_description' => 'An authored summary.']);
    $files->write($content . '/news/body.html', '<h1>News</h1><p>Announcements.</p>');
    $posts = $root . '/radpress/content/posts';
    $files->writeJson($posts . '/talk/meta.json', ['title' => 'Talk', 'slug' => 'talk', 'status' => 'published', 'post_type' => 'events']);
    $files->write($posts . '/talk/body.html', '<p>Talk.</p>');
    $files->writeJson($posts . '/speaker/meta.json', ['title' => 'Speaker', 'slug' => 'speaker', 'status' => 'published', 'post_type' => 'events', 'parent_slug' => 'talk']);
    $files->write($posts . '/speaker/body.html', '<p>Speaker.</p>');
    $protected = [$config . '/site.json', $theme . '/layouts/base.php', $content . '/welcome/meta.json', $content . '/welcome/body.html'];
    $hashes = array_map(static fn(string $path): string => hash_file('sha256', $path), $protected);

    $app = new App($root);
    $robots = $app->handle($request('/robots.txt'));
    deliveryCheck($robots->status() === 200 && str_contains($robots->content(), "Sitemap: $base/sitemap.xml")
        && str_contains($robots->content(), 'Disallow: /press/admin'), 'robots uses the configured mount and domain');
    $sitemap = $app->handle($request('/sitemap.xml'))->content();
    deliveryCheck(substr_count($sitemap, "<loc>$base/</loc>") === 1 && !str_contains($sitemap, '/welcome</loc>'), 'sitemap uses the configured homepage');
    deliveryCheck(substr_count($sitemap, "<loc>$base/events</loc>") === 1
        && str_contains($sitemap, "<loc>$base/events/talk/speaker</loc>"), 'sitemap includes published archives and nested paths once');
    $alias = $app->handle($request('/news', ['HTTP_HOST' => 'chamber.example', 'REQUEST_URI' => '/press/news?ref=mail'], ['ref' => 'mail']));
    deliveryCheck($alias->status() === 301 && $alias->headers()['Location'] === "$base/news?ref=mail", 'public host redirect retains path and query');
    deliveryCheck(PublicDelivery::canonicalHost($request('/admin/login', ['HTTP_HOST' => 'chamber.example']), $base) === null
        && PublicDelivery::canonicalHost($request('/news', ['HTTP_HOST' => 'preview.example']), $base) === null
        && PublicDelivery::canonicalHost($request('/news', ['HTTP_HOST' => 'chamber.example'], [], 'POST'), $base) === null, 'admin, preview host and write requests keep their URL');
    $homepageAlias = $app->handle($request('/welcome', [], ['ref' => 'mail']));
    deliveryCheck($homepageAlias->status() === 301 && $homepageAlias->headers()['Location'] === '/press/?ref=mail', 'homepage slug redirects within the installation');

    $home = $app->handle($request('/'));
    deliveryCheck($home->status() === 200 && str_contains($home->content(), '<title>Chamber &amp; Industry</title>')
        && str_contains($home->content(), "href=\"$base/\"") && str_contains($home->content(), 'Meet the local businesses')
        && str_contains($home->content(), 'id="custom"'), 'missing metadata is supplied to the custom theme');
    $news = $app->handle($request('/news'))->content();
    deliveryCheck(str_contains($news, '<title>Our news</title>') && str_contains($news, 'content="An authored summary."'), 'authored metadata remains authoritative');
    deliveryCheck($hashes === array_map(static fn(string $path): string => hash_file('sha256', $path), $protected), 'rendering leaves site, content and theme files intact');

    if (function_exists('gzencode') && !(bool)ini_get('zlib.output_compression')) {
        $gzip = $app->handle($request('/', ['HTTP_ACCEPT_ENCODING' => 'br, gzip;q=0.8']));
        deliveryCheck(($gzip->headers()['Content-Encoding'] ?? '') === 'gzip' && gzdecode($gzip->content()) === $home->content(), 'anonymous HTML uses negotiated gzip');
        foreach ([
            $request('/', ['HTTP_ACCEPT_ENCODING' => '*;q=1, gzip;q=0']),
            $request('/', ['HTTP_ACCEPT_ENCODING' => 'gzip', 'HTTP_COOKIE' => 'fixture=1']),
            $request('/', ['HTTP_ACCEPT_ENCODING' => 'gzip'], ['q' => 'fixture']),
            $request('/contact', ['HTTP_ACCEPT_ENCODING' => 'gzip']),
        ] as $uncompressed) {
            $response = PublicDelivery::compress($uncompressed, $home, []);
            deliveryCheck(!isset($response->headers()['Content-Encoding']), 'excluded representations retain identity bytes');
        }
        $private = PublicDelivery::compress($request('/', ['HTTP_ACCEPT_ENCODING' => 'gzip']), $home->withHeader('Cache-Control', 'private, no-store'), []);
        deliveryCheck(!isset($private->headers()['Content-Encoding']), 'private response is never compressed');
    }
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
echo "Public delivery checks passed\n";
