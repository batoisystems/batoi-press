<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use RuntimeException;

/** Fixed-resource JSON writes, shared by browser and approved machine operations. */
final class WebsiteDocumentStore
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore(), private readonly ?\Closure $checkpoint = null) {}

    public function read(string $resource): array
    {
        return $this->locked($resource, function () use ($resource): array {
            $this->recover($resource);
            return $this->raw($resource);
        });
    }

    public function commit(string $resource, array $document, string $expectedRevision, ?string $operationId = null): array
    {
        return $this->locked($resource, function () use ($resource, $document, $expectedRevision, $operationId): array {
            $this->recover($resource);
            $before = $this->raw($resource);
            if (!hash_equals(ContentRevision::for($before), trim($expectedRevision, '" '))) throw new MenuConflictException('This website document changed. Reload before saving.');
            if ($operationId !== null && is_file($this->receiptPath($operationId))) throw new MenuConflictException('This website operation already has a receipt.');
            if ($before !== []) $this->privateWrite($this->paths->dataPath('versions/website/' . $resource . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.json'), $before);
            if ($operationId !== null) {
                $this->privateWrite($this->journalPath($resource), ['resource' => $resource, 'id' => $operationId, 'state' => 'pending', 'before_hash' => ContentRevision::for($before), 'after_hash' => ContentRevision::for($document), 'result' => ['type' => $resource, 'revision' => ContentRevision::for($document)]]);
                if ($this->checkpoint !== null) ($this->checkpoint)('journal');
            }
            $this->files->writeJson($this->documentPath($resource), $document);
            if ($operationId !== null) {
                if ($this->checkpoint !== null) ($this->checkpoint)('written');
                $this->recover($resource);
            }
            return ['type' => $resource, 'revision' => ContentRevision::for($document)];
        });
    }

    public function receipt(string $resource, string $id): ?array
    {
        return $this->locked($resource, function () use ($resource, $id): array {
            $this->recover($resource);
            $path = $this->receiptPath($id);
            $receipt = is_file($path) ? $this->files->readJson($path) : [];
            if ($receipt !== [] && ($receipt['resource'] ?? '') !== $resource) throw new RuntimeException('Website receipt target mismatch.');
            return $receipt;
        }) ?: null;
    }

    private function recover(string $resource): void
    {
        $path = $this->journalPath($resource);
        if (!is_file($path)) return;
        $journal = $this->files->readJson($path);
        if (($journal['state'] ?? '') !== 'pending') return;
        if (($journal['resource'] ?? '') !== $resource || !is_string($journal['before_hash'] ?? null) || !is_string($journal['after_hash'] ?? null)) throw new RuntimeException('Invalid website recovery journal.');
        $current = ContentRevision::for($this->raw($resource));
        if (hash_equals($journal['after_hash'], $current)) $journal['state'] = 'committed';
        elseif (hash_equals($journal['before_hash'], $current)) $journal['state'] = 'not_applied';
        else throw new RuntimeException('Website recovery requires operator review: unexpected external changes.');
        $this->privateWrite($this->receiptPath($journal['id']), $journal);
        $this->privateWrite($path, $journal);
    }

    private function raw(string $resource): array
    {
        $path = $this->documentPath($resource);
        return is_file($path) ? $this->files->readJson($path) : [];
    }

    private function documentPath(string $resource): string
    {
        return match ($resource) {
            'widgets' => $this->paths->contentPath('widgets/sidebar.json'),
            'site' => $this->paths->configPath('site.json'),
            default => throw new RuntimeException('Unsupported website document.'),
        };
    }

    private function journalPath(string $resource): string { return $this->paths->dataPath('integrations/website-transactions/' . $resource . '.json'); }
    private function receiptPath(string $id): string
    {
        if (!preg_match('/^proposal_[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid website operation ID.');
        return $this->paths->dataPath('integrations/website-transactions/receipts/' . $id . '.json');
    }
    private function privateWrite(string $path, array $value): void { $this->files->writeJson($path, $value); @chmod($path, 0600); }
    private function locked(string $resource, callable $callback): array
    {
        $document = $this->documentPath($resource); // Validate the fixed resource before constructing any storage path.
        if (is_link($document) || is_link(dirname($document))) throw new RuntimeException('Linked website storage is not supported.');
        $path = $this->paths->dataPath('locks/website-' . $resource . '.lock');
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new RuntimeException('Unable to create website lock storage.');
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Unable to lock website storage.');
        }
        try { return $callback(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
