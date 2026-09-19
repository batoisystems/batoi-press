<?php
declare(strict_types=1);

use Batoi\Press\Content\ContentTransaction;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;

require dirname(__DIR__) . '/autoload.php';

if (($argv[1] ?? '') === '--crash') {
    $config = Config::load($argv[2]);
    $repo = new PageRepository($config->paths(), new FileStore(), new HtmlContent());
    $prepared = $repo->prepareSave(['original_slug' => 'parent', 'slug' => 'renamed', 'title' => 'Changed', 'body' => '<p>Changed</p>', 'status' => 'published'], 'owner');
    (new ContentTransaction($config->paths(), new FileStore(), static function (string $stage): void { if ($stage === 'file:1') exit(37); }))->commit('page', $prepared);
    exit(1);
}

$root = sys_get_temp_dir() . '/batoi-content-transaction-' . bin2hex(random_bytes(6));
mkdir($root . '/radpress/config', 0700, true);
try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $config = Config::load($root);
    $repo = new PageRepository($config->paths(), $files, new HtmlContent());
    $repo->save(['title' => 'Parent', 'slug' => 'parent', 'body' => '<p>Original</p>', 'status' => 'published'], 'owner');
    $repo->save(['title' => 'Child', 'slug' => 'child', 'parent_slug' => 'parent', 'body' => '<p>Child</p>', 'status' => 'published'], 'owner');
    $original = $repo->all();
    foreach (['journal', 'rename', 'file:0', 'file:1', 'file:2'] as $failure) {
        $prepared = $repo->prepareSave(['original_slug' => 'parent', 'slug' => 'renamed', 'title' => 'Changed', 'body' => '<p>Changed</p>', 'status' => 'published'], 'owner');
        try {
            (new ContentTransaction($config->paths(), $files, static function (string $stage) use ($failure): void {
                if ($stage === $failure) throw new RuntimeException('Injected failure');
            }))->commit('page', $prepared);
            throw new LogicException('Expected injected failure');
        } catch (RuntimeException $error) {
            checkTransaction($error->getMessage() === 'Injected failure', 'unexpected recovery error: ' . $error->getMessage());
        }
        checkTransaction($repo->all() === $original, 'failure at ' . $failure . ' must restore body, metadata and child reference');
        checkTransaction(!is_dir($config->paths()->contentPath('pages/renamed')), 'rollback restores original directory name');
    }
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --crash ' . escapeshellarg($root), $output, $status);
    checkTransaction($status === 37, 'crash fixture must terminate during a real write');
    checkTransaction($repo->all() === $original, 'next repository read recovers an interrupted process before serving content');

    $prepared = $repo->prepareSave(['title' => 'New', 'slug' => 'new', 'body' => 'New'], 'owner');
    try {
        (new ContentTransaction($config->paths(), $files, static function (string $stage): void { if ($stage === 'file:0') throw new RuntimeException('New failure'); }))->commit('page', $prepared);
    } catch (RuntimeException $error) {
        checkTransaction($error->getMessage() === 'New failure', 'new-content rollback should succeed');
    }
    checkTransaction(!is_dir($config->paths()->contentPath('pages/new')), 'rollback removes only the incomplete new record');

    $prepared = $repo->prepareSave(['original_slug' => 'parent', 'slug' => 'parent', 'title' => 'Stale', 'body' => 'Stale'], 'owner');
    $repo->save(['original_slug' => 'parent', 'slug' => 'parent', 'title' => 'Concurrent', 'body' => 'Concurrent'], 'owner');
    $blocked = false;
    try { (new ContentTransaction($config->paths()))->commit('page', $prepared); } catch (RuntimeException $error) { $blocked = str_contains($error->getMessage(), 'between preparation'); }
    checkTransaction($blocked && $repo->findBySlug('parent')['title'] === 'Concurrent', 'commit rechecks its base revision under the storage lock');

    $prepared = $repo->prepareSave(['original_slug' => 'parent', 'slug' => 'renamed', 'title' => 'Concurrent', 'body' => 'Concurrent'], 'owner');
    $prepared['hierarchy_revision'] = (new ContentTransaction($config->paths()))->hierarchyRevision('page');
    $repo->save(['title' => 'Later child', 'slug' => 'later-child', 'parent_slug' => 'child', 'body' => 'Later child'], 'owner');
    $beforeHierarchyConflict = $repo->all();
    $blocked = false;
    try { (new ContentTransaction($config->paths()))->commit('page', $prepared); }
    catch (RuntimeException $error) { $blocked = str_contains($error->getMessage(), 'hierarchy changed between preparation'); }
    checkTransaction($blocked && $repo->all() === $beforeHierarchyConflict, 'storage lock rejects an unreviewed descendant added after preparation without moving or changing content');

    $operation = 'proposal_' . str_repeat('a', 32);
    $prepared = $repo->prepareSave(['original_slug' => 'parent', 'slug' => 'parent', 'title' => 'Committed', 'body' => 'Committed'], 'owner');
    try {
        (new ContentTransaction($config->paths(), $files, static function (string $stage): void { if ($stage === 'committed') throw new RuntimeException('Receipt interruption'); }))->commit('page', $prepared + ['operation_id' => $operation]);
    } catch (RuntimeException $error) { checkTransaction($error->getMessage() === 'Receipt interruption', 'commit receipt failure fixture'); }
    $receipt = (new ContentTransaction($config->paths()))->receipt('page', $operation);
    checkTransaction(($receipt['state'] ?? '') === 'committed' && $repo->findBySlug('parent')['title'] === 'Committed', 'terminal storage commit remains committed and its receipt is reconstructed');

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --crash ' . escapeshellarg($root), $output, $status);
    $external = '<p>External change after crash</p>';
    $files->write($config->paths()->contentPath('pages/renamed/body.html'), $external);
    $blocked = false;
    try { $repo->all(); } catch (RuntimeException $error) { $blocked = str_contains($error->getMessage(), 'external edit'); }
    checkTransaction($blocked && $files->read($config->paths()->contentPath('pages/renamed/body.html')) === $external, 'recovery must refuse to overwrite an unrelated external edit');
    echo "Content transaction checks passed\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}
function checkTransaction(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
