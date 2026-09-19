<?php
declare(strict_types=1);

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Content\WebsiteDocumentStore;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;

require dirname(__DIR__) . '/autoload.php';
$root = sys_get_temp_dir() . '/press-website-documents-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
try {
    $paths = new Paths($root, ['content' => 'radpress/content', 'data' => 'radpress/data']);
    $files = new FileStore();
    $store = new WebsiteDocumentStore($paths);
    checkDocument($store->read('widgets') === [], 'missing document has an empty base revision');
    $original = ['widgets' => [['type' => 'recent_posts', 'title' => 'Recent posts']]];
    $store->commit('widgets', $original, ContentRevision::for([]));
    try { $store->commit('widgets', [], ContentRevision::for([])); throw new LogicException('Stale revision accepted'); }
    catch (MenuConflictException $exception) { checkDocument($store->read('widgets') === $original, 'conflict preserves original bytes'); }
    foreach (['journal' => 'not_applied', 'written' => 'committed'] as $stage => $state) {
        $before = $store->read('widgets');
        $after = ['widgets' => [['type' => 'tag_cloud', 'title' => $stage]]];
        $id = 'proposal_' . bin2hex(random_bytes(16));
        $interrupted = new WebsiteDocumentStore($paths, $files, static function (string $point) use ($stage): void { if ($point === $stage) throw new RuntimeException('Injected interruption'); });
        try { $interrupted->commit('widgets', $after, ContentRevision::for($before), $id); throw new LogicException('Interruption not triggered'); }
        catch (RuntimeException $exception) { checkDocument($exception->getMessage() === 'Injected interruption', 'expected injected failure'); }
        checkDocument($store->receipt('widgets', $id)['state'] === $state, 'receipt identifies interruption boundary');
        checkDocument($store->read('widgets') === ($state === 'committed' ? $after : $before), 'recovery never applies or rewrites a document');
        $store->commit('widgets', $original, ContentRevision::for($store->read('widgets')));
        checkDocument($store->receipt('widgets', $id)['state'] === $state, 'independent receipt survives later browser saves');
    }
    try { $store->read('../config/security'); throw new LogicException('Arbitrary resource accepted'); }
    catch (RuntimeException $exception) { checkDocument($exception->getMessage() === 'Unsupported website document.', 'only fixed resources may be accessed'); }
    $siteOriginal = ['name' => 'Before', 'private_marker' => 'PRESERVE'];
    $store->commit('site', $siteOriginal, ContentRevision::for([]));
    $siteOperation = 'proposal_' . bin2hex(random_bytes(16));
    $siteInterrupted = new WebsiteDocumentStore($paths, $files, static function (string $point): void { if ($point === 'written') throw new RuntimeException('Injected site interruption'); });
    try { $siteInterrupted->commit('site', array_replace($siteOriginal, ['name' => 'After']), ContentRevision::for($siteOriginal), $siteOperation); } catch (RuntimeException $exception) {}
    $reloadedConfig = \Batoi\Press\Core\Config::load($root);
    checkDocument($reloadedConfig->site()['name'] === 'After' && $reloadedConfig->site()['private_marker'] === 'PRESERVE' && $store->receipt('site', $siteOperation)['state'] === 'committed', 'configuration bootstrap reconciles a committed site document without losing private keys');
    $id = 'proposal_' . bin2hex(random_bytes(16));
    $interrupted = new WebsiteDocumentStore($paths, $files, static function (): void { throw new RuntimeException('Injected interruption'); });
    try { $interrupted->commit('widgets', ['widgets' => []], ContentRevision::for($original), $id); } catch (RuntimeException $exception) {}
    $external = ['widgets' => [['type' => 'html', 'title' => 'External edit', 'body' => 'Preserve']]];
    $files->writeJson($paths->contentPath('widgets/sidebar.json'), $external);
    try { $store->read('widgets'); throw new LogicException('Unexpected external edit accepted'); }
    catch (RuntimeException $exception) { checkDocument(str_contains($exception->getMessage(), 'unexpected external changes'), 'ambiguous recovery stops'); }
    checkDocument($files->readJson($paths->contentPath('widgets/sidebar.json')) === $external, 'external changes are never overwritten');
    echo "Website document checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}
function checkDocument(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
