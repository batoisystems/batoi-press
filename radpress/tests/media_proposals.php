<?php
declare(strict_types=1);

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\IdempotencyStore;
use Batoi\Press\Application\SiteReadService;
use Batoi\Press\Content\MediaRepository;
use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Api\ApiController;
use Batoi\Press\Mcp\McpController;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\MachineAccessPolicy;
use Batoi\Press\Admin\MediaController;
use Batoi\Press\Admin\ProposalController;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-media-proposals-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Media test', 'base_url' => 'https://example.test/press']);
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [['username' => 'owner', 'role' => 'owner'], ['username' => 'editor', 'role' => 'editor']]]);
    $config = Config::load($root);
    $paths = $config->paths();
    $pages = new PageRepository($paths, $files, new HtmlContent());
    $posts = new PostRepository($paths, $files, new HtmlContent());
    $service = new ContentMutationService($config, $pages, $posts, new AuditLog($paths, $files), new IdempotencyStore($paths));
    $repository = new MediaRepository($paths);
    $assets = new AssetManager($paths);
    $tokens = new AccessTokenRepository($paths);
    $issued = $tokens->issue('Media manager', ['media:read', 'media:write'], 'owner', new DateTimeImmutable('+1 hour'));
    $access = (new MachineAccessPolicy($paths))->resolve($tokens->authenticate($issued['token']));
    $reader = $tokens->issue('Media reader', ['media:read'], 'owner', new DateTimeImmutable('+1 hour'));
    $upload = ['name' => 'notes.txt', 'content_base64' => base64_encode("A reviewed document.\n"), 'metadata' => ['title' => 'Reviewed notes', 'alt' => '', 'caption' => '<script>Untrusted text</script>']];
    $proposal = $service->proposeMediaUpload($upload, $access, 'media-upload-1');
    $record = $service->proposal($proposal['id']);
    $session = new Session('press_media_proposal_test', $paths->dataPath('sessions'));
    $csrf = new Csrf($session);
    $controller = new ProposalController($config, $service, $csrf, ['username' => 'owner', 'role' => 'owner']);
    pmCheck(str_contains($controller->show($record['id'])->content(), '&lt;script&gt;Untrusted text&lt;/script&gt;') && str_contains($controller->show($record['id'])->content(), '/media-preview'), 'review page escapes metadata and links a private inspection');
    $preview = $controller->mediaPreview($record['id']);
    pmCheck($preview->status() === 200 && $preview->content() === "A reviewed document.\n" && $preview->headers()['Cache-Control'] === 'private, no-store' && str_contains($preview->headers()['Content-Security-Policy'], 'sandbox'), 'private preview is bounded, non-cacheable and sandboxed');
    $files->writeJson($paths->configPath('security.json'), ['headers' => ['csp_mode' => 'enforce']]);
    $securedPreview = \Batoi\Press\Security\SecurityHeaders::apply($preview, new Request('GET', '/admin/proposals/' . $record['id'] . '/media-preview', [], [], []), Config::load($root));
    pmCheck(str_contains($securedPreview->headers()['Content-Security-Policy'], "sandbox; default-src 'none'") && $securedPreview->headers()['Referrer-Policy'] === 'no-referrer', 'global security headers preserve the stricter preview sandbox and referrer policy');
    pmCheck((new ProposalController($config, $service, $csrf, ['username' => 'editor', 'role' => 'editor']))->mediaPreview($record['id'])->status() === 403, 'editors cannot inspect owner-only upload reviews');
    pmCheck($assets->all() === [] && !str_contains(json_encode($proposal), $upload['content_base64']), 'staged uploads are private and response summaries contain no payload');
    $stagePath = $paths->dataPath('integrations/media/staging/' . $proposal['id'] . '.json');
    pmCheck((fileperms($stagePath) & 0777) === 0600, 'private staging files have restrictive permissions');
    pmCheck($service->proposeMediaUpload($upload, $access, 'media-upload-1')['id'] === $proposal['id'], 'upload proposals are idempotent');
    pmFails(fn () => $service->proposeMediaUpload(array_replace($upload, ['name' => 'different.txt']), $access, 'media-upload-1'), 'idempotency_conflict');
    pmFails(fn () => $service->reviewProposal($record['id'], 'wrong', 'owner', true), 'approval_mismatch');
    pmFails(fn () => $service->reviewProposal($record['id'], $record['approval_hash'], 'editor', true), 'forbidden');
    pmCheck($repository->stagedFile($record['id'], $record['after'])['bytes'] === "A reviewed document.\n", 'private inspection reads exactly the proposed bytes');
    $applied = $service->reviewProposal($record['id'], $record['approval_hash'], 'owner', true);
    pmCheck($applied['state'] === 'applied' && count($assets->all()) === 1, 'approval publishes one file only');
    pmCheck(!isset($files->readJson($stagePath)['content_base64']), 'completed staging drops temporary bytes but retains its manifest');
    $assetId = $record['target_id'];
    $current = $repository->load($assetId);
    pmCheck($current['metadata']['title'] === 'Reviewed notes' && file_get_contents($current['asset']['path']) === "A reviewed document.\n", 'published bytes and metadata match reviewed input');
    pmCheck($controller->mediaPreview($record['id'])->status() === 404, 'completed uploads are no longer available through private preview');
    pmFails(fn () => $service->reviewProposal($record['id'], $record['approval_hash'], 'owner', true), 'proposal_processed');
    $metadata = $service->proposeMediaMetadata($assetId, ['alt' => 'Updated library text'], $current['revision'], $access, 'metadata-change-1');
    $metadataRecord = $service->proposal($metadata['id']);
    pmCheck($repository->load($assetId)['metadata']['alt'] === '', 'metadata proposal does not alter the library before approval');
    $service->reviewProposal($metadata['id'], $metadataRecord['approval_hash'], 'owner', true);
    pmCheck($repository->load($assetId)['metadata']['alt'] === 'Updated library text', 'metadata approval persists reviewed alt text');
    $mediaController = new MediaController($config, $csrf, new AuditLog($paths, $files), ['username' => 'owner', 'role' => 'owner']);
    $metadataPage = $mediaController->edit(new Request('GET', '/admin/media/edit', ['storage' => 'assets', 'file' => $current['asset']['relative']], [], []));
    pmCheck(str_contains($metadataPage->content(), 'name="expected_revision"') && str_contains($metadataPage->content(), 'Updated library text') && !str_contains($metadataPage->content(), '<script>Untrusted text</script>'), 'browser metadata form shares escaped library values and a revision precondition');
    $formInput = ['csrf_token' => $csrf->token(), 'id' => $assetId, 'expected_revision' => $current['revision'], 'title' => 'Stale browser title'];
    pmCheck($mediaController->updateMetadata(new Request('POST', '/admin/media/update-metadata', [], $formInput, []))->status() === 409, 'stale browser metadata form cannot overwrite approved values');
    $badCsrf = array_replace($formInput, ['csrf_token' => 'invalid']);
    pmCheck($mediaController->updateMetadata(new Request('POST', '/admin/media/update-metadata', [], $badCsrf, []))->status() === 400, 'browser metadata changes require CSRF');
    pmFails(fn () => $service->proposeMediaMetadata($assetId, ['alt' => 'Stale'], $current['revision'], $access, 'metadata-stale-1'), 'revision_conflict');
    $latest = $repository->load($assetId);
    $stale = $service->proposeMediaMetadata($assetId, ['title' => 'Stale title'], $latest['revision'], $access, 'metadata-byte-conflict');
    $staleRecord = $service->proposal($stale['id']);
    $assets->updateText('assets', $current['asset']['relative'], "Different bytes, same URL.\n");
    pmFails(fn () => $service->reviewProposal($stale['id'], $staleRecord['approval_hash'], 'owner', true), 'revision_conflict');

    // Receipts survive subsequent edits and do not undo them during reconciliation.
    $receiptProposal = $service->proposal($metadata['id']);
    $receiptProposal['state'] = 'applying';
    $files->writeJson($paths->dataPath('integrations/proposals/' . $metadata['id'] . '.json'), $receiptProposal);
    $now = $repository->load($assetId);
    $repository->applyMetadata('proposal_' . bin2hex(random_bytes(16)), $assetId, ['alt' => 'Later browser edit'], $now['revision']);
    pmCheck($service->reconcileProposal($metadata['id'], $metadataRecord['approval_hash'], 'owner')['state'] === 'applied' && $repository->load($assetId)['metadata']['alt'] === 'Later browser edit', 'reconciliation keeps later media changes');

    // Fault checkpoints model a process ending before/after each persistent boundary.
    foreach (['journal', 'metadata', 'file', 'receipt'] as $point) {
        $operation = 'proposal_' . bin2hex(random_bytes(16));
        $expected = $repository->stage($operation, $upload, $access['id']);
        $fault = new MediaRepository($paths, $files, static function (string $at) use ($point): void { if ($at === $point) throw new RuntimeException('Injected interruption'); });
        try { $fault->applyUpload($operation, $expected); throw new LogicException('Interruption not injected'); } catch (RuntimeException $error) { pmCheck($error->getMessage() === 'Injected interruption', 'expected interruption reached'); }
        $temporary = dirname($paths->contentPath('assets/' . $expected['relative'])) . '/.bp-upload-' . substr($operation, 9);
        if ($point === 'metadata') file_put_contents($temporary, 'A rev'); // A process stopped during its private-to-hidden copy.
        $receipt = $repository->receipt($operation);
        pmCheck($receipt['state'] === (in_array($point, ['file', 'receipt'], true) ? 'committed' : 'not_applied'), 'upload recovery identifies exact publication boundary');
        pmCheck(($assets->find('assets', $expected['relative']) !== null) === ($receipt['state'] === 'committed'), 'receipt agrees with actual asset visibility');
        pmCheck(!is_file($temporary), 'recovery clears only verified incomplete temporary upload bytes');
        try { $repository->applyUpload($operation, $expected); throw new LogicException('Receipt replay accepted'); } catch (MenuConflictException) {}
    }
    foreach (['journal', 'metadata'] as $point) {
        $before = $repository->load($assetId);
        $operation = 'proposal_' . bin2hex(random_bytes(16));
        $fault = new MediaRepository($paths, $files, static function (string $at) use ($point): void { if ($at === $point) throw new RuntimeException('Injected interruption'); });
        try { $fault->applyMetadata($operation, $assetId, ['alt' => 'After ' . $point], $before['revision']); } catch (RuntimeException) {}
        pmCheck($repository->receipt($operation)['state'] === ($point === 'metadata' ? 'committed' : 'not_applied'), 'metadata interruption is reconciled without reapplying');
    }
    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $expected = $repository->stage($operation, $upload, $access['id']);
    $program = 'require ' . var_export(dirname(__DIR__) . '/autoload.php', true) . '; $config = Batoi\\Press\\Core\\Config::load($argv[1]); $repo = new Batoi\\Press\\Content\\MediaRepository($config->paths(), new Batoi\\Press\\Core\\FileStore(), static function ($point) { if ($point === "file") exit(73); }); $repo->applyUpload($argv[2], json_decode($argv[3], true));';
    $process = proc_open([PHP_BINARY, '-r', $program, $root, $operation, json_encode($expected)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    pmCheck(is_resource($process), 'crash worker starts');
    fclose($pipes[0]); $workerOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    pmCheck(proc_close($process) === 73 && $workerOutput === '', 'crash worker exits at the actual publication boundary');
    pmCheck($repository->receipt($operation)['state'] === 'committed' && $assets->find('assets', $expected['relative']) !== null, 'a fresh process reconciles a real interrupted upload');
    $quota = new MediaRepository($paths, $files, null, ['staged_bytes' => 4]);
    pmReject(fn () => $quota->stage('proposal_' . bin2hex(random_bytes(16)), $upload, $access['id']), 'staging quota');
    $quota = new MediaRepository($paths, $files, null, ['connection_bytes' => 4]);
    pmReject(fn () => $quota->stage('proposal_' . bin2hex(random_bytes(16)), $upload, $access['id']), 'connection quota');
    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $expected = $repository->stage($operation, $upload, $access['id']);
    $quota = new MediaRepository($paths, $files, null, ['managed_bytes' => 4]);
    pmReject(fn () => $quota->applyUpload($operation, $expected), 'managed-media quota');
    pmCheck($assets->find('assets', $expected['relative']) === null, 'quota refusal never publishes');
    $repository->releaseStage($operation);

    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $expected = $repository->stage($operation, $upload, $access['id']);
    $collision = $assets->prepareTarget($expected['relative']);
    file_put_contents($collision, 'Keep existing file');
    try { $repository->applyUpload($operation, $expected); throw new LogicException('Upload replaced a collision'); } catch (MenuConflictException) {}
    pmCheck(file_get_contents($collision) === 'Keep existing file', 'upload never overwrites an existing destination');
    $repository->releaseStage($operation);

    // A journal must not overwrite an unexpected external edit during recovery.
    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $before = $repository->load($assetId);
    $fault = new MediaRepository($paths, $files, static function (string $at): void { if ($at === 'metadata') throw new RuntimeException('Injected interruption'); });
    try { $fault->applyMetadata($operation, $assetId, ['alt' => 'Interrupted'], $before['revision']); } catch (RuntimeException) {}
    $metadataPath = $paths->dataPath('integrations/media/metadata/' . $assetId . '.json');
    $known = $files->readJson($metadataPath);
    $external = array_replace($known, ['metadata' => ['title' => 'External editor', 'alt' => '', 'caption' => '']]);
    $files->writeJson($metadataPath, $external);
    try { $repository->receipt($operation); throw new LogicException('External edit overwritten'); } catch (RuntimeException) {}
    pmCheck($files->readJson($metadataPath) === $external, 'recovery preserves unexpected external metadata');
    $files->writeJson($metadataPath, $known); // Restore only this isolated test's own injected state.
    pmCheck($repository->receipt($operation)['state'] === 'committed', 'recovery resumes after fixture conflict is resolved');

    $api = new ApiController($config, $pages, $posts);
    $mediaRequest = static fn (string $method, string $path, string $token, array $input = [], array $headers = []): Request => new Request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'] + $headers, json_encode($input));
    $get = $api->handle($mediaRequest('GET', '/api/v2/media/' . $assetId, $reader['token']));
    pmCheck($get->status() === 200 && !str_contains($get->content(), $root) && str_contains($get->content(), 'revision'), 'media-only read exposes metadata/revision without filesystem paths');
    pmCheck($api->handle($mediaRequest('GET', '/api/v2/pages', $reader['token']))->status() === 403, 'media read does not expose drafts');
    pmCheck($api->handle($mediaRequest('POST', '/api/v2/media/uploads', $reader['token'], $upload, ['HTTP_IDEMPOTENCY_KEY' => 'api-upload-reader']))->status() === 403, 'read-only upload denied');
    $response = $api->handle($mediaRequest('POST', '/api/v2/media/uploads', $issued['token'], $upload, ['HTTP_IDEMPOTENCY_KEY' => 'api-upload-writer']));
    $apiProposal = json_decode($response->content(), true)['data'];
    pmCheck($response->status() === 202 && $apiProposal['state'] === 'pending', 'API upload returns pending review rather than publication');
    pmCheck($api->handle($mediaRequest('GET', '/api/v2/media-proposals/' . $apiProposal['id'], $issued['token']))->status() === 200, 'API upload status is available to originating connection');
    pmCheck($api->handle($mediaRequest('GET', '/api/v2/media-proposals/' . $apiProposal['id'], $reader['token']))->status() === 404, 'other connections cannot read proposal status');
    $apiMetadata = $api->handle($mediaRequest('POST', '/api/v2/media/' . $assetId . '/proposals', $issued['token'], ['caption' => 'API caption'], ['HTTP_IDEMPOTENCY_KEY' => 'api-media-metadata', 'HTTP_IF_MATCH' => $repository->load($assetId)['revision']]));
    pmCheck($apiMetadata->status() === 202 && $repository->load($assetId)['metadata']['caption'] !== 'API caption', 'API metadata changes wait for approval');
    $mcp = new McpController($config, $pages, $posts);
    $rpc = static fn (string $method, array $params, string $token): Request => $mediaRequest('POST', '/mcp', $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25']);
    $names = array_column(json_decode($mcp->handle($rpc('tools/list', [], $reader['token']))->content(), true)['result']['tools'], 'name');
    pmCheck(in_array('media_get', $names, true) && !in_array('page_get', $names, true) && !in_array('media_propose_upload', $names, true), 'media scope filters MCP discovery without granting content access');
    $result = json_decode($mcp->handle($rpc('tools/call', ['name' => 'media_propose_upload', 'arguments' => ['upload' => $upload, 'idempotency_key' => 'mcp-upload-1']], $issued['token']))->content(), true);
    pmCheck(($result['result']['structuredContent']['state'] ?? '') === 'pending', 'MCP upload shares pending approval semantics');
    $result = json_decode($mcp->handle($rpc('tools/call', ['name' => 'media_propose_metadata', 'arguments' => ['id' => $assetId, 'changes' => ['caption' => 'MCP caption'], 'expected_revision' => $repository->load($assetId)['revision'], 'idempotency_key' => 'mcp-metadata-1']], $issued['token']))->content(), true);
    pmCheck(($result['result']['structuredContent']['state'] ?? '') === 'pending' && $repository->load($assetId)['metadata']['caption'] !== 'MCP caption', 'MCP metadata changes share pending approval semantics');
    $result = json_decode($mcp->handle($rpc('tools/call', ['name' => 'media_propose_upload', 'arguments' => ['upload' => $upload, 'idempotency_key' => 'mcp-upload-denied']], $reader['token']))->content(), true);
    pmCheck(isset($result['error']) || ($result['result']['isError'] ?? false), 'direct unauthorized MCP upload is denied');

    $revoked = $service->proposeMediaUpload($upload, $access, 'media-revoked-1');
    $revokedRecord = $service->proposal($revoked['id']);
    $tokens->revoke($access['id']);
    pmFails(fn () => $service->reviewProposal($revoked['id'], $revokedRecord['approval_hash'], 'owner', true), 'connection_revoked');
    pmCheck($service->reviewProposal($revoked['id'], $revokedRecord['approval_hash'], 'owner', false)['state'] === 'rejected', 'administrator can reject and release a revoked upload');
    echo "Media proposal checks passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isLink() || !$item->isDir() ? unlink((string)$item) : rmdir((string)$item);
    rmdir($root);
}
function pmCheck(bool $ok, string $message): void { if (!$ok) throw new LogicException($message); }
function pmFails(callable $callback, string $code): void { try { $callback(); } catch (ContentMutationException $error) { pmCheck($error->errorCode() === $code, 'Expected ' . $code . ', got ' . $error->errorCode()); return; } throw new LogicException('Expected ' . $code); }
function pmReject(callable $callback, string $message): void { try { $callback(); } catch (InvalidArgumentException) { return; } throw new LogicException($message); }
