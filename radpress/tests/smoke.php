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
$mediaFile = $root . '/radpress/content/media/smoke-test.txt';
file_put_contents($mediaFile, 'media ok', LOCK_EX);
$paths[] = '/media/smoke-test.txt';

try {
    foreach ($paths as $path) {
        $response = (new App($root))->handle(new Request('GET', $path, [], [], []));
        ob_start();
        $response->send();
        $body = (string)ob_get_clean();
        if ($body === '') {
            throw new RuntimeException("Empty response for {$path}");
        }

        if ($path === '/about' && !str_contains($body, '<title>About Batoi Press</title>')) {
            throw new RuntimeException("SEO title did not render for {$path}");
        }

        if ($path === '/media/smoke-test.txt' && $body !== 'media ok') {
            throw new RuntimeException("Media response mismatch for {$path}");
        }
    }
} finally {
    if (is_file($mediaFile)) {
        unlink($mediaFile);
    }
}

echo "Smoke checks passed\n";
