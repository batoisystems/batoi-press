<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
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

    private function validated(string $type, array $input, ?array $existing): array
    {
        $allowed = $type === 'page'
            ? ['title', 'slug', 'parent_slug', 'body', 'blocks', 'custom_css', 'custom_js', 'template', 'seo_title', 'seo_description', 'show_latest_posts', 'latest_posts_limit', 'publish_at', 'unpublish_at', 'reviewer', 'workflow_note']
            : ['title', 'subtitle', 'slug', 'parent_slug', 'body', 'category', 'tags', 'featured_image', 'featured_image_alt', 'layout', 'seo_title', 'seo_description', 'published_at', 'publish_at', 'unpublish_at', 'reviewer', 'workflow_note'];
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
            : ['title', 'subtitle', 'slug', 'parent_slug', 'body', 'status', 'published_at', 'publish_at', 'unpublish_at', 'reviewer', 'category', 'featured_image', 'featured_image_alt', 'layout', 'seo_title', 'seo_description'];
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
