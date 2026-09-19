<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Content\ContentTransaction;
use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Content\WidgetRepository;
use Batoi\Press\Content\WebsiteDocumentStore;
use Batoi\Press\Content\PublicSettingsRepository;
use Batoi\Press\Content\MediaRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\MachineAccessPolicy;
use RuntimeException;

final class ContentMutationService
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly AuditLog $audit,
        private readonly IdempotencyStore $idempotency
    ) {
    }

    public function createDraft(string $type, array $input, string $actor, string $idempotencyKey, string $requestId = ''): array
    {
        $type = $this->type($type);
        $this->machineInput($type, $input);
        $clean = $this->validated($type, $input, null);
        $clean['status'] = 'draft';
        unset($clean['published_at']);
        return $this->idempotency->run($actor, $type . '.create_draft', $idempotencyKey, $clean, function () use ($type, $clean, $actor, $requestId): array {
            return $this->locked($type, function () use ($type, $clean, $actor, $requestId): array {
                $slug = (string)($clean['slug'] ?? '');
                if ($slug !== '' && $this->repository($type)->findBySlug($slug) !== null) {
                    throw new ContentMutationException(ucfirst($type) . ' slug already exists.', 'slug_conflict', 409);
                }
                $saved = $this->repository($type)->save($clean, $actor);
                $record = $this->find($type, (string)$saved['slug']);
                $result = $this->result($type, 'created', null, $record);
                $this->record($actor, $type . '.draft_created', $record, $requestId, $result['change_summary']);
                return $result;
            });
        });
    }

    public function updateDraft(string $type, string $identifier, array $input, string $expectedRevision, string $actor, string $requestId = ''): array
    {
        $type = $this->type($type);
        $this->machineInput($type, $input);
        return $this->locked($type, function () use ($type, $identifier, $input, $expectedRevision, $actor, $requestId): array {
            $existing = $this->find($type, $identifier);
            if (($existing['status'] ?? '') !== 'draft') {
                throw new ContentMutationException('Only draft content can be updated by this operation.', 'not_a_draft', 409);
            }
            $this->assertRevision($existing, $expectedRevision);
            $merged = $this->validated($type, $input, $existing);
            $merged['original_slug'] = (string)$existing['slug'];
            $merged['status'] = 'draft';
            $saved = $this->repository($type)->save($merged, $actor);
            $record = $this->find($type, (string)$saved['slug']);
            $result = $this->result($type, 'updated', $existing, $record);
            $this->record($actor, $type . '.draft_updated', $record, $requestId, $result['change_summary']);
            return $result;
        });
    }

    public function publish(string $type, string $identifier, string $expectedRevision, string $actor, string $idempotencyKey, string $requestId = ''): array
    {
        $type = $this->type($type);
        $operationInput = ['identifier' => $identifier, 'expected_revision' => $expectedRevision];
        return $this->idempotency->run($actor, $type . '.publish', $idempotencyKey, $operationInput, function () use ($type, $identifier, $expectedRevision, $actor, $requestId): array {
            return $this->locked($type, function () use ($type, $identifier, $expectedRevision, $actor, $requestId): array {
                $existing = $this->find($type, $identifier);
                $this->assertRevision($existing, $expectedRevision);
                $input = $this->repositoryInput($type, $existing);
                $input['original_slug'] = (string)$existing['slug'];
                $input['status'] = 'published';
                $saved = $this->repository($type)->save($input, $actor);
                $record = $this->find($type, (string)$saved['slug']);
                $result = $this->result($type, 'published', $existing, $record);
                $this->record($actor, $type . '.published', $record, $requestId, $result['change_summary']);
                return $result;
            });
        });
    }

    public function saveFromAdmin(string $type, array $input, string $expectedRevision, string $actor, string $requestId = ''): array
    {
        $type = $this->type($type);
        return $this->locked($type, function () use ($type, $input, $expectedRevision, $actor, $requestId): array {
            $identifier = trim((string)($input['original_slug'] ?? $input['slug'] ?? ''));
            $existing = null;
            if ($identifier !== '') {
                foreach ($this->repository($type)->all() as $candidate) {
                    if (($candidate['slug'] ?? '') === $identifier) {
                        $existing = $candidate;
                        break;
                    }
                }
            }
            if (trim((string)($input['original_slug'] ?? '')) !== '' && $existing === null) {
                throw new ContentMutationException(ucfirst($type) . ' not found.', 'not_found', 404);
            }
            if ($existing !== null) {
                $this->assertRevision($existing, $expectedRevision);
            }
            $clean = $this->validated($type, $input, $existing);
            if ($existing !== null) {
                $clean['original_slug'] = (string)$existing['slug'];
            }
            $clean['status'] = PublicationState::normalize($input['status'] ?? 'draft');
            $saved = $this->repository($type)->save($clean, $actor);
            $record = $this->find($type, (string)$saved['slug']);
            $result = $this->result($type, $existing === null ? 'created' : 'updated', $existing, $record);
            $this->record($actor, $type . '.admin_saved', $record, $requestId, $result['change_summary']);
            return $result;
        });
    }

    /** Prepare immutable changes; no public content is changed until a local review POST. */
    public function propose(string $type, string $identifier, array $changes, string $expectedRevision, array $access, string $key, string $action = 'retain', string $requestId = ''): array
    {
        $type = $this->type($type);
        $this->machineInput($type, $changes);
        if (!in_array($action, ['retain', 'publish', 'schedule', 'unpublish'], true)) {
            throw new ContentMutationException('Unsupported proposal action.', 'validation_failed', 422);
        }
        $required = $action === 'retain' ? ['content:write'] : ['content:publish'];
        if ($changes !== [] && !in_array('content:write', $required, true)) $required[] = 'content:write';
        $connection = (string)($access['id'] ?? '');
        $this->proposalAuthority($connection, (string)($access['principal'] ?? ''), $required);
        if (array_diff($required, (array)($access['scopes'] ?? [])) !== []) {
            throw new ContentMutationException('The connection lacks proposal permissions.', 'insufficient_scope', 403);
        }
        $actor = 'token:' . $connection;
        return $this->idempotency->run($actor, $type . '.propose', $key, compact('identifier', 'changes', 'expectedRevision', 'action'), function () use ($type, $identifier, $changes, $expectedRevision, $access, $required, $actor, $action, $connection, $requestId): array {
            return $this->locked($type, function () use ($type, $identifier, $changes, $expectedRevision, $access, $required, $actor, $action, $connection, $requestId): array {
                $existing = $this->find($type, $identifier);
                $this->assertRevision($existing, $expectedRevision);
                $input = $this->validated($type, $changes, $existing);
                $input['original_slug'] = (string)$existing['slug'];
                $input['status'] = match ($action) {
                    'publish' => 'published', 'schedule' => 'scheduled', 'unpublish' => 'archived',
                    default => (string)($existing['status'] ?? 'draft'),
                };
                try {
                    $prepared = $this->repository($type)->prepareSave($input, $actor);
                } catch (RuntimeException $error) {
                    throw new ContentMutationException($error->getMessage(), 'validation_failed', 422);
                }
                $after = $prepared['meta'] + ['body' => $prepared['body']];
                $beforeInput = $this->repositoryInput($type, $existing);
                $afterInput = $this->repositoryInput($type, $after);
                // Notes are commands appended to history, not persistent record
                // fields. Keep the reviewed command without copying old history.
                if (array_key_exists('workflow_note', $changes)) $afterInput['workflow_note'] = trim($changes['workflow_note']);
                $storage = new ContentTransaction($this->config->paths());
                $hierarchyRevision = $storage->hierarchyRevision($type);
                $proposal = [
                    'id' => 'proposal_' . bin2hex(random_bytes(16)), 'type' => $type,
                    'target_id' => (string)$existing['id'], 'action' => $action,
                    'site' => rtrim((string)($this->config->site()['base_url'] ?? ''), '/'),
                    'connection_id' => $connection, 'principal' => (string)$access['principal'],
                    'required_scopes' => $required, 'base_revision' => ContentRevision::for($existing),
                    'before' => $beforeInput, 'after' => $afterInput,
                    'before_url' => $this->repository($type)->publicPath($existing),
                    'after_url' => $this->repository($type)->publicPath($after),
                    'affected_routes' => $this->affectedRoutes($type, $existing, $after),
                    'created_at' => time(), 'expires_at' => min(time() + 86400, strtotime((string)($access['expires_at'] ?? '')) ?: time() + 86400),
                    'state' => 'pending', 'request_id' => $requestId,
                ];
                if ($hierarchyRevision !== $storage->hierarchyRevision($type)) throw new ContentMutationException('The hierarchy changed while preparing the proposal. Retry with the current content.', 'revision_conflict', 409);
                if ($proposal['before_url'] !== $proposal['after_url']) $proposal['hierarchy_revision'] = $hierarchyRevision;
                $proposal['approval_hash'] = $this->proposalHash($proposal);
                $this->writeProposal($proposal);
                $this->audit->record($actor, 'content.proposal.created', $proposal['id'], '', 'success', ['request_id' => $requestId, 'principal' => $proposal['principal'], 'target' => $proposal['target_id']]);
                return $this->proposalSummary($proposal);
            });
        });
    }

    public function proposeMenu(string $key, array $changes, int $expectedRevision, array $access, string $idempotencyKey, string $requestId = ''): array
    {
        $required = ['site:read', 'site:write'];
        $connection = (string)($access['id'] ?? '');
        $this->proposalAuthority($connection, (string)($access['principal'] ?? ''), $required);
        if (array_diff($required, (array)($access['scopes'] ?? [])) !== []) throw new ContentMutationException('Menu changes require site read and write permission.', 'insufficient_scope', 403);
        return $this->idempotency->run('token:' . $connection, 'menu.propose', $idempotencyKey, compact('key', 'changes', 'expectedRevision'), function () use ($key, $changes, $expectedRevision, $access, $connection, $required, $requestId): array {
            return $this->locked('menu', function () use ($key, $changes, $expectedRevision, $access, $connection, $required, $requestId): array {
                $prepared = $this->websiteChanges()->prepareMenu($key, $changes, $expectedRevision, 'token:' . $connection);
                $proposal = ['id' => 'proposal_' . bin2hex(random_bytes(16)), 'type' => 'menu', 'target_id' => $key, 'action' => 'update_navigation',
                    'site' => rtrim((string)($this->config->site()['base_url'] ?? ''), '/'), 'connection_id' => $connection, 'principal' => (string)$access['principal'],
                    'required_scopes' => $required, 'base_revision' => ContentRevision::for($prepared['before']),
                    'before' => $this->menuFields($prepared['before']), 'after' => $this->menuFields($prepared['after']), 'before_url' => '/', 'after_url' => '/',
                    'created_at' => time(), 'expires_at' => min(time() + 86400, strtotime((string)($access['expires_at'] ?? '')) ?: time() + 86400),
                    'state' => 'pending', 'request_id' => $requestId];
                $proposal['approval_hash'] = $this->proposalHash($proposal);
                $this->writeProposal($proposal);
                $this->audit->record('token:' . $connection, 'menu.proposal.created', $proposal['id'], '', 'success', ['principal' => $access['principal'], 'request_id' => $requestId]);
                return $this->proposalSummary($proposal);
            });
        });
    }

    public function proposeWidgets(array $widgets, string $expectedRevision, array $access, string $key, string $requestId = ''): array
    {
        return $this->proposeWebsiteDocument('widgets', $widgets, $expectedRevision, $access, $key, $requestId);
    }

    public function proposePublicSettings(array $changes, string $expectedRevision, array $access, string $key, string $requestId = ''): array
    {
        return $this->proposeWebsiteDocument('site', $changes, $expectedRevision, $access, $key, $requestId);
    }

    private function documentRepository(string $kind): WidgetRepository|PublicSettingsRepository
    {
        return match ($kind) { 'widgets' => new WidgetRepository($this->config->paths()), 'site' => new PublicSettingsRepository($this->config->paths()), default => throw new RuntimeException('Unsupported website document.') };
    }

    private function proposeWebsiteDocument(string $kind, array $input, string $expectedRevision, array $access, string $key, string $requestId): array
    {
        $required = ['site:read', 'site:write'];
        $connection = (string)($access['id'] ?? '');
        $this->proposalAuthority($connection, (string)($access['principal'] ?? ''), $required);
        if (array_diff($required, (array)($access['scopes'] ?? [])) !== []) throw new ContentMutationException('Website changes require site read and write permission.', 'insufficient_scope', 403);
        return $this->idempotency->run('token:' . $connection, $kind . '.propose', $key, compact('input', 'expectedRevision'), function () use ($kind, $input, $expectedRevision, $access, $connection, $required, $requestId): array {
            try { if ($kind === 'widgets') \Batoi\Press\Core\WidgetRenderer::normalizeList($input, true); }
            catch (RuntimeException $error) { throw new ContentMutationException($error->getMessage(), 'validation_failed', 422); }
            try { $prepared = $this->documentRepository($kind)->prepareSave($input, $expectedRevision); }
            catch (\InvalidArgumentException $error) { throw new ContentMutationException($error->getMessage(), 'validation_failed', 422); }
            catch (MenuConflictException $error) { throw new ContentMutationException('Website content changed. Read it again.', 'revision_conflict', 409); }
            catch (RuntimeException $error) { throw new ContentMutationException('Website storage is unavailable or requires recovery.', 'storage_unavailable', 503); }
            $proposal = ['id' => 'proposal_' . bin2hex(random_bytes(16)), 'type' => $kind, 'target_id' => $kind === 'widgets' ? 'sidebar' : 'public-settings', 'action' => 'update_' . $kind,
                'site' => rtrim((string)($this->config->site()['base_url'] ?? ''), '/'), 'connection_id' => $connection, 'principal' => (string)$access['principal'],
                'required_scopes' => $required, 'base_revision' => $prepared['base_revision'], 'before' => $prepared['before'], 'after' => $prepared['after'],
                'before_url' => '/', 'after_url' => '/', 'created_at' => time(), 'expires_at' => min(time() + 86400, strtotime((string)($access['expires_at'] ?? '')) ?: time() + 86400), 'state' => 'pending', 'request_id' => $requestId];
            $proposal['approval_hash'] = $this->proposalHash($proposal);
            $this->writeProposal($proposal);
            $this->audit->record('token:' . $connection, $kind . '.proposal.created', $proposal['id'], '', 'success', ['principal' => $access['principal'], 'request_id' => $requestId]);
            return $this->proposalSummary($proposal);
        });
    }

    public function menuProposalSummary(string $id, array $access, string $type = 'menu'): array
    {
        $proposal = $this->proposal($id, $access);
        if ($proposal['type'] !== $type) throw new ContentMutationException('Website proposal not found.', 'not_found', 404);
        return $this->proposalSummary($proposal);
    }

    public function mediaRepository(): MediaRepository
    {
        return new MediaRepository($this->config->paths(), new FileStore(), null, (array)($this->config->security()['uploads']['machine_limits'] ?? []));
    }

    public function proposeMediaUpload(array $input, array $access, string $key, string $requestId = ''): array
    {
        return $this->proposeMedia('upload', '', $input, '', $access, $key, $requestId);
    }

    public function proposeMediaMetadata(string $id, array $changes, string $revision, array $access, string $key, string $requestId = ''): array
    {
        return $this->proposeMedia('metadata', $id, $changes, $revision, $access, $key, $requestId);
    }

    private function proposeMedia(string $action, string $target, array $input, string $revision, array $access, string $key, string $requestId): array
    {
        $readScope = in_array('media:read', (array)($access['scopes'] ?? []), true) ? 'media:read' : 'content:read';
        $required = [$readScope, 'media:write'];
        $connection = (string)($access['id'] ?? '');
        if (array_diff($required, (array)($access['scopes'] ?? [])) !== []) throw new ContentMutationException('Media changes require media write and media or content read permission.', 'insufficient_scope', 403);
        $this->proposalAuthority($connection, (string)($access['principal'] ?? ''), $required);
        return $this->idempotency->run('token:' . $connection, 'media.' . $action, $key, compact('target', 'input', 'revision'), function () use ($action, $target, $input, $revision, $access, $key, $requestId, $required, $connection): array {
            return $this->locked('media', function () use ($action, $target, $input, $revision, $access, $key, $requestId, $required, $connection): array {
                $id = 'proposal_' . substr(hash('sha256', $connection . '|media.' . $action . '|' . $key), 0, 32);
                $requestHash = ContentRevision::for(compact('target', 'input', 'revision'));
                if (is_file($this->proposalPath($id))) {
                    $existing = $this->proposal($id, $access);
                    if (($existing['request_hash'] ?? '') !== $requestHash) throw new ContentMutationException('This idempotency key was used for different media input.', 'idempotency_conflict', 409);
                    return $this->proposalSummary($existing);
                }
                try {
                    $repository = $this->mediaRepository();
                    if ($action === 'upload') {
                        $after = $repository->stage($id, $input, $connection, (array)($this->config->security()['uploads'] ?? []));
                        $before = [];
                        $target = $after['id'];
                        $base = ContentRevision::for([]);
                        $url = $after['url'];
                    } else {
                        $prepared = $repository->prepareMetadata($target, $input, $revision);
                        $before = $prepared['before']; $after = $prepared['after']; $base = $prepared['revision']; $url = $prepared['asset']['url'];
                    }
                } catch (\InvalidArgumentException $error) { throw new ContentMutationException($error->getMessage(), 'validation_failed', 422); }
                catch (MenuConflictException $error) { throw new ContentMutationException($error->getMessage(), 'revision_conflict', 409); }
                catch (RuntimeException $error) { throw new ContentMutationException('Media storage is unavailable or requires recovery.', 'storage_unavailable', 503); }
                $proposal = ['id' => $id, 'type' => 'media', 'target_id' => $target, 'action' => $action,
                    'site' => rtrim((string)($this->config->site()['base_url'] ?? ''), '/'), 'connection_id' => $connection, 'principal' => (string)$access['principal'],
                    'required_scopes' => $required, 'base_revision' => $base, 'before' => $before, 'after' => $after,
                    'before_url' => $action === 'upload' ? '' : $url, 'after_url' => $url,
                    'created_at' => time(), 'expires_at' => min(time() + 86400, strtotime((string)($access['expires_at'] ?? '')) ?: time() + 86400), 'state' => 'pending', 'request_id' => $requestId, 'request_hash' => $requestHash];
                $proposal['approval_hash'] = $this->proposalHash($proposal);
                $this->writeProposal($proposal);
                $this->audit->record('token:' . $connection, 'media.proposal.created', $id, '', 'success', ['principal' => $access['principal'], 'request_id' => $requestId]);
                return $this->proposalSummary($proposal);
            });
        });
    }

    private function reviewMediaProposal(string $id, string $hash, string $reviewer, bool $approve, bool $reconcile = false): array
    {
        return $this->locked('media', function () use ($id, $hash, $reviewer, $approve, $reconcile): array {
            $this->websiteReviewer($reviewer);
            $proposal = $this->proposal($id);
            if (!hash_equals($proposal['approval_hash'], $hash)) throw new ContentMutationException('Review the current media proposal.', 'approval_mismatch', 409);
            if ($proposal['state'] !== ($reconcile ? 'applying' : 'pending')) throw new ContentMutationException('This media proposal cannot be processed again.', 'proposal_processed', 409);
            $repository = $this->mediaRepository();
            if ($reconcile) {
                try {
                    $receipt = $repository->receipt($id);
                    if ($receipt === null) {
                        if ($proposal['action'] === 'upload') {
                            $path = $this->config->paths()->contentPath('assets/' . $proposal['after']['relative']);
                            if (file_exists($path) || is_link($path)) throw new RuntimeException('Unexpected media exists without a receipt.');
                        } elseif ($repository->load($proposal['target_id'])['revision'] !== $proposal['base_revision']) throw new RuntimeException('Media changed without a receipt.');
                    }
                } catch (RuntimeException|\InvalidArgumentException $error) { throw new ContentMutationException('Media recovery requires operator review; external edits were not overwritten.', 'recovery_required', 409); }
                if ($receipt !== null && !in_array($receipt['state'] ?? '', ['committed', 'not_applied'], true)) throw new ContentMutationException('Media recovery is not complete.', 'recovery_required', 409);
                $proposal['state'] = ($receipt['state'] ?? '') === 'committed' ? 'applied' : 'failed';
                if ($proposal['state'] === 'applied') $proposal['result'] = ['ok' => true, 'action' => 'proposal_applied', 'resource' => $receipt['result'], 'recovered_receipt' => true];
                $proposal['reconciled_by'] = $reviewer;
                $this->writeProposal($proposal);
                if ($proposal['action'] === 'upload') $repository->releaseStage($id);
                $this->audit->record($reviewer, 'media.proposal.reconciled', $id, '', 'success', ['state' => $proposal['state']]);
                return $this->proposalSummary($proposal);
            }
            if (!$approve) {
                $proposal['state'] = 'rejected'; $proposal['reviewed_by'] = $reviewer;
                $this->writeProposal($proposal);
                if ($proposal['action'] === 'upload') $repository->releaseStage($id);
                $this->audit->record($reviewer, 'media.proposal.rejected', $id);
                return $this->proposalSummary($proposal);
            }
            if ($proposal['expires_at'] <= time()) throw new ContentMutationException('This media proposal expired.', 'proposal_expired', 409);
            $this->proposalAuthority($proposal['connection_id'], $proposal['principal'], $proposal['required_scopes']);
            try {
                if ($proposal['action'] === 'upload') $repository->stagedFile($id, $proposal['after'], (array)($this->config->security()['uploads'] ?? []));
                else {
                    $prepared = $repository->prepareMetadata($proposal['target_id'], $proposal['after'], $proposal['base_revision']);
                    if ($prepared['after'] !== $proposal['after']) throw new MenuConflictException('Media normalization changed.');
                }
            } catch (\InvalidArgumentException $error) { throw new ContentMutationException($error->getMessage(), 'validation_failed', 422); }
            catch (MenuConflictException $error) { throw new ContentMutationException('Media changed; prepare a new proposal.', 'revision_conflict', 409); }
            catch (RuntimeException $error) { throw new ContentMutationException('Staged media is unavailable or has changed.', 'storage_unavailable', 503); }
            $proposal['state'] = 'applying'; $proposal['reviewed_by'] = $reviewer; $proposal['reviewed_at'] = time();
            $this->writeProposal($proposal);
            try {
                $result = $proposal['action'] === 'upload'
                    ? $repository->applyUpload($id, $proposal['after'], (array)($this->config->security()['uploads'] ?? []))
                    : $repository->applyMetadata($id, $proposal['target_id'], $proposal['after'], $proposal['base_revision']);
                $proposal['state'] = 'applied'; $proposal['result'] = ['ok' => true, 'action' => 'proposal_applied', 'resource' => $result];
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, 'media.proposal.applied', $id, '', 'success', ['connection_id' => $proposal['connection_id'], 'principal' => $proposal['principal'], 'revision' => $result['revision']]);
            } catch (MenuConflictException|\InvalidArgumentException $error) {
                $proposal['state'] = 'failed'; $this->writeProposal($proposal);
                if ($proposal['action'] === 'upload') $repository->releaseStage($id);
                $this->audit->record($reviewer, 'media.proposal.failed', $id, '', 'failed');
                throw new ContentMutationException('Media changed or its storage quota was reached; prepare a new proposal after resolving it.', 'media_conflict', 409);
            } catch (\Throwable $error) {
                $this->audit->record($reviewer, 'media.proposal.recovery_required', $id, '', 'failed');
                throw new ContentMutationException('Media application did not finish. Reconcile its receipt before retrying.', 'recovery_required', 500);
            }
            return $this->proposalSummary($proposal);
        });
    }

    private function menus(): MenuRepository { return new MenuRepository($this->config->paths(), new FileStore()); }
    private function websiteChanges(): WebsiteChangeService { return new WebsiteChangeService($this->menus(), $this->pages, $this->posts, $this->config); }
    private function menuFields(array $menu): array { return array_intersect_key($menu, array_flip(['name', 'location', 'items'])); }

    private function websiteReviewer(string $reviewer): void
    {
        $users = (new FileStore())->readJson($this->config->paths()->configPath('users.json'));
        $matches = array_values(array_filter((array)($users['users'] ?? []), static fn ($user): bool => is_array($user) && ($user['username'] ?? '') === $reviewer));
        $user = count($matches) === 1 ? $matches[0] : [];
        if (!in_array(AdminAccess::role($user), ['owner', 'admin'], true) || !empty($user['disabled_at']) || ($user['status'] ?? '') === 'disabled') {
            throw new ContentMutationException('An active installation administrator must review website changes.', 'forbidden', 403);
        }
    }

    private function reviewWebsiteProposal(string $id, string $hash, string $reviewer, bool $approve, bool $reconcile = false): array
    {
        return $this->locked('website', function () use ($id, $hash, $reviewer, $approve, $reconcile): array {
            $proposal = $this->proposal($id);
            $isMenu = $proposal['type'] === 'menu';
            $kind = $proposal['type'];
            $this->websiteReviewer($reviewer);
            if (!hash_equals($proposal['approval_hash'], $hash)) throw new ContentMutationException('Review the current proposal.', 'approval_mismatch', 409);
            if ($proposal['state'] !== ($reconcile ? 'applying' : 'pending')) throw new ContentMutationException('This proposal cannot be processed again.', 'proposal_processed', 409);
            $menus = $this->menus();
            $documentRepository = $isMenu ? null : $this->documentRepository($kind);
            if ($reconcile) {
                try { $receipt = $isMenu ? $menus->operationReceipt($proposal['target_id'], $id) : (new WebsiteDocumentStore($this->config->paths()))->receipt($kind, $id); }
                catch (RuntimeException $error) { throw new ContentMutationException('Website recovery requires operator review; no external changes were overwritten.', 'recovery_required', 409); }
                if ($receipt === null) {
                    if ($isMenu) $this->assertRevision($menus->load($proposal['target_id']), $proposal['base_revision']);
                    elseif (!hash_equals($proposal['base_revision'], $documentRepository->load()['revision'])) throw new ContentMutationException('Website content changed after the interrupted approval.', 'revision_conflict', 409);
                    $proposal['state'] = 'failed';
                } else {
                    if (!in_array($receipt['state'] ?? '', ['committed', 'not_applied'], true)) throw new ContentMutationException('Website recovery is not complete.', 'recovery_required', 409);
                    $proposal['state'] = $receipt['state'] === 'committed' ? 'applied' : 'failed';
                    if ($proposal['state'] === 'applied') $proposal['result'] = ['ok' => true, 'action' => 'proposal_applied', 'resource' => $receipt['result'], 'recovered_receipt' => true];
                }
                $proposal['reconciled_by'] = $reviewer;
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, $kind . '.proposal.reconciled', $id, '', 'success', ['state' => $proposal['state']]);
                return $this->proposalSummary($proposal);
            }
            if ($proposal['expires_at'] <= time()) throw new ContentMutationException('This proposal has expired.', 'proposal_expired', 409);
            $this->proposalAuthority($proposal['connection_id'], $proposal['principal'], $proposal['required_scopes']);
            if (!$approve) {
                $proposal['state'] = 'rejected';
                $proposal['reviewed_by'] = $reviewer;
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, $kind . '.proposal.rejected', $id);
                return $this->proposalSummary($proposal);
            }
            $current = $isMenu ? $menus->load($proposal['target_id']) : $documentRepository->load();
            if ($isMenu) $this->assertRevision($current, $proposal['base_revision']);
            elseif (!hash_equals($proposal['base_revision'], $current['revision'])) throw new ContentMutationException('Website content changed. Prepare a new proposal.', 'revision_conflict', 409);
            $documentInput = $kind === 'widgets' ? $proposal['after']['widgets'] : $proposal['after'];
            try { $prepared = $isMenu ? $this->websiteChanges()->prepareMenu($proposal['target_id'], $proposal['after'], (int)$current['revision'], $reviewer) : $documentRepository->prepareSave($documentInput, $proposal['base_revision']); }
            catch (\InvalidArgumentException $error) { throw new ContentMutationException($error->getMessage(), 'validation_failed', 422); }
            catch (MenuConflictException $error) { throw new ContentMutationException('Website content changed. Prepare a new proposal.', 'revision_conflict', 409); }
            catch (RuntimeException $error) { if ($error instanceof ContentMutationException) throw $error; throw new ContentMutationException('Website validation failed. Prepare a new proposal.', 'validation_failed', 422); }
            if (($isMenu ? $this->menuFields($prepared['after']) : $prepared['after']) !== $proposal['after']) throw new ContentMutationException('Website normalization changed; review a new proposal.', 'proposal_changed', 409);
            $proposal['state'] = 'applying';
            $proposal['reviewed_by'] = $reviewer;
            $proposal['reviewed_at'] = time();
            $this->writeProposal($proposal);
            try {
                $after = $isMenu ? $menus->save($proposal['after'], $reviewer, (int)$current['revision'], $proposal['target_id'], $id, $proposal['base_revision']) : $documentRepository->save($documentInput, $proposal['base_revision'], $id);
                $proposal['state'] = 'applied';
                $proposal['result'] = ['ok' => true, 'action' => 'proposal_applied', 'resource' => $isMenu ? ['type' => 'menu', 'key' => $proposal['target_id'], 'id' => $after['id'], 'revision' => $after['revision']] : $after];
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, $kind . '.proposal.applied', $id, '', 'success', ['connection_id' => $proposal['connection_id'], 'principal' => $proposal['principal'], 'revision' => $isMenu ? ContentRevision::for($after) : $after['revision']]);
            } catch (MenuConflictException $error) {
                $proposal['state'] = 'failed';
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, $kind . '.proposal.conflicted', $id, '', 'failed');
                throw new ContentMutationException('The website content changed during approval. Prepare a new proposal.', 'revision_conflict', 409);
            } catch (\Throwable $error) {
                $this->audit->record($reviewer, $kind . '.proposal.recovery_required', $id, '', 'failed');
                throw new ContentMutationException('Website application did not complete. Reconcile the approval receipt before retrying.', 'recovery_required', 500);
            }
            return $this->proposalSummary($proposal);
        });
    }

    public function proposal(string $id, ?array $access = null): array
    {
        $proposal = (new FileStore())->readJson($this->proposalPath($id, true));
        if (!hash_equals((string)($proposal['approval_hash'] ?? ''), $this->proposalHash($proposal))
            || ($proposal['site'] ?? '') !== rtrim((string)($this->config->site()['base_url'] ?? ''), '/')) {
            throw new ContentMutationException('Proposal integrity or installation mismatch.', 'proposal_invalid', 409);
        }
        if ($access !== null && ($proposal['connection_id'] !== ($access['id'] ?? '') || $proposal['principal'] !== ($access['principal'] ?? ''))) {
            throw new ContentMutationException('Proposal not found for this connection.', 'not_found', 404);
        }
        return $proposal;
    }

    /** Restore editorial values through a new review, never rewind history or execute old code. */
    public function proposeRestoration(string $id, string $expectedRevision, array $access, string $key, string $requestId = ''): array
    {
        if (array_diff(['content:read', 'content:write'], (array)($access['scopes'] ?? [])) !== []) {
            throw new ContentMutationException('Restoration requires content read and write permission.', 'insufficient_scope', 403);
        }
        $source = $this->proposal($id, $access);
        if (in_array($source['type'], ['menu', 'widgets', 'site', 'media'], true)) throw new ContentMutationException('Prepare a new website or media metadata proposal using the previous values to restore it.', 'unsupported_type', 422);
        if ($source['state'] !== 'applied') throw new ContentMutationException('Only an applied proposal provides a restoration point.', 'proposal_not_applied', 409);
        $changes = array_intersect_key($source['before'], array_flip($this->machineFields($source['type'])));
        unset($changes['publish_at'], $changes['unpublish_at'], $changes['published_at']);
        if ($source['type'] === 'post' && isset($changes['tags'])) $changes['tags'] = array_values(array_filter(array_map('trim', explode(',', (string)$changes['tags']))));
        // The visibility state, reviewer and executable assets remain current. A separate lifecycle
        // proposal is required to republish or unpublish; historical credentials/authority never return.
        $result = $this->propose($source['type'], $source['target_id'], $changes, $expectedRevision, $access, $key, 'retain', $requestId);
        $this->audit->record('token:' . $access['id'], 'content.proposal.restoration_requested', $result['id'], '', 'success', ['source_proposal' => $id, 'request_id' => $requestId]);
        return $result + ['restoration_source' => $id, 'restoration_policy' => 'Editorial values only; current visibility, reviewer and executable assets are preserved.'];
    }

    public function proposals(): array
    {
        $items = [];
        foreach (glob($this->config->paths()->dataPath('integrations/proposals/proposal_*.json')) ?: [] as $path) {
            $id = basename($path, '.json');
            try {
                $proposal = $this->proposal($id);
                $items[] = $this->proposalSummary($proposal);
            } catch (RuntimeException $error) {
                // Do not trust or display metadata from an unreadable, altered,
                // or different-installation record. Preserve it for recovery.
                $items[] = ['id' => $id, 'unavailable' => true, 'created_at' => 0];
            }
        }
        usort($items, static fn (array $a, array $b): int => (($b['unavailable'] ?? false) <=> ($a['unavailable'] ?? false)) ?: ($b['created_at'] <=> $a['created_at']));
        return array_slice($items, 0, 100);
    }

    /** Browser CSRF/step-up is checked by the controller; authority and revision are rechecked here. */
    public function reviewProposal(string $id, string $hash, string $reviewer, bool $approve): array
    {
        $initial = $this->proposal($id);
        if ($initial['type'] === 'media') return $this->reviewMediaProposal($id, $hash, $reviewer, $approve);
        if (in_array($initial['type'], ['menu', 'widgets', 'site'], true)) return $this->reviewWebsiteProposal($id, $hash, $reviewer, $approve);
        return $this->locked($this->type($initial['type']), function () use ($id, $hash, $reviewer, $approve): array {
            $proposal = $this->proposal($id);
            $users = (new FileStore())->readJson($this->config->paths()->configPath('users.json'));
            $matches = array_values(array_filter((array)($users['users'] ?? []), static fn ($user): bool => is_array($user) && ($user['username'] ?? '') === $reviewer));
            $user = count($matches) === 1 ? $matches[0] : [];
            if (!in_array(AdminAccess::role($user), ['owner', 'admin', 'editor'], true)
                || !empty($user['disabled_at']) || ($user['status'] ?? '') === 'disabled') {
                throw new ContentMutationException('An active publishing administrator must review this proposal.', 'forbidden', 403);
            }
            if (!hash_equals($proposal['approval_hash'], $hash)) throw new ContentMutationException('Review the current proposal before approving.', 'approval_mismatch', 409);
            if ($proposal['state'] !== 'pending') {
                throw new ContentMutationException('This proposal was already processed. Read its current status; it will not be applied twice.', 'proposal_processed', 409);
            }
            // Owners/admins may close obsolete requests even after their target
            // disappears. Editors must still satisfy the current assigned reviewer.
            $existing = ($approve || AdminAccess::role($user) === 'editor') ? $this->find($proposal['type'], $proposal['target_id']) : null;
            if (!empty($existing['reviewer']) && $existing['reviewer'] !== $reviewer && AdminAccess::role($user) === 'editor') {
                throw new ContentMutationException('The assigned reviewer or an installation administrator must approve.', 'reviewer_required', 403);
            }
            if (!$approve) {
                $proposal['state'] = 'rejected';
                $proposal['reviewed_by'] = $reviewer;
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, 'content.proposal.rejected', $id);
                return $this->proposalSummary($proposal);
            }
            if ($proposal['expires_at'] <= time()) throw new ContentMutationException('This proposal has expired. Prepare a new proposal.', 'proposal_expired', 409);
            $this->proposalAuthority($proposal['connection_id'], $proposal['principal'], $proposal['required_scopes']);
            $this->assertRevision($existing, $proposal['base_revision']);
            $input = $proposal['after'];
            $input['original_slug'] = $existing['slug'];
            // Repeat repository validation against current hierarchy and routing before any write.
            $prepared = $this->repository($proposal['type'])->prepareSave($input, $reviewer);
            $normalized = $this->repositoryInput($proposal['type'], $prepared['meta'] + ['body' => $prepared['body']]);
            if (array_key_exists('workflow_note', $input)) $normalized['workflow_note'] = trim($input['workflow_note']);
            if ($normalized !== $proposal['after']) throw new ContentMutationException('Normalization changed. Prepare and review a new proposal.', 'proposal_changed', 409);
            if ($this->repository($proposal['type'])->publicPath($existing) !== $proposal['before_url']
                || $this->repository($proposal['type'])->publicPath($prepared['meta']) !== $proposal['after_url']
                || $this->affectedRoutes($proposal['type'], $existing, $prepared['meta']) !== ($proposal['affected_routes'] ?? [])) {
                throw new ContentMutationException('The content hierarchy changed. Review a new proposal.', 'revision_conflict', 409);
            }
            if ($proposal['before_url'] !== $proposal['after_url']) {
                if (($proposal['hierarchy_revision'] ?? '') !== (new ContentTransaction($this->config->paths()))->hierarchyRevision($proposal['type'])) {
                    throw new ContentMutationException('The content hierarchy changed. Prepare a new proposal with current route impacts.', 'revision_conflict', 409);
                }
                $prepared['hierarchy_revision'] = $proposal['hierarchy_revision'];
            }
            $proposal['state'] = 'applying';
            $proposal['reviewed_by'] = $reviewer;
            $proposal['reviewed_at'] = time();
            $this->writeProposal($proposal); // Durable receipt: a crash cannot cause an automatic second application.
            try {
                $saved = (new ContentTransaction($this->config->paths()))->commit($proposal['type'], $prepared + ['operation_id' => $id]);
                $after = $this->find($proposal['type'], $saved['slug']);
                $proposal['result'] = $this->result($proposal['type'], 'proposal_applied', $existing, $after);
                $proposal['state'] = 'applied';
                $this->writeProposal($proposal);
                $this->audit->record($reviewer, 'content.proposal.applied', $id, '', 'success', ['connection_id' => $proposal['connection_id'], 'principal' => $proposal['principal'], 'revision' => ContentRevision::for($after)]);
            } catch (\Throwable $error) {
                // The storage journal rolls back partial writes; retain the approval receipt for reconciliation.
                $this->audit->record($reviewer, 'content.proposal.recovery_required', $id, '', 'failed');
                throw new ContentMutationException('Application did not complete. Use Reconcile receipt in Proposed changes; do not submit the proposal again.', 'recovery_required', 500);
            }
            return $this->proposalSummary($proposal);
        });
    }

    private function proposalAuthority(string $connection, string $principal, array $required): void
    {
        $current = (new MachineAccessPolicy($this->config->paths()))->connection($connection);
        if ($current === null || ($current['principal'] ?? '') !== $principal || array_diff($required, $current['scopes']) !== []) {
            throw new ContentMutationException('The originating connection no longer has permission.', 'connection_revoked', 403);
        }
    }

    public function reconcileProposal(string $id, string $hash, string $reviewer): array
    {
        $initial = $this->proposal($id);
        if ($initial['type'] === 'media') return $this->reviewMediaProposal($id, $hash, $reviewer, false, true);
        if (in_array($initial['type'], ['menu', 'widgets', 'site'], true)) return $this->reviewWebsiteProposal($id, $hash, $reviewer, false, true);
        return $this->locked($this->type($initial['type']), function () use ($id, $hash, $reviewer): array {
            $proposal = $this->proposal($id);
            $users = (new FileStore())->readJson($this->config->paths()->configPath('users.json'));
            $matches = array_values(array_filter((array)($users['users'] ?? []), static fn ($user): bool => is_array($user) && ($user['username'] ?? '') === $reviewer));
            $user = count($matches) === 1 ? $matches[0] : [];
            if (!in_array(AdminAccess::role($user), ['owner', 'admin'], true) || !empty($user['disabled_at']) || ($user['status'] ?? '') === 'disabled') {
                throw new ContentMutationException('An active installation administrator must reconcile recovery.', 'forbidden', 403);
            }
            if ($proposal['state'] !== 'applying' || !hash_equals($proposal['approval_hash'], $hash)) {
                throw new ContentMutationException('Only an interrupted approval receipt can be reconciled.', 'proposal_processed', 409);
            }
            try {
                $receipt = (new ContentTransaction($this->config->paths()))->receipt($proposal['type'], $id);
            } catch (RuntimeException $error) {
                throw new ContentMutationException('Recovery is blocked by unexpected storage state. Preserve the journal and obtain operator assistance; no external edits have been overwritten.', 'recovery_required', 409);
            }
            if ($receipt === null) {
                // No content write starts before its journal. Require the original content revision
                // as independent evidence before resolving a pre-journal interruption.
                $this->assertRevision($this->find($proposal['type'], $proposal['target_id']), $proposal['base_revision']);
                $proposal['state'] = 'failed';
            } elseif ($receipt['state'] === 'rolled_back') {
                $proposal['state'] = 'failed';
            } elseif ($receipt['state'] === 'committed') {
                $proposal['state'] = 'applied';
                $proposal['result'] = ['ok' => true, 'action' => 'proposal_applied', 'resource' => $receipt['result'], 'previous_revision' => $proposal['base_revision'], 'recovered_receipt' => true];
            } else {
                throw new ContentMutationException('Storage recovery is not complete.', 'recovery_required', 409);
            }
            $proposal['reconciled_by'] = $reviewer;
            $proposal['reconciled_at'] = time();
            $this->writeProposal($proposal);
            $this->audit->record($reviewer, 'content.proposal.reconciled', $id, '', 'success', ['state' => $proposal['state']]);
            return $this->proposalSummary($proposal);
        });
    }

    private function proposalHash(array $proposal): string
    {
        return ContentRevision::for(array_intersect_key($proposal, array_flip(['id', 'type', 'target_id', 'action', 'site', 'connection_id', 'principal', 'required_scopes', 'base_revision', 'before', 'after', 'before_url', 'after_url', 'affected_routes', 'hierarchy_revision', 'created_at', 'expires_at', 'request_hash'])));
    }

    /** Exact descendant route consequences, using one hierarchy scan. */
    private function affectedRoutes(string $type, array $before, array $after): array
    {
        $repository = $this->repository($type);
        $oldPath = $repository->publicPath($before);
        $newPath = $repository->publicPath($after);
        if ($oldPath === $newPath) return [];
        $children = [];
        foreach ($repository->all() as $record) $children[(string)($record['parent_slug'] ?? '')][] = $record;
        $queue = [[(string)$before['slug'], '']];
        $seen = [(string)$before['slug'] => true];
        $routes = [];
        for ($i = 0; $i < count($queue); $i++) {
            [$parent, $suffix] = $queue[$i];
            foreach ($children[$parent] ?? [] as $child) {
                $slug = (string)$child['slug'];
                if (isset($seen[$slug])) continue;
                $seen[$slug] = true;
                $childSuffix = $suffix . '/' . rawurlencode($slug);
                $routes[] = ['id' => (string)$child['id'], 'before_url' => $oldPath . $childSuffix, 'after_url' => $newPath . $childSuffix];
                $queue[] = [$slug, $childSuffix];
            }
        }
        usort($routes, static fn (array $a, array $b): int => strcmp($a['before_url'], $b['before_url']));
        return $routes;
    }

    private function proposalPath(string $id, bool $mustExist = false): string
    {
        if (preg_match('/^proposal_[a-f0-9]{32}$/D', $id) !== 1) throw new ContentMutationException('Invalid proposal identifier.', 'not_found', 404);
        $path = $this->config->paths()->dataPath('integrations/proposals/' . $id . '.json');
        if ($mustExist && !is_file($path)) throw new ContentMutationException('Proposal not found.', 'not_found', 404);
        return $path;
    }

    private function writeProposal(array $proposal): void
    {
        $path = $this->proposalPath($proposal['id']);
        (new FileStore())->writeJson($path, $proposal);
        @chmod($path, 0600);
    }

    public function proposalSummary(array $proposal): array
    {
        return array_intersect_key($proposal, array_flip(['id', 'type', 'target_id', 'action', 'site', 'principal', 'connection_id', 'base_revision', 'state', 'created_at', 'expires_at', 'before_url', 'after_url', 'result', 'reviewed_by'])) + [
            'review_url' => rtrim((string)($this->config->site()['base_url'] ?? ''), '/') . '/admin/proposals/' . $proposal['id'],
            'approval_required' => $proposal['state'] === 'pending',
            'live_content_changed' => $proposal['state'] === 'applied',
        ];
    }

    private function machineInput(string $type, array $input): void
    {
        $allowed = $this->machineFields($type);
        foreach ($input as $field => $value) {
            if (!in_array($field, $allowed, true)) throw new ContentMutationException('Unsupported machine-editable field: ' . $field, 'validation_failed', 422);
            if (in_array($field, ['blocks', 'tags'], true)) {
                if (!is_array($value) || !array_is_list($value) || count($value) > 30) throw new ContentMutationException('Invalid list field.', 'validation_failed', 422);
                if ($field === 'tags') foreach ($value as $tag) {
                    if (!is_string($tag) || strlen($tag) > 80) throw new ContentMutationException('Invalid tag.', 'validation_failed', 422);
                }
                if ($field === 'blocks') foreach ($value as $block) {
                    if (!is_array($block) || !in_array($block['type'] ?? '', ['html', 'posts', 'gallery', 'products', 'widget'], true)
                        || array_diff(array_keys($block), ['type', 'title', 'body', 'category', 'widget', 'limit', 'show_image', 'show_date', 'show_read_more']) !== []) {
                        throw new ContentMutationException('Invalid page block.', 'validation_failed', 422);
                    }
                    foreach ($block as $key => $part) {
                        $valid = match ($key) {
                            'limit' => is_int($part) && $part >= 1 && $part <= 24,
                            'show_image', 'show_date', 'show_read_more' => is_bool($part),
                            default => is_string($part),
                        };
                        if (!$valid) throw new ContentMutationException('Invalid page block field.', 'validation_failed', 422);
                    }
                }
            } elseif ($field === 'show_latest_posts') {
                if (!is_bool($value)) throw new ContentMutationException('show_latest_posts must be boolean.', 'validation_failed', 422);
            } elseif ($field === 'latest_posts_limit') {
                if (!is_int($value) || $value < 1 || $value > 12) throw new ContentMutationException('Invalid latest posts limit.', 'validation_failed', 422);
            } elseif (!is_string($value)) {
                throw new ContentMutationException('Invalid field value.', 'validation_failed', 422);
            }
        }
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 1048576) throw new ContentMutationException('Changes exceed 1 MiB.', 'validation_failed', 422);
    }

    private function machineFields(string $type): array
    {
        return array_merge(['title', 'slug', 'parent_slug', 'body', 'seo_title', 'seo_description', 'publish_at', 'unpublish_at', 'workflow_note'],
            $type === 'page' ? ['blocks', 'template', 'show_latest_posts', 'latest_posts_limit'] : ['subtitle', 'post_type', 'category', 'tags', 'featured_image', 'featured_image_alt', 'layout', 'published_at']);
    }

    private function validated(string $type, array $input, ?array $existing): array
    {
        $allowed = $type === 'page'
            ? ['title', 'slug', 'parent_slug', 'body', 'blocks', 'custom_css', 'custom_js', 'template', 'seo_title', 'seo_description', 'show_latest_posts', 'latest_posts_limit', 'publish_at', 'unpublish_at', 'reviewer', 'workflow_note']
            : ['title', 'subtitle', 'slug', 'parent_slug', 'post_type', 'body', 'category', 'tags', 'featured_image', 'featured_image_alt', 'layout', 'seo_title', 'seo_description', 'published_at', 'publish_at', 'unpublish_at', 'reviewer', 'workflow_note'];
        $base = $existing === null ? [] : $this->repositoryInput($type, $existing);
        if ($type === 'page' && array_key_exists('body', $input) && !array_key_exists('blocks', $input)) {
            unset($base['blocks']);
        }
        foreach ($input as $key => $value) {
            if (in_array((string)$key, $allowed, true)) {
                $base[(string)$key] = $value;
            }
        }
        $title = trim((string)($base['title'] ?? ''));
        $body = (string)($base['body'] ?? '');
        if ($title === '' || strlen($title) > 200) {
            throw new ContentMutationException('Title must contain between 1 and 200 bytes.', 'validation_failed', 422, ['field' => 'title']);
        }
        if (strlen($body) > 1048576) {
            throw new ContentMutationException('Body exceeds the 1 MiB content limit.', 'validation_failed', 422, ['field' => 'body']);
        }
        if ($type === 'page' && (strlen((string)($base['custom_css'] ?? '')) > 102400 || strlen((string)($base['custom_js'] ?? '')) > 102400)) {
            throw new ContentMutationException('Custom CSS and JavaScript are limited to 100 KiB each.', 'validation_failed', 422);
        }
        if (strlen((string)($base['seo_title'] ?? '')) > 200 || strlen((string)($base['seo_description'] ?? '')) > 500) {
            throw new ContentMutationException('Search metadata exceeds its allowed size.', 'validation_failed', 422);
        }
        if (strlen((string)($base['reviewer'] ?? '')) > 100 || strlen((string)($base['workflow_note'] ?? '')) > 500) {
            throw new ContentMutationException('Workflow reviewer or note exceeds its allowed size.', 'validation_failed', 422);
        }
        if ($type === 'post' && is_array($base['tags'] ?? null)) {
            $base['tags'] = implode(', ', array_slice(array_map('strval', $base['tags']), 0, 30));
        }
        return $base;
    }

    private function repositoryInput(string $type, array $record): array
    {
        $fields = $type === 'page'
            ? ['title', 'slug', 'parent_slug', 'body', 'blocks', 'custom_css', 'custom_js', 'status', 'template', 'seo_title', 'seo_description', 'show_latest_posts', 'latest_posts_limit', 'publish_at', 'unpublish_at', 'reviewer']
            : ['title', 'subtitle', 'slug', 'parent_slug', 'post_type', 'body', 'status', 'published_at', 'publish_at', 'unpublish_at', 'reviewer', 'category', 'featured_image', 'featured_image_alt', 'layout', 'seo_title', 'seo_description'];
        $input = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $record)) {
                $input[$field] = $record[$field];
            }
        }
        if ($type === 'post') {
            $input['tags'] = implode(', ', array_map('strval', (array)($record['tags'] ?? [])));
        }
        return $input;
    }

    private function find(string $type, string $identifier): array
    {
        foreach ($this->repository($type)->all() as $record) {
            if (($record['slug'] ?? '') === $identifier || ($record['id'] ?? '') === $identifier) {
                return $record;
            }
        }
        throw new ContentMutationException(ucfirst($type) . ' not found.', 'not_found', 404);
    }

    private function assertRevision(array $record, string $expected): void
    {
        $current = ContentRevision::for($record);
        $expected = trim($expected, " \t\n\r\0\x0B\"");
        if ($expected === '' || !hash_equals($current, $expected)) {
            throw new ContentMutationException('The content changed after it was read. Fetch the latest revision and retry.', 'revision_conflict', 409, ['current_revision' => $current]);
        }
    }

    private function result(string $type, string $action, ?array $before, array $after): array
    {
        $fields = ['title', 'slug', 'body', 'status', 'publish_at', 'unpublish_at', 'reviewer', 'seo_title', 'seo_description'];
        $changed = [];
        foreach ($fields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        return [
            'ok' => true,
            'action' => $action,
            'resource' => [
                'id' => (string)($after['id'] ?? ''),
                'type' => $type,
                'slug' => (string)($after['slug'] ?? ''),
                'status' => (string)($after['status'] ?? 'draft'),
                'revision' => ContentRevision::for($after),
                'updated_at' => (string)($after['updated_at'] ?? ''),
            ],
            'previous_revision' => $before === null ? null : ContentRevision::for($before),
            'change_summary' => ['fields' => $changed],
        ];
    }

    private function repository(string $type): PageRepository|PostRepository
    {
        return $type === 'page' ? $this->pages : $this->posts;
    }

    private function type(string $type): string
    {
        if (!in_array($type, ['page', 'post'], true)) {
            throw new ContentMutationException('Unsupported content type.', 'unsupported_type', 400);
        }
        return $type;
    }

    private function locked(string $type, callable $callback): array
    {
        $path = $this->config->paths()->dataPath('locks/content-' . $type . '.lock');
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create content lock directory.');
        }
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock content mutations.');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function record(string $actor, string $action, array $record, string $requestId, array $summary): void
    {
        $this->audit->record($actor, 'content.' . $action, (string)($record['id'] ?? $record['slug'] ?? ''), '', 'success', [
            'request_id' => $requestId,
            'revision' => ContentRevision::for($record),
            'change_summary' => $summary,
        ]);
    }
}
