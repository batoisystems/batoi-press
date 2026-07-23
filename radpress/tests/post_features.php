<?php
declare(strict_types=1);

use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-post-features-' . bin2hex(random_bytes(4));
try {
    mkdir($root . '/radpress/content/posts', 0775, true);
    mkdir($root . '/radpress/data', 0775, true);
    $posts = new PostRepository(new Paths($root, ['content' => 'radpress/content', 'data' => 'radpress/data']), new FileStore(), new HtmlContent());
    $saved = $posts->save([
        'title' => 'Featured Post',
        'slug' => 'featured-post',
        'subtitle' => 'A concise article summary',
        'status' => 'published',
        'published_at' => '2026-07-01 10:00:00',
        'category' => 'Product News',
        'featured_image' => '/assets/images/featured.webp',
        'featured_image_alt' => 'Team presenting the new product',
        'layout' => 'sidebar-right',
        'body' => '<p>Body</p>',
    ], 'owner');
    assertPost(($saved['category'] ?? '') === 'Product News', 'new category names should persist with the post');
    assertPost(($saved['subtitle'] ?? '') === 'A concise article summary', 'post subtitles should persist');
    assertPost(($saved['featured_image'] ?? '') === '/assets/images/featured.webp', 'featured image URL should persist');
    assertPost(($saved['featured_image_alt'] ?? '') === 'Team presenting the new product', 'featured image alt text should persist');
    assertPost(($saved['layout'] ?? '') === 'sidebar-right', 'selected post layout should persist');
    $unsafe = $posts->save([
        'title' => 'Unsafe Image',
        'slug' => 'unsafe-image',
        'status' => 'draft',
        'featured_image' => 'javascript:alert(1)',
        'layout' => '../unsafe',
        'body' => '<p>Body</p>',
    ], 'owner');
    assertPost(($unsafe['featured_image'] ?? 'x') === '', 'unsafe featured image URLs should be removed');
    assertPost(($unsafe['layout'] ?? '') === 'full', 'unsafe post layouts should fall back to full width');
    $newer = $posts->save([
        'title' => 'Newer Article',
        'slug' => '',
        'status' => 'published',
        'published_at' => '2026-07-02 10:00:00',
        'body' => '<p>Body</p>',
    ], 'owner');
    assertPost(($newer['slug'] ?? '') === 'newer-article', 'blank slugs should be generated from the post title');
    $older = $posts->save([
        'title' => 'Older Article',
        'slug' => 'older-article',
        'status' => 'published',
        'published_at' => '2026-06-30 10:00:00',
        'body' => '<p>Body</p>',
    ], 'owner');
    $adjacent = $posts->adjacentPublished('featured-post');
    assertPost(($adjacent['previous']['slug'] ?? '') === ($older['slug'] ?? ''), 'previous article should resolve to the next older published post');
    assertPost(($adjacent['next']['slug'] ?? '') === ($newer['slug'] ?? ''), 'next article should resolve to the next newer published post');
    echo "Post feature checks passed\n";
} finally {
    removePostFixture($root);
}

function assertPost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removePostFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
