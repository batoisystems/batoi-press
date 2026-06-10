<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../helpers/esc.php';
require __DIR__ . '/../helpers/url.php';
require __DIR__ . '/../helpers/date.php';

use Batoi\Press\Core\App;
use Batoi\Press\Core\Request;

$root = dirname(__DIR__, 2);
$paths = ['/', '/about', '/blog', '/blog/first-blog-post', '/sitemap.xml', '/feed.xml', '/admin', '/admin/login', '/admin/pages', '/admin/posts', '/admin/media', '/admin/menus', '/admin/settings', '/admin/users', '/admin/cache', '/admin/export-static', '/admin/updates'];

foreach ($paths as $path) {
    $response = (new App($root))->handle(new Request('GET', $path, [], [], []));
    ob_start();
    $response->send();
    $body = (string)ob_get_clean();
    if ($body === '') {
        fwrite(STDERR, "Empty response for {$path}\n");
        exit(1);
    }
}

echo "Smoke checks passed\n";
