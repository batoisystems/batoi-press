<?php
declare(strict_types=1);

use Batoi\Press\Application\SiteReadService;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-editorial-workflow-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/content/pages', 'radpress/content/posts', 'radpress/content/menus', 'radpress/data'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Editorial test', 'base_url' => 'https://press.example.test']);
    $config = Config::load($root);
    $pages = new PageRepository($config->paths(), $files, new HtmlContent());
    $posts = new PostRepository($config->paths(), $files, new HtmlContent());

    $review = $pages->save([
        'title' => 'Reviewed Page', 'slug' => 'reviewed', 'status' => 'in_review', 'reviewer' => 'editor',
        'workflow_note' => 'Ready for legal review.', 'body' => '<p>Reviewed</p>',
    ], 'author');
    assertEditorial(($review['status'] ?? '') === 'in_review' && ($review['reviewer'] ?? '') === 'editor', 'review state and reviewer should persist');
    assertEditorial(($review['workflow_history'][0]['note'] ?? '') === 'Ready for legal review.', 'workflow notes should be retained in bounded history');
    assertEditorial(!PublicationState::isPublic($review), 'review content must remain private');

    $future = date(DATE_ATOM, time() + 3600);
    $scheduled = $posts->save([
        'title' => 'Future Post', 'slug' => 'future-post', 'status' => 'scheduled', 'publish_at' => $future,
        'category' => 'News', 'tags' => 'Launch, Product', 'body' => '<p>Future</p>',
    ], 'editor');
    assertEditorial(!PublicationState::isPublic($scheduled), 'future scheduled content must remain private');

    $due = $posts->save([
        'title' => 'Due Post', 'slug' => 'due-post', 'status' => 'scheduled', 'publish_at' => date(DATE_ATOM, time() - 60),
        'unpublish_at' => date(DATE_ATOM, time() + 3600), 'category' => 'News', 'tags' => 'Launch, Update', 'body' => '<p>Due</p>',
    ], 'editor');
    assertEditorial(PublicationState::isPublic($due), 'due scheduled content should become public without a cron transition');
    assertEditorial(count($posts->allPublished()) === 1, 'published queries should include only currently visible content');

    $expired = $pages->save([
        'title' => 'Expired Page', 'slug' => 'expired', 'status' => 'published',
        'unpublish_at' => date(DATE_ATOM, time() - 60), 'body' => '<p>Expired</p>',
    ], 'editor');
    assertEditorial(!PublicationState::isPublic($expired), 'past unpublish dates should remove public visibility');

    $taxonomies = SiteReadService::create($config, $pages, $posts)->taxonomies();
    assertEditorial(($taxonomies['categories'][0]['count'] ?? 0) === 2 && ($taxonomies['categories'][0]['public_count'] ?? 0) === 1, 'category counts should distinguish total and public usage');
    $tagCounts = array_column((array)$taxonomies['tags'], 'count', 'slug');
    assertEditorial(($tagCounts['launch'] ?? 0) === 2 && ($tagCounts['product'] ?? 0) === 1, 'shared tags should aggregate consistently');

    echo "Editorial workflow checks passed\n";
} finally {
    removeEditorialFixture($root);
}

function assertEditorial(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeEditorialFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
