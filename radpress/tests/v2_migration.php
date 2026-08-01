<?php
declare(strict_types=1);

use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\ThemeManager;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-v2-migration-' . bin2hex(random_bytes(5));
foreach (['radpress/content/pages/legacy-page', 'radpress/content/posts/legacy-post', 'radpress/content/menus', 'radpress/data', 'radpress/theme/legacy'] as $directory) mkdir($root . '/' . $directory, 0775, true);
try {
    $files = new FileStore();
    $paths = new Paths($root, ['content' => 'radpress/content', 'data' => 'radpress/data', 'theme' => 'radpress/theme']);
    $files->writeJson($root . '/radpress/content/pages/legacy-page/meta.json', ['title' => 'Legacy Page', 'slug' => 'legacy-page', 'status' => 'published']);
    $files->write($root . '/radpress/content/pages/legacy-page/body.html', '<h1>Legacy page</h1>');
    $files->writeJson($root . '/radpress/content/posts/legacy-post/meta.json', ['title' => 'Legacy Post', 'slug' => 'legacy-post', 'status' => 'published', 'published_at' => '2025-01-01T00:00:00+00:00']);
    $files->write($root . '/radpress/content/posts/legacy-post/body.html', '<h1>Legacy post</h1>');
    $files->writeJson($root . '/radpress/content/menus/main.json', ['items' => [
        ['label' => 'Company', 'url' => '/company'], ['label' => 'Team', 'url' => '/team', 'parent' => '/company'],
    ]]);
    $files->writeJson($root . '/radpress/theme/legacy/theme.json', ['schema' => 1, 'slug' => 'legacy', 'name' => 'Legacy', 'version' => '1.8.0', 'author' => 'Batoi']);

    $pages = new PageRepository($paths, $files, new HtmlContent());
    $posts = new PostRepository($paths, $files, new HtmlContent());
    assertV2Migration(PublicationState::isPublic((array)$pages->findBySlug('legacy-page')), 'legacy published pages should remain public without new workflow fields');
    assertV2Migration(PublicationState::isPublic((array)$posts->findBySlug('legacy-post')), 'legacy published posts should retain published_at compatibility');
    $menu = (new MenuRepository($paths, $files))->load('main');
    assertV2Migration(($menu['schema_version'] ?? 0) === 2 && ($menu['items'][1]['parent_id'] ?? '') === ($menu['items'][0]['id'] ?? null), 'legacy URL-parent menus should migrate in memory to stable IDs');
    assertV2Migration((new ThemeManager($paths, $files))->menuLocations('legacy') === ['primary' => 'Primary navigation'], 'legacy themes should receive the compatible primary menu location');
    assertV2Migration(!isset($menu['items'][0]['script']) && ($menu['revision'] ?? -1) === 0, 'migration should not invent executable fields or revision history');
    echo "Version 2 migration checks passed\n";
} finally {
    removeV2MigrationFixture($root);
}

function assertV2Migration(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeV2MigrationFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($path);
}
