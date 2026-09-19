<?php
declare(strict_types=1);

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\IdempotencyStore;
use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\MachineAccessPolicy;

require dirname(__DIR__) . '/autoload.php';
$root = sys_get_temp_dir() . '/press-menu-proposals-' . bin2hex(random_bytes(6));
mkdir($root . '/radpress/config', 0700, true);
try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['base_url' => 'https://example.test/press', 'homepage' => 'home']);
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [['username' => 'owner', 'role' => 'owner'], ['username' => 'editor', 'role' => 'editor']]]);
    $config = Config::load($root);
    $pages = new PageRepository($config->paths(), $files, new HtmlContent());
    $posts = new PostRepository($config->paths(), $files, new HtmlContent());
    $pages->save(['title' => 'Home', 'slug' => 'home', 'status' => 'published', 'body' => '<p>Live</p>'], 'owner');
    $menus = new MenuRepository($config->paths(), $files);
    $items = [['id' => 'mi_home', 'type' => 'page', 'label' => 'Home', 'url' => '/', 'enabled' => true]];
    $menus->save(['items' => $items], 'owner', 0);
    $service = new ContentMutationService($config, $pages, $posts, new AuditLog($config->paths(), $files), new IdempotencyStore($config->paths()));
    $tokens = new AccessTokenRepository($config->paths());
    $issued = $tokens->issue('Website manager', ['site:read', 'site:write'], 'owner', new DateTimeImmutable('+1 hour'));
    $access = (new MachineAccessPolicy($config->paths()))->resolve($tokens->authenticate($issued['token']));
    $before = $menus->load();
    $proposal = $service->proposeMenu('main', ['name' => 'Reviewed navigation'], 1, $access, 'menu-proposal-0001');
    checkMenuProposal($menus->load() === $before, 'proposal leaves live menu unchanged');
    checkMenuProposal($service->proposeMenu('main', ['name' => 'Reviewed navigation'], 1, $access, 'menu-proposal-0001')['id'] === $proposal['id'], 'idempotent retry returns the original proposal');
    $record = $service->proposal($proposal['id']);
    failsMenuProposal(fn () => $service->reviewProposal($record['id'], 'wrong', 'owner', true), 'approval_mismatch');
    failsMenuProposal(fn () => $service->reviewProposal($record['id'], $record['approval_hash'], 'editor', true), 'forbidden');
    failsMenuProposal(fn () => $service->menuProposalSummary($record['id'], array_replace($access, ['id' => 'another'])), 'not_found');
    $applied = $service->reviewProposal($record['id'], $record['approval_hash'], 'owner', true);
    checkMenuProposal($applied['state'] === 'applied' && $menus->load()['name'] === 'Reviewed navigation' && $menus->load()['revision'] === 2, 'approved proposal applies one new menu revision');
    failsMenuProposal(fn () => $service->reviewProposal($record['id'], $record['approval_hash'], 'owner', true), 'proposal_processed');
    $receipt = $service->proposal($record['id']);
    $receipt['state'] = 'applying';
    $files->writeJson($config->paths()->dataPath('integrations/proposals/' . $record['id'] . '.json'), $receipt);
    $menus->save(['name' => 'Later browser edit', 'items' => $items], 'owner', 2);
    checkMenuProposal($service->reconcileProposal($record['id'], $record['approval_hash'], 'owner')['state'] === 'applied' && $menus->load()['name'] === 'Later browser edit', 'receipt reconciliation never overwrites subsequent browser edits');
    $snapshots = count(glob($config->paths()->dataPath('versions/menus/main/*.json')) ?: []);
    try { $menus->save(['name' => 'Replay', 'items' => $items], 'owner', 3, 'main', $record['id']); throw new LogicException('Receipt replay accepted'); }
    catch (\Batoi\Press\Content\MenuConflictException $exception) { checkMenuProposal(count(glob($config->paths()->dataPath('versions/menus/main/*.json')) ?: []) === $snapshots, 'rejected receipt replay creates no duplicate snapshots'); }

    $stale = $service->proposeMenu('main', ['name' => 'Stale'], 3, $access, 'menu-stale-0001');
    $staleRecord = $service->proposal($stale['id']);
    $menus->save(['name' => 'Browser wins', 'items' => $items], 'owner', 3);
    failsMenuProposal(fn () => $service->reviewProposal($stale['id'], $staleRecord['approval_hash'], 'owner', true), 'revision_conflict');
    $references = $service->proposeMenu('main', ['name' => 'Check targets'], 4, $access, 'menu-references-1');
    $referenceRecord = $service->proposal($references['id']);
    $pages->save(['original_slug' => 'home', 'title' => 'Home', 'slug' => 'home', 'status' => 'draft'], 'owner');
    failsMenuProposal(fn () => $service->reviewProposal($references['id'], $referenceRecord['approval_hash'], 'owner', true), 'validation_failed');
    checkMenuProposal($menus->load()['revision'] === 4, 'broken target revalidation does not publish navigation');
    $pages->save(['original_slug' => 'home', 'title' => 'Home', 'slug' => 'home', 'status' => 'published'], 'owner');
    $widgetRepository = new \Batoi\Press\Content\WidgetRepository($config->paths());
    $initialWidgets = $widgetRepository->load();
    $widgetProposal = $service->proposeWidgets([['type' => 'html', 'title' => 'Reviewed widget', 'body' => '<p>Reviewed</p><script>bad()</script>']], $initialWidgets['revision'], $access, 'widget-proposal-1');
    $widgetRecord = $service->proposal($widgetProposal['id']);
    checkMenuProposal($widgetRepository->load() === $initialWidgets && $widgetRecord['after']['widgets'][1]['body'] === '<p>Reviewed</p>', 'widget proposals sanitize without changing live sidebar');
    failsMenuProposal(fn () => $service->reviewProposal($widgetRecord['id'], $widgetRecord['approval_hash'], 'editor', true), 'forbidden');
    $service->reviewProposal($widgetRecord['id'], $widgetRecord['approval_hash'], 'owner', true);
    checkMenuProposal($widgetRepository->load()['widgets'][1]['title'] === 'Reviewed widget', 'approved widget proposal applies the reviewed list');
    failsMenuProposal(fn () => $service->reviewProposal($widgetRecord['id'], $widgetRecord['approval_hash'], 'owner', true), 'proposal_processed');
    $widgetReceipt = $service->proposal($widgetRecord['id']);
    $widgetReceipt['state'] = 'applying';
    $files->writeJson($config->paths()->dataPath('integrations/proposals/' . $widgetRecord['id'] . '.json'), $widgetReceipt);
    $afterWidgets = $widgetRepository->load();
    $widgetRepository->save([['type' => 'tag_cloud', 'title' => 'Later widget edit']], $afterWidgets['revision']);
    checkMenuProposal($service->reconcileProposal($widgetRecord['id'], $widgetRecord['approval_hash'], 'owner')['state'] === 'applied' && $widgetRepository->load()['widgets'][1]['title'] === 'Later widget edit', 'widget receipt reconciliation preserves subsequent browser edits');
    $currentWidgets = $widgetRepository->load();
    $staleWidgets = $service->proposeWidgets([['type' => 'tag_cloud', 'title' => 'Stale widget']], $currentWidgets['revision'], $access, 'widget-stale-1');
    $staleWidgetRecord = $service->proposal($staleWidgets['id']);
    $widgetRepository->save([['type' => 'tag_cloud', 'title' => 'New browser widget']], $currentWidgets['revision']);
    failsMenuProposal(fn () => $service->reviewProposal($staleWidgets['id'], $staleWidgetRecord['approval_hash'], 'owner', true), 'revision_conflict');
    $settingsRepository = new \Batoi\Press\Content\PublicSettingsRepository($config->paths());
    $siteBefore = $files->readJson($config->paths()->configPath('site.json'));
    $settingsProposal = $service->proposePublicSettings(['name' => 'Approved site name'], $settingsRepository->load()['revision'], $access, 'settings-proposal-1');
    $settingsRecord = $service->proposal($settingsProposal['id']);
    checkMenuProposal($files->readJson($config->paths()->configPath('site.json')) === $siteBefore, 'public settings proposal leaves live configuration unchanged');
    failsMenuProposal(fn () => $service->reviewProposal($settingsRecord['id'], $settingsRecord['approval_hash'], 'editor', true), 'forbidden');
    $service->reviewProposal($settingsRecord['id'], $settingsRecord['approval_hash'], 'owner', true);
    checkMenuProposal($settingsRepository->load()['settings']['name'] === 'Approved site name', 'owner approval applies public settings');
    failsMenuProposal(fn () => $service->reviewProposal($settingsRecord['id'], $settingsRecord['approval_hash'], 'owner', true), 'proposal_processed');
    $settingsReceipt = $service->proposal($settingsRecord['id']);
    $settingsReceipt['state'] = 'applying';
    $files->writeJson($config->paths()->dataPath('integrations/proposals/' . $settingsRecord['id'] . '.json'), $settingsReceipt);
    $siteStore = new \Batoi\Press\Content\WebsiteDocumentStore($config->paths());
    $siteNow = $siteStore->read('site');
    $siteStore->commit('site', array_replace($siteNow, ['tagline' => 'Later edit']), ContentRevision::for($siteNow));
    checkMenuProposal($service->reconcileProposal($settingsRecord['id'], $settingsRecord['approval_hash'], 'owner')['state'] === 'applied' && $settingsRepository->load()['settings']['tagline'] === 'Later edit', 'settings receipt reconciliation preserves later changes');
    $staleSettings = $service->proposePublicSettings(['name' => 'Stale name'], $settingsRepository->load()['revision'], $access, 'settings-stale-1');
    $staleSettingsRecord = $service->proposal($staleSettings['id']);
    $siteNow = $siteStore->read('site');
    $siteStore->commit('site', array_replace($siteNow, ['name' => 'Browser name']), ContentRevision::for($siteNow));
    failsMenuProposal(fn () => $service->reviewProposal($staleSettings['id'], $staleSettingsRecord['approval_hash'], 'owner', true), 'revision_conflict');
    $tokens->revoke($access['id']);
    // Revocation blocks public settings as well as content and navigation.
    $settingsRepository = new \Batoi\Press\Content\PublicSettingsRepository($config->paths());
    failsMenuProposal(fn () => $service->proposePublicSettings(['name' => 'Denied'], $settingsRepository->load()['revision'], $access, 'settings-revoked-1'), 'connection_revoked');
    failsMenuProposal(fn () => $service->reviewProposal($references['id'], $referenceRecord['approval_hash'], 'owner', true), 'connection_revoked');

    foreach (['journal' => 'not_applied', 'written' => 'committed'] as $stage => $state) {
        $current = $menus->load();
        $operation = 'proposal_' . bin2hex(random_bytes(16));
        $interrupted = new MenuRepository($config->paths(), $files, static function (string $checkpoint) use ($stage): void { if ($checkpoint === $stage) throw new RuntimeException('Injected interruption'); });
        try { $interrupted->save(['name' => 'Interrupted ' . $stage, 'items' => $items], 'owner', $current['revision'], 'main', $operation, ContentRevision::for($current)); throw new LogicException('Interruption not triggered'); }
        catch (RuntimeException $exception) { checkMenuProposal($exception->getMessage() === 'Injected interruption', 'expected injected failure'); }
        $resolved = $menus->operationReceipt('main', $operation);
        checkMenuProposal($resolved['state'] === $state, 'journal distinguishes before-write from after-write interruptions');
        checkMenuProposal($menus->load()['revision'] === $current['revision'] + ($state === 'committed' ? 1 : 0), 'recovery never applies the document a second time');
    }
    $current = $menus->load();
    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $interrupted = new MenuRepository($config->paths(), $files, static function (): void { throw new RuntimeException('Injected interruption'); });
    try { $interrupted->save(['name' => 'External conflict', 'items' => $items], 'owner', $current['revision'], 'main', $operation); } catch (RuntimeException $exception) {}
    $external = array_replace($current, ['name' => 'Unexpected external edit']);
    $files->writeJson($config->paths()->contentPath('menus/main.json'), $external);
    try { $menus->load(); throw new LogicException('External conflict accepted'); }
    catch (RuntimeException $exception) { checkMenuProposal(str_contains($exception->getMessage(), 'unexpected external'), 'external changes block ambiguous recovery'); }
    checkMenuProposal($files->readJson($config->paths()->contentPath('menus/main.json')) === $external, 'recovery does not overwrite external edits');
    echo "Menu proposal checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}
function checkMenuProposal(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function failsMenuProposal(callable $callback, string $code): void {
    try { $callback(); } catch (ContentMutationException $exception) { checkMenuProposal($exception->errorCode() === $code, 'Expected ' . $code . ', received ' . $exception->errorCode()); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
