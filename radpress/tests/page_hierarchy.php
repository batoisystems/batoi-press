<?php
declare(strict_types=1);

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\App;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Request;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/esc.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/date.php';

$root = sys_get_temp_dir() . '/batoi-press-page-hierarchy-' . bin2hex(random_bytes(4));

try {
    createHierarchyFixture($root);
    $paths = new Paths($root, [
        'public_root' => 'public_html',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ]);
    $files = new FileStore();
    $html = new HtmlContent();
    $pages = new PageRepository($paths, $files, $html);
    $posts = new PostRepository($paths, $files, $html);

    $parent = $pages->save([
        'title' => 'Company',
        'slug' => 'parent',
        'status' => 'published',
        'body' => '<h1>Company</h1>',
    ], 'owner');
    $child = $pages->save([
        'title' => 'Team',
        'slug' => '',
        'parent_slug' => 'parent',
        'status' => 'published',
        'body' => '<h1>Team page</h1>',
    ], 'owner');
    assertHierarchy(($child['slug'] ?? '') === 'team', 'blank page slugs should be generated from the title');
    assertHierarchy($pages->publicPath($child) === '/parent/team', 'child pages should use a nested public path');
    assertHierarchy(($pages->findByPath('/parent/team')['slug'] ?? '') === 'team', 'nested public paths should resolve the intended child page');

    $renamed = $pages->save([
        'title' => 'Company',
        'slug' => 'company',
        'original_slug' => (string)$parent['slug'],
        'status' => 'published',
        'body' => '<h1>Company</h1>',
    ], 'owner');
    $reloadedChild = $pages->findBySlug('team');
    assertHierarchy(($reloadedChild['parent_slug'] ?? '') === 'company', 'renaming a parent should update direct child references');
    assertHierarchy($pages->publicPath((array)$reloadedChild) === '/company/team', 'renamed parents should update child public paths');

    $cycleRejected = false;
    try {
        $pages->save([
            'title' => 'Company',
            'slug' => (string)$renamed['slug'],
            'original_slug' => (string)$renamed['slug'],
            'parent_slug' => 'team',
            'status' => 'published',
            'body' => '<h1>Company</h1>',
        ], 'owner');
    } catch (RuntimeException $exception) {
        $cycleRejected = str_contains($exception->getMessage(), 'cycle');
    }
    assertHierarchy($cycleRejected, 'page hierarchy cycles should be rejected');

    $pages->save([
        'title' => 'Draft Page',
        'slug' => 'draft-page',
        'status' => 'draft',
        'body' => '<h1>Private draft page</h1>',
    ], 'owner');
    $posts->save([
        'title' => 'Draft Post',
        'slug' => 'draft-post',
        'status' => 'draft',
        'body' => '<h1>Private draft post</h1>',
    ], 'owner');

    $publicChild = (new App($root))->handle(new Request('GET', '/company/team', [], [], []));
    assertHierarchy($publicChild->status() === 200 && str_contains($publicChild->content(), 'Team page'), 'published child pages should render at their nested route');
    $draftPage = (new App($root))->handle(new Request('GET', '/draft-page', [], [], []));
    assertHierarchy($draftPage->status() === 404 && !str_contains($draftPage->content(), 'Private draft page'), 'draft pages should not render publicly');
    $draftPost = (new App($root))->handle(new Request('GET', '/blog/draft-post', [], [], []));
    assertHierarchy($draftPost->status() === 404 && !str_contains($draftPost->content(), 'Private draft post'), 'draft posts should not render publicly');

    echo "Page hierarchy and draft visibility checks passed\n";
} finally {
    removeHierarchyFixture($root);
}

function createHierarchyFixture(string $root): void
{
    foreach ([
        'public_html',
        'radpress/config',
        'radpress/content/pages',
        'radpress/content/posts',
        'radpress/data',
        'radpress/theme/default/layouts',
    ] as $directory) {
        mkdir($root . '/' . $directory, 0775, true);
    }
    file_put_contents($root . '/radpress/config/paths.json', json_encode([
        'public_root' => 'public_html',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/config/site.json', json_encode([
        'name' => 'Hierarchy Test',
        'theme' => 'default',
        'homepage' => 'company',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/theme.json', json_encode([
        'schema' => 1,
        'slug' => 'default',
        'name' => 'Test Theme',
        'version' => '1.0.0',
        'author' => 'Batoi',
        'assets' => ['styles' => [], 'scripts' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/base.php', '<!doctype html><html><head></head><body><?php echo $content; ?></body></html>', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/page.php', '<main><?php echo $page["body"] ?? ""; ?></main>', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/post.php', '<main><?php echo $post["body"] ?? ""; ?></main>', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/blog.php', '<main>Blog</main>', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/archive.php', '<main>Archive</main>', LOCK_EX);
    file_put_contents($root . '/radpress/theme/default/layouts/404.php', '<main>Not found</main>', LOCK_EX);
}

function assertHierarchy(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeHierarchyFixture(string $path): void
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
