<?php
declare(strict_types=1);

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\IdempotencyStore;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\MachineAccessPolicy;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Admin\ProposalController;
use Batoi\Press\Core\Request;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/batoi-press-proposals-' . bin2hex(random_bytes(6));
mkdir($root . '/radpress/config', 0700, true);
try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['base_url' => 'https://press.example.test/subdirectory']);
    $password = 'Proposal-Review-Fixture!';
    $users = [['username' => 'owner', 'role' => 'owner', 'password_hash' => Password::hash($password)], ['username' => 'editor', 'role' => 'editor'], ['username' => 'viewer', 'role' => 'viewer']];
    $files->writeJson($root . '/radpress/config/users.json', ['users' => $users]);
    $config = Config::load($root);
    $pages = new PageRepository($config->paths(), $files, new HtmlContent());
    $posts = new PostRepository($config->paths(), $files, new HtmlContent());
    $service = new ContentMutationService($config, $pages, $posts, new AuditLog($config->paths(), $files), new IdempotencyStore($config->paths()));
    $session = new Session('press_proposal_test', $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $controller = new ProposalController($config, $service, $csrf, $users[0]);
    $emptyQueue = $controller->index();
    checkProposal(str_contains($emptyQueue->content(), 'No proposed changes') && !str_contains($emptyQueue->content(), 'Showing the most recent 100 proposals'), 'empty queue explains its state instead of rendering an empty table');
    checkProposal(($emptyQueue->headers()['Cache-Control'] ?? '') === 'private, no-store', 'empty review queue remains private and non-cacheable');
    $tokens = new AccessTokenRepository($config->paths());
    $issued = $tokens->issue('Proposal editor', ['content:read', 'content:write', 'content:publish'], 'owner', new DateTimeImmutable('+1 hour'), 'editor');
    $access = (new MachineAccessPolicy($config->paths()))->resolve($issued['access']);
    $pages->save(['title' => 'About', 'slug' => 'about', 'body' => '<p>Original</p>', 'status' => 'published'], 'owner');
    $existing = $pages->findBySlug('about');
    $revision = ContentRevision::for($existing);
    $proposal = $service->propose('page', 'about', ['body' => '<p>Reviewed</p><script>bad()</script>'], $revision, $access, 'proposal-create-0001');
    checkProposal($pages->findBySlug('about') === $existing, 'preparation must not change the published record');
    checkProposal($proposal['state'] === 'pending' && str_starts_with($proposal['review_url'], 'https://press.example.test/subdirectory/admin/proposals/'), 'proposal exposes a subdirectory-safe review URL');
    $record = $service->proposal($proposal['id'], $access);
    $proposalPath = $config->paths()->dataPath('integrations/proposals/' . $proposal['id'] . '.json');
    $originalProposalBytes = $files->read($proposalPath);
    $altered = $record;
    $altered['after']['title'] = 'Untrusted altered proposal';
    $files->writeJson($proposalPath, $altered);
    failsProposal(fn () => $service->proposal($proposal['id']), 'proposal_invalid');
    failsProposal(fn () => $service->reviewProposal($proposal['id'], $record['approval_hash'], 'owner', true), 'proposal_invalid');
    $unavailableQueue = $controller->index()->content();
    checkProposal(str_contains($unavailableQueue, 'Unavailable:') && !str_contains($unavailableQueue, 'Untrusted altered proposal') && !str_contains($unavailableQueue, 'Review changes'), 'invalid records are visible as non-actionable warnings without trusting their metadata');
    checkProposal($files->readJson($proposalPath) === $altered && $pages->findBySlug('about') === $existing, 'invalid-record listing and refused approval preserve storage and live content');
    $files->write($proposalPath, '{invalid-json');
    checkProposal(str_contains($controller->index()->content(), 'Unavailable:'), 'malformed JSON does not break the review queue');
    $files->write($proposalPath, $originalProposalBytes);
    $sitePath = $config->paths()->configPath('site.json');
    $originalSiteBytes = $files->read($sitePath);
    $files->writeJson($sitePath, ['base_url' => 'https://other-installation.example.test']);
    $otherConfig = Config::load($root);
    $otherService = new ContentMutationService($otherConfig, $pages, $posts, new AuditLog($otherConfig->paths(), $files), new IdempotencyStore($otherConfig->paths()));
    failsProposal(fn () => $otherService->reviewProposal($proposal['id'], $record['approval_hash'], 'owner', true), 'proposal_invalid');
    checkProposal(!empty($otherService->proposals()[0]['unavailable']) && $pages->findBySlug('about') === $existing, 'changed site identity refuses approval and lists an unavailable record');
    $files->write($sitePath, $originalSiteBytes);
    checkProposal($service->proposal($proposal['id']) === $record, 'returning the unchanged proposal to its original site retains its integrity');
    $files->write($config->paths()->dataPath('integrations/proposals/proposal_' . str_repeat('f', 32) . '.json'), '{invalid-fixture');
    $mixedQueue = $controller->index()->content();
    checkProposal(str_contains($mixedQueue, 'Unavailable:') && str_contains($mixedQueue, '/admin/proposals/' . $proposal['id']) && str_contains($mixedQueue, 'Review changes'), 'an unreadable record does not hide the valid proposal or its review action');
    checkProposal($record['after']['body'] === '<p>Reviewed</p>', 'preview uses repository-sanitized changes');
    checkProposal(str_contains($controller->index()->content(), 'Review changes') && !str_contains($controller->index()->content(), 'No proposed changes'), 'nonempty queue displays its review action');
    $screen = $controller->show($proposal['id']);
    checkProposal(($screen->headers()['Cache-Control'] ?? '') === 'private, no-store' && str_contains($screen->content(), '&lt;p&gt;Reviewed&lt;/p&gt;'), 'private preview escapes content instead of executing it');
    checkProposal(AdminAccess::canAccess($users[1], '/admin/proposals/' . $proposal['id']) && !AdminAccess::canAccess($users[2], '/admin/proposals'), 'review UI permits publishing roles only');
    $badCsrf = $controller->review($proposal['id'], new Request('POST', '/admin/proposals/review', [], ['decision' => 'approve', 'approval_hash' => $record['approval_hash'], 'current_password' => $password], []));
    checkProposal($badCsrf->status() === 400 && $pages->findBySlug('about') === $existing, 'review requires CSRF and leaves live content unchanged');
    $badPassword = $controller->review($proposal['id'], new Request('POST', '/admin/proposals/review', [], ['csrf_token' => $csrf->token(), 'decision' => 'approve', 'approval_hash' => $record['approval_hash'], 'current_password' => 'incorrect'], []));
    checkProposal($badPassword->status() === 403 && $pages->findBySlug('about') === $existing, 'approval requires current password step-up');
    $replay = $service->propose('page', 'about', ['body' => '<p>Reviewed</p><script>bad()</script>'], $revision, $access, 'proposal-create-0001');
    checkProposal($replay['id'] === $proposal['id'], 'retry returns the same proposal');
    failsProposal(fn () => $service->proposal($proposal['id'], array_replace($access, ['id' => 'different'])), 'not_found');
    failsProposal(fn () => $service->reviewProposal($proposal['id'], 'wrong', 'owner', true), 'approval_mismatch');
    failsProposal(fn () => $service->reviewProposal($proposal['id'], $record['approval_hash'], 'viewer', true), 'forbidden');
    $approvedResponse = $controller->review($proposal['id'], new Request('POST', '/admin/proposals/review', [], ['csrf_token' => $csrf->token(), 'decision' => 'approve', 'approval_hash' => $record['approval_hash'], 'current_password' => $password], []));
    checkProposal($approvedResponse->status() === 302, 'review POST applies and redirects to the receipt');
    $applied = $service->proposal($proposal['id']);
    checkProposal($applied['state'] === 'applied' && $pages->findBySlug('about')['body'] === '<p>Reviewed</p>', 'approval promotes normalized content');
    checkProposal($pages->findBySlug('about')['status'] === 'published', 'editing preserves live status');
    failsProposal(fn () => $service->reviewProposal($proposal['id'], $record['approval_hash'], 'owner', true), 'proposal_processed');
    $applied['state'] = 'applying'; // Simulate a lost approval receipt after a committed storage transaction.
    $files->writeJson($config->paths()->dataPath('integrations/proposals/' . $proposal['id'] . '.json'), $applied);
    $beforeReconcile = $pages->findBySlug('about');
    failsProposal(fn () => $service->reconcileProposal($proposal['id'], $record['approval_hash'], 'editor'), 'forbidden');
    $reconciled = $service->reconcileProposal($proposal['id'], $record['approval_hash'], 'owner');
    checkProposal($reconciled['state'] === 'applied' && $pages->findBySlug('about') === $beforeReconcile, 'reconciliation recovers committed receipt without applying content again');
    $restoration = $service->proposeRestoration($proposal['id'], ContentRevision::for($pages->findBySlug('about')), $access, 'restore-proposal-1');
    checkProposal($pages->findBySlug('about')['body'] === '<p>Reviewed</p>', 'restoration remains a proposal until approved');
    $restorationRecord = $service->proposal($restoration['id']);
    $service->reviewProposal($restoration['id'], $restorationRecord['approval_hash'], 'owner', true);
    checkProposal($pages->findBySlug('about')['body'] === '<p>Original</p>' && $pages->findBySlug('about')['status'] === 'published', 'restoration restores editorial values as a new revision without changing visibility');

    $current = $pages->findBySlug('about');
    $conflict = $service->propose('page', 'about', ['title' => 'Proposed'], ContentRevision::for($current), $access, 'proposal-conflict-1');
    $conflictRecord = $service->proposal($conflict['id']);
    $service->saveFromAdmin('page', ['original_slug' => 'about', 'title' => 'Browser edit', 'status' => 'published'], ContentRevision::for($current), 'owner');
    failsProposal(fn () => $service->reviewProposal($conflict['id'], $conflictRecord['approval_hash'], 'owner', true), 'revision_conflict');
    checkProposal($pages->findBySlug('about')['title'] === 'Browser edit', 'stale approval never overwrites browser changes');

    $current = $pages->findBySlug('about');
    $unpublish = $service->propose('page', 'about', [], ContentRevision::for($current), $access, 'proposal-unpublish', 'unpublish');
    $unpublishRecord = $service->proposal($unpublish['id']);
    $service->reviewProposal($unpublish['id'], $unpublishRecord['approval_hash'], 'owner', true);
    checkProposal($pages->findBySlug('about')['status'] === 'archived', 'unpublish is explicit and reversible');
    checkProposal($pages->findBySlug('about')['body'] === $current['body'], 'unpublish does not delete content');

    // Exercise the complete proposal lifecycle for both repositories, not just
    // repository-level scheduling. Time boundaries use an explicit clock.
    $draftCredential = $tokens->issue('Draft-only fixture', ['content:read', 'content:write'], 'owner', new DateTimeImmutable('+1 hour'), 'editor');
    $draftAccess = (new MachineAccessPolicy($config->paths()))->resolve($draftCredential['access']);
    $publishTime = time() + 3600;
    $endTime = $publishTime + 3600;
    foreach (['page' => $pages, 'post' => $posts] as $type => $repository) {
        $slug = 'lifecycle-' . $type;
        $repository->save(['title' => 'Lifecycle ' . $type, 'slug' => $slug, 'body' => '<p>Lifecycle body</p>', 'status' => 'draft'], 'owner');
        $draft = $repository->findBySlug($slug);
        $dates = ['publish_at' => date(DATE_ATOM, $publishTime), 'unpublish_at' => date(DATE_ATOM, $endTime), 'workflow_note' => '  Reviewed launch window  ', 'seo_title' => 'Launch search title', 'seo_description' => 'Reviewed search description'];
        $dates += $type === 'page'
            ? ['show_latest_posts' => true, 'latest_posts_limit' => 4, 'blocks' => [
                ['type' => 'html', 'body' => '<p>Lifecycle body</p><script>unsafe()</script>'],
                ['type' => 'posts', 'title' => 'News', 'limit' => 4, 'show_image' => false, 'show_date' => true, 'show_read_more' => true],
                ['type' => 'gallery', 'body' => '<figure><img src="/assets/team.png" alt="Team"></figure>'],
                ['type' => 'products', 'title' => 'Products', 'limit' => 3],
                ['type' => 'widget', 'widget' => 'Recent Posts'],
            ]]
            : ['subtitle' => 'Launch summary', 'category' => 'Product News', 'tags' => ['Launch', 'Product'], 'featured_image' => '/assets/team.png', 'featured_image_alt' => 'Launch team', 'layout' => 'sidebar-right', 'post_type' => 'news'];
        failsProposal(fn () => $service->propose($type, $slug, $dates, ContentRevision::for($draft), $draftAccess, 'schedule-denied-' . $type, 'schedule'), 'connection_revoked');
        failsProposal(fn () => $service->propose($type, $slug, [], ContentRevision::for($draft), $access, 'schedule-missing-' . $type, 'schedule'), 'validation_failed');
        failsProposal(fn () => $service->propose($type, $slug, ['publish_at' => 'not-a-date'], ContentRevision::for($draft), $access, 'schedule-invalid-' . $type, 'schedule'), 'validation_failed');
        checkProposal($repository->findBySlug($slug) === $draft, $type . ' invalid or unauthorized scheduling leaves the draft unchanged');
        $scheduledProposal = $service->propose($type, $slug, $dates, ContentRevision::for($draft), $access, 'schedule-valid-' . $type, 'schedule');
        checkProposal($repository->findBySlug($slug) === $draft, $type . ' scheduling remains private and unchanged before approval');
        $scheduledRecord = $service->proposal($scheduledProposal['id']);
        checkProposal(($scheduledRecord['after']['workflow_note'] ?? null) === 'Reviewed launch window', $type . ' proposal retains its normalized workflow note for review');
        checkProposal(str_contains($controller->show($scheduledProposal['id'])->content(), 'Reviewed launch window'), $type . ' approval preview includes the note');
        $notePath = $config->paths()->dataPath('integrations/proposals/' . $scheduledProposal['id'] . '.json');
        $changedNote = $scheduledRecord;
        $changedNote['after']['workflow_note'] = 'Different note';
        $files->writeJson($notePath, $changedNote);
        failsProposal(fn () => $service->reviewProposal($scheduledProposal['id'], $scheduledRecord['approval_hash'], 'owner', true), 'proposal_invalid');
        $files->writeJson($notePath, $scheduledRecord);
        $service->reviewProposal($scheduledProposal['id'], $scheduledRecord['approval_hash'], 'owner', true);
        $scheduled = $repository->findBySlug($slug);
        checkProposal($scheduled['seo_title'] === 'Launch search title' && $scheduled['seo_description'] === 'Reviewed search description', $type . ' approval preserves reviewed search metadata');
        if ($type === 'page') {
            checkProposal(array_column($scheduled['blocks'], 'type') === ['html', 'posts', 'gallery', 'products', 'widget'] && $scheduled['blocks'][0]['body'] === '<p>Lifecycle body</p>', 'all supported block types retain order and sanitize HTML through approval');
            checkProposal($scheduled['blocks'][1]['show_image'] === false && $scheduled['blocks'][1]['show_date'] === true && $scheduled['blocks'][1]['show_read_more'] === true && $scheduled['blocks'][1]['limit'] === 4, 'approved post blocks retain display controls');
            checkProposal($scheduled['show_latest_posts'] === true && $scheduled['latest_posts_limit'] === 4, 'approved page retains latest-post settings');
        } else {
            checkProposal($scheduled['tags'] === ['Launch', 'Product'] && $scheduled['category'] === 'Product News' && $scheduled['subtitle'] === 'Launch summary', 'approved post preserves taxonomy and subtitle');
            checkProposal($scheduled['featured_image'] === '/assets/team.png' && $scheduled['featured_image_alt'] === 'Launch team' && $scheduled['layout'] === 'sidebar-right', 'approved post preserves safe image and layout metadata');
            checkProposal($scheduled['post_type'] === 'news' && $posts->publicPath($scheduled) === '/news/' . $slug, 'approved custom post type selects the reviewed archive route');
        }
        $history = $scheduled['workflow_history'];
        $lastHistory = end($history);
        checkProposal($lastHistory['note'] === 'Reviewed launch window' && $lastHistory['actor'] === 'owner', $type . ' approval appends the reviewed note attributed to the actual approver');
        checkProposal($scheduled['status'] === 'scheduled' && strtotime($scheduled['publish_at']) === $publishTime && strtotime($scheduled['unpublish_at']) === $endTime, $type . ' approval retains exact publication boundaries');
        checkProposal(!PublicationState::isPublic($scheduled, $publishTime - 1) && PublicationState::isPublic($scheduled, $publishTime) && !PublicationState::isPublic($scheduled, $endTime), $type . ' approved schedule activates and expires at the exact boundaries');
        failsProposal(fn () => $service->reviewProposal($scheduledProposal['id'], $scheduledRecord['approval_hash'], 'owner', true), 'proposal_processed');
        checkProposal($repository->findBySlug($slug)['workflow_history'] === $history, $type . ' approval replay does not duplicate workflow history');

        $archiveProposal = $service->propose($type, $slug, [], ContentRevision::for($scheduled), $access, 'archive-valid-' . $type, 'unpublish');
        checkProposal($repository->findBySlug($slug) === $scheduled, $type . ' pending unpublish does not change the approved schedule');
        $archiveRecord = $service->proposal($archiveProposal['id']);
        $service->reviewProposal($archiveProposal['id'], $archiveRecord['approval_hash'], 'owner', true);
        $archived = $repository->findBySlug($slug);
        checkProposal($archived['status'] === 'archived' && !PublicationState::isPublic($archived, $publishTime) && $archived['body'] === $scheduled['body'], $type . ' unpublish archives without deleting content');

        // Publishing retains dates unless explicitly changed; remove the old
        // visibility window as part of the exact revision being reviewed.
        $republish = $service->propose($type, $slug, ['publish_at' => date(DATE_ATOM, time() - 60), 'unpublish_at' => ''], ContentRevision::for($archived), $access, 'republish-valid-' . $type, 'publish');
        checkProposal($repository->findBySlug($slug) === $archived, $type . ' reversal also waits for approval');
        $republishRecord = $service->proposal($republish['id']);
        $service->reviewProposal($republish['id'], $republishRecord['approval_hash'], 'owner', true);
        $republished = $repository->findBySlug($slug);
        checkProposal($republished['status'] === 'published' && PublicationState::isPublic($republished) && $republished['body'] === $scheduled['body'] && $republished['id'] === $draft['id'], $type . ' approved republish reverses archival while preserving content identity');

        $childSlug = 'child-' . $type;
        $repository->save(['title' => 'Child ' . $type, 'slug' => $childSlug, 'parent_slug' => $slug, 'status' => 'published', 'body' => '<p>Child</p>'], 'owner');
        $childBefore = $repository->findBySlug($childSlug);
        $childPath = $repository->publicPath($childBefore);
        failsProposal(fn () => $service->propose($type, $slug, ['parent_slug' => $childSlug], ContentRevision::for($republished), $access, 'cycle-denied-' . $type), 'validation_failed');
        $renamedSlug = 'renamed-' . $type;
        $rename = $service->propose($type, $slug, ['slug' => $renamedSlug], ContentRevision::for($republished), $access, 'rename-parent-' . $type);
        $renameRecord = $service->proposal($rename['id']);
        checkProposal($renameRecord['before_url'] !== $renameRecord['after_url'] && $repository->publicPath($repository->findBySlug($childSlug)) === $childPath, $type . ' route change is previewed without moving the child before approval');
        checkProposal($renameRecord['affected_routes'] === [['id' => $childBefore['id'], 'before_url' => $childPath, 'after_url' => str_replace('/' . $slug . '/', '/' . $renamedSlug . '/', $childPath)]], $type . ' proposal binds the exact affected child route');
        checkProposal(str_contains($controller->show($rename['id'])->content(), 'Affected child URLs') && str_contains($controller->show($rename['id'])->content(), $childPath), $type . ' review shows the affected child route');
        $grandchildSlug = 'grandchild-' . $type;
        $repository->save(['title' => 'Grandchild', 'slug' => $grandchildSlug, 'parent_slug' => $childSlug, 'status' => 'draft', 'body' => '<p>Grandchild</p>'], 'owner');
        failsProposal(fn () => $service->reviewProposal($rename['id'], $renameRecord['approval_hash'], 'owner', true), 'revision_conflict');
        checkProposal($repository->findBySlug($slug)['id'] === $republished['id'], $type . ' a new descendant forces review of a fresh route impact list');
        $rename = $service->propose($type, $slug, ['slug' => $renamedSlug], ContentRevision::for($republished), $access, 'rename-parent-refreshed-' . $type);
        $renameRecord = $service->proposal($rename['id']);
        checkProposal(count($renameRecord['affected_routes']) === 2, $type . ' refreshed route impact includes all descendant depths and draft routes');
        $service->reviewProposal($rename['id'], $renameRecord['approval_hash'], 'owner', true);
        $renamed = $repository->findBySlug($renamedSlug);
        $childAfter = $repository->findBySlug($childSlug);
        checkProposal($repository->findBySlug($slug) === null && $renamed['id'] === $republished['id'] && $childAfter['parent_slug'] === $renamedSlug && $childAfter['id'] === $childBefore['id'], $type . ' approved parent rename updates references and preserves both identities');
        checkProposal($repository->publicPath($childAfter) === str_replace('/' . $slug . '/', '/' . $renamedSlug . '/', $childPath), $type . ' child route follows the approved parent rename');
        checkProposal($repository->publicPath($repository->findBySlug($grandchildSlug)) === $repository->publicPath($childAfter) . '/' . $grandchildSlug, $type . ' deeper descendant route follows the approved rename');
    }

    $current = $pages->findBySlug('about');
    $revoked = $service->propose('page', 'about', [], ContentRevision::for($current), $access, 'proposal-revoked-1', 'publish');
    $revokedRecord = $service->proposal($revoked['id']);
    $pages->save(['title' => 'Retired fixture', 'slug' => 'retired-fixture', 'body' => '<p>Preserve me</p>', 'status' => 'draft', 'reviewer' => 'owner'], 'owner');
    $retired = $pages->findBySlug('retired-fixture');
    $obsolete = $service->propose('page', 'retired-fixture', ['title' => 'Obsolete change'], ContentRevision::for($retired), $access, 'proposal-retired-1');
    $obsoleteRecord = $service->proposal($obsolete['id']);
    failsProposal(fn () => $service->reviewProposal($obsolete['id'], $obsoleteRecord['approval_hash'], 'editor', false), 'reviewer_required');
    $retiredBody = $files->read($config->paths()->contentPath('pages/retired-fixture/body.html'));
    rename($config->paths()->contentPath('pages/retired-fixture'), $root . '/retired-fixture');
    failsProposal(fn () => $service->reviewProposal($obsolete['id'], $obsoleteRecord['approval_hash'], 'editor', false), 'not_found');
    $service->reviewProposal($obsolete['id'], $obsoleteRecord['approval_hash'], 'owner', false);
    checkProposal($service->proposal($obsolete['id'])['state'] === 'rejected' && $files->read($root . '/retired-fixture/body.html') === $retiredBody, 'owner rejection of a missing target does not restore or mutate content');
    $expired = $service->propose('page', 'about', [], ContentRevision::for($current), array_replace($access, ['expires_at' => '2000-01-01T00:00:00+00:00']), 'proposal-expired-1', 'publish');
    $expiredRecord = $service->proposal($expired['id']);
    failsProposal(fn () => $service->reviewProposal($expired['id'], $expiredRecord['approval_hash'], 'owner', true), 'proposal_expired');
    $expiredScreen = $controller->show($expired['id'])->content();
    checkProposal(str_contains($expiredScreen, 'value="reject"') && !str_contains($expiredScreen, 'value="approve"'), 'expired editorial proposals retain a reject-only review form');
    $service->reviewProposal($expired['id'], $expiredRecord['approval_hash'], 'owner', false);
    checkProposal($service->proposal($expired['id'])['state'] === 'rejected' && $pages->findBySlug('about') === $current, 'rejecting an expired proposal leaves content unchanged');
    $tokens->revoke($access['id']);
    failsProposal(fn () => $service->reviewProposal($revoked['id'], $revokedRecord['approval_hash'], 'owner', true), 'connection_revoked');
    checkProposal($pages->findBySlug('about')['status'] === 'archived', 'revoked connections cannot publish pending proposals');
    failsProposal(fn () => $service->reviewProposal($revoked['id'], 'wrong', 'owner', false), 'approval_mismatch');
    failsProposal(fn () => $service->reviewProposal($revoked['id'], $revokedRecord['approval_hash'], 'viewer', false), 'forbidden');
    $service->reviewProposal($revoked['id'], $revokedRecord['approval_hash'], 'owner', false);
    checkProposal($service->proposal($revoked['id'])['state'] === 'rejected' && $pages->findBySlug('about') === $current, 'owners may close revoked proposals without changing content or reviving access');
    failsProposal(fn () => $service->reviewProposal($revoked['id'], $revokedRecord['approval_hash'], 'owner', false), 'proposal_processed');
    failsProposal(fn () => $service->createDraft('page', ['title' => 'Unsafe', 'custom_js' => 'alert(1)'], 'token:x', 'unsafe-content-1'), 'validation_failed');
    checkProposal(!str_contains($files->read($config->paths()->dataPath('log/audit.jsonl')), $issued['token']), 'proposal audit never contains credentials');
    echo "Content proposal checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}

function checkProposal(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function failsProposal(callable $fn, string $code): void {
    try { $fn(); } catch (ContentMutationException $error) {
        checkProposal($error->errorCode() === $code, 'Expected ' . $code . ', received ' . $error->errorCode());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $code);
}
