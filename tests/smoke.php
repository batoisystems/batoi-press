<?php
declare(strict_types=1);

require __DIR__ . '/../radpress/autoload.php';
require __DIR__ . '/../radpress/Helpers/esc.php';
require __DIR__ . '/../radpress/Helpers/url.php';
require __DIR__ . '/../radpress/Helpers/date.php';

use Batoi\Press\Core\App;
use Batoi\Press\Core\Request;

$root = dirname(__DIR__);
$paths = ['/', '/about', '/blog', '/blog/first-blog-post', '/sitemap.xml', '/feed.xml', '/admin', '/admin/updates'];

foreach ($paths as $path) {
    $response = (new App($root))->handle(new Request('GET', $path, [], []));
    ob_start();
    $response->send();
    $body = (string)ob_get_clean();
    if ($body === '') {
        fwrite(STDERR, "Empty response for {$path}\n");
        exit(1);
    }
}

echo "Smoke checks passed\n";

