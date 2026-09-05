<?php
declare(strict_types=1);

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\IdempotencyStore;
use Batoi\Press\Application\SiteReadService;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-content-mutations-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/content/pages', 'radpress/content/posts', 'radpress/content/menus', 'radpress/data'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Mutation test', 'base_url' => 'https://press.example.test']);
    $config = Config::load($root);
    $pages = new PageRepository($config->paths(), $files, new HtmlContent());
    $posts = new PostRepository($config->paths(), $files, new HtmlContent());
    $audit = new AuditLog($config->paths(), $files);
    $mutations = new ContentMutationService($config, $pages, $posts, $audit, new IdempotencyStore($config->paths(), $files));
    $reads = SiteReadService::create($config, $pages, $posts);

    $created = $mutations->createDraft('page', [
        'title' => 'Automation Draft',
        'slug' => 'automation-draft',
        'body' => '<h1>Draft</h1><script>alert(1)</script>',
    ], 'token:test', 'create-page-0001', 'request-mutation-1');
    assertMutation(($created['resource']['status'] ?? '') === 'draft', 'create operation must force draft status');
    assertMutation(!str_contains((string)($pages->findBySlug('automation-draft')['body'] ?? ''), '<script'), 'repository sanitization must remain active for machine writes');

    $replay = $mutations->createDraft('page', [
        'title' => 'Automation Draft',
        'slug' => 'automation-draft',
        'body' => '<h1>Draft</h1><script>alert(1)</script>',
    ], 'token:test', 'create-page-0001', 'request-mutation-2');
    assertMutation(($replay['idempotent_replay'] ?? false) === true, 'matching idempotency retries should replay the original result');
    assertMutation(count($pages->all()) === 1, 'matching idempotency retries must not duplicate content');

    $idempotencyConflict = false;
    try {
        $mutations->createDraft('page', ['title' => 'Different', 'slug' => 'different', 'body' => 'Different'], 'token:test', 'create-page-0001');
    } catch (ContentMutationException $exception) {
        $idempotencyConflict = $exception->errorCode() === 'idempotency_conflict';
    }
    assertMutation($idempotencyConflict, 'reusing an idempotency key with different input should conflict');

    $page = $reads->page('automation-draft');
    $updated = $mutations->updateDraft('page', 'automation-draft', ['title' => 'Reviewed Draft', 'body' => '<p>Reviewed</p>'], (string)($page['revision'] ?? ''), 'token:test', 'request-mutation-3');
    assertMutation(($updated['resource']['revision'] ?? '') !== ($page['revision'] ?? ''), 'successful updates should return a new revision');
    assertMutation(($pages->findBySlug('automation-draft')['blocks'][0]['body'] ?? '') === '<p>Reviewed</p>', 'body-only API updates must update the block used for public rendering');
    assertMutation(($updated['change_summary']['fields'] ?? []) === ['title', 'body'], 'change summary should contain field names without content values');

    $staleRejected = false;
    try {
        $mutations->updateDraft('page', 'automation-draft', ['title' => 'Stale'], (string)($page['revision'] ?? ''), 'token:test');
    } catch (ContentMutationException $exception) {
        $staleRejected = $exception->errorCode() === 'revision_conflict' && isset($exception->details()['current_revision']);
    }
    assertMutation($staleRejected, 'stale expected revisions should be rejected with the current revision');

    $latest = $reads->page('automation-draft');
    $published = $mutations->publish('page', 'automation-draft', (string)($latest['revision'] ?? ''), 'token:test', 'publish-page-0001', 'request-mutation-4');
    assertMutation(($published['resource']['status'] ?? '') === 'published', 'publish should be a distinct state-changing operation');
    $publishReplay = $mutations->publish('page', 'automation-draft', (string)($latest['revision'] ?? ''), 'token:test', 'publish-page-0001');
    assertMutation(($publishReplay['idempotent_replay'] ?? false) === true, 'publish retries should be idempotent');

    $post = $mutations->createDraft('post', [
        'title' => 'A Draft Post', 'body' => '<p>Post</p>', 'category' => 'News', 'tags' => ['one', 'two'],
    ], 'token:test', 'create-post-0001');
    assertMutation(($post['resource']['type'] ?? '') === 'post' && ($post['resource']['status'] ?? '') === 'draft', 'post drafts should use the same governed mutation service');

    $auditBody = $files->read($root . '/radpress/data/log/audit.jsonl');
    assertMutation(str_contains($auditBody, 'content.page.draft_created') && str_contains($auditBody, 'request-mutation-4'), 'mutations should produce correlated audit events');
    assertMutation(!str_contains($auditBody, 'Reviewed</p>'), 'audit entries should not contain full content values');

    echo "Content mutation checks passed\n";
} finally {
    removeMutationFixture($root);
}

function assertMutation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeMutationFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
