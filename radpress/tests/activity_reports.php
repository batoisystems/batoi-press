<?php
declare(strict_types=1);

use Batoi\Press\Application\ActivityReadService;
use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;

require dirname(__DIR__) . '/autoload.php';
$root = sys_get_temp_dir() . '/press-activity-' . bin2hex(random_bytes(6));
mkdir($root . '/radpress/config', 0700, true);
try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'radpress/data']);
    $config = Config::load($root);
    $service = new ActivityReadService($config->paths());
    checkActivity($service->report()['data'] === [], 'missing log is an empty history');
    $path = $config->paths()->dataPath('log/audit.jsonl');
    $revision = ContentRevision::for(['title' => 'Reviewed content']);
    $entry = ['id' => str_repeat('a', 16), 'time' => date(DATE_ATOM), 'action' => 'content.proposal.applied', 'outcome' => 'success', 'user' => 'owner', 'target' => 'proposal_' . str_repeat('b', 32), 'ip' => 'PRIVATE_IP', 'details' => ['password' => 'PRIVATE_PASSWORD', 'body' => 'PRIVATE_BODY', 'revision' => $revision]];
    $encoded = json_encode($entry) . "\n";
    $files->write($path, $encoded . json_encode(array_replace($entry, ['id' => str_repeat('d', 16), 'target' => '/private/SECRET_PATH', 'action' => 'admin.action'])) . "\n{unfinished");
    $report = $service->report(['limit' => '1']);
    checkActivity(count($report['data']) === 1 && $report['data'][0]['id'] === str_repeat('d', 16) && $report['results_truncated'], 'newest complete records first and overflow is explicit');
    checkActivity(!str_contains(json_encode($report), 'PRIVATE_') && !str_contains(json_encode($report), 'SECRET_PATH'), 'projection excludes raw details, IP and private targets');
    $filtered = $service->report(['action' => 'content.proposal.applied']);
    checkActivity(count($filtered['data']) === 1 && $filtered['data'][0]['proposal_id'] === $entry['target'] && $filtered['data'][0]['revision'] === $revision, 'exact filter retains safe review identifiers and production-format revision');
    foreach ([['limit' => 0], ['limit' => 101], ['limit' => []], ['action' => []], ['path' => '/etc/passwd']] as $invalid) {
        try { $service->report($invalid); throw new RuntimeException('invalid filter accepted'); }
        catch (ContentMutationException $exception) { checkActivity($exception->httpStatus() === 422, 'invalid filters return validation errors'); }
    }
    $files->write($path, str_repeat($encoded, ActivityReadService::MAX_LINES + 2));
    checkActivity($service->report()['window_truncated'], 'line scan is bounded');
    $files->write($path, str_repeat('x', ActivityReadService::MAX_BYTES + 20) . "\n" . $encoded);
    $bounded = $service->report();
    checkActivity($bounded['window_truncated'] && count($bounded['data']) === 1, 'oversized partial first record is discarded without hiding later records');
    echo "Activity report checks passed\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}
function checkActivity(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
