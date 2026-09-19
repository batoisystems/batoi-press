<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\Paths;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\MachineMediaInput;
use Batoi\Press\Security\UploadGuard;
use RuntimeException;
use InvalidArgumentException;

final class MediaRepository
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore(), private readonly ?\Closure $checkpoint = null, private readonly array $limits = [])
    {
    }

    public function count(): int
    {
        $dir = $this->paths->contentPath('media');
        return is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;
    }

    public static function assetId(array $asset): string
    {
        return 'asset_' . substr(hash('sha256', $asset['storage'] . ':' . $asset['relative']), 0, 24);
    }

    /** No byte hash for collection reads; exact revisions are obtained with load(). */
    public function metadata(string $id): array
    {
        return $this->locked(fn (): array => $this->metadataFields($this->rawMetadata($id)));
    }

    public function load(string $id): array
    {
        return $this->locked(fn (): array => $this->loadUnlocked($id));
    }

    private function loadUnlocked(string $id): array
    {
        $this->assetKey($id);
        foreach ((new AssetManager($this->paths))->all() as $asset) {
            if (self::assetId($asset) !== $id) continue;
            // Metadata changes never touch source bytes; include a strong byte revision.
            if ($asset['size'] > AssetManager::DEFAULT_MAX_BYTES) throw new InvalidArgumentException('This asset exceeds the managed metadata inspection limit.');
            $hash = @hash_file('sha256', $asset['path']);
            if (!is_string($hash)) throw new RuntimeException('Unable to inspect media revision.');
            $metadata = $this->metadataFields($this->rawMetadata($id));
            return ['id' => $id, 'asset' => $asset, 'sha256' => $hash, 'metadata' => $metadata,
                'revision' => ContentRevision::for(['id' => $id, 'sha256' => $hash, 'metadata' => $metadata])];
        }
        throw new InvalidArgumentException('Media asset was not found.');
    }

    public function prepareMetadata(string $id, array $changes, string $revision): array
    {
        return $this->locked(fn (): array => $this->prepareMetadataUnlocked($id, $changes, $revision));
    }

    private function prepareMetadataUnlocked(string $id, array $changes, string $revision): array
    {
        $current = $this->loadUnlocked($id);
        if (!hash_equals($current['revision'], trim($revision, '" '))) throw new MenuConflictException('Media changed. Read it again before preparing changes.');
        if (in_array($current['asset']['type'], ['scripts', 'styles'], true)) throw new InvalidArgumentException('Executable asset management is not available to machine clients.');
        return $current + ['before' => $current['metadata'], 'after' => array_replace($current['metadata'], MachineMediaInput::metadata($changes))];
    }

    /** A single private JSON document avoids partially persisted staged bytes/metadata. */
    public function stage(string $operation, array $input, string $connection, array $uploadConfig = []): array
    {
        $prepared = MachineMediaInput::prepare($input, $uploadConfig);
        $requestHash = ContentRevision::for(array_diff_key($prepared, ['bytes' => true]));
        return $this->locked(function () use ($operation, $prepared, $connection, $requestHash): array {
            $path = $this->stagePath($operation);
            if (is_file($path)) {
                $existing = $this->read($path);
                if (($existing['connection'] ?? '') !== $connection || ($existing['request_hash'] ?? '') !== $requestHash) throw new MenuConflictException('This upload operation was already used with different input.');
                return $existing['after'];
            }
            $total = $owned = 0;
            $records = $this->records('staging', 'proposal_*.json');
            if (count($records) >= $this->limit('staging_records', 512)) throw new InvalidArgumentException('Media staging history limit reached; ask an administrator to archive it.');
            foreach ($records as $recordPath) {
                $record = $this->read($recordPath);
                if (!isset($record['content_base64'])) continue;
                if ((int)($record['created_at'] ?? 0) + 86400 <= time()) {
                    $this->releaseStageUnlocked($record['operation']);
                    continue;
                }
                $bytes = strlen($record['content_base64']);
                $total += $bytes;
                if (($record['connection'] ?? '') === $connection) $owned += $bytes;
            }
            $encoded = base64_encode($prepared['bytes']);
            if ($total + strlen($encoded) > $this->limit('staged_bytes', 33554432) || $owned + strlen($encoded) > $this->limit('connection_bytes', 8388608)) {
                throw new InvalidArgumentException('Private media staging quota reached. Review or reject pending uploads before adding more.');
            }
            $manager = new AssetManager($this->paths);
            $safeName = (new UploadGuard(MachineMediaInput::EXTENSIONS, MachineMediaInput::MAX_BYTES))->safeName($prepared['name']);
            $relative = $manager->relativeUploadPath($safeName);
            $after = ['id' => self::assetId(['storage' => 'assets', 'relative' => $relative]), 'name' => $safeName, 'original_name' => $prepared['name'], 'storage' => 'assets', 'relative' => $relative,
                'url' => '/assets/' . $relative, 'sha256' => $prepared['sha256'], 'size' => $prepared['size'], 'mime_type' => $prepared['mime_type'], 'dimensions' => $prepared['dimensions'],
                'metadata' => array_replace(['title' => '', 'alt' => '', 'caption' => ''], $prepared['metadata'])];
            $this->privateWrite($path, ['operation' => $operation, 'connection' => $connection, 'request_hash' => $requestHash, 'after' => $after, 'content_base64' => $encoded, 'created_at' => time(), 'state' => 'staged']);
            return $after;
        });
    }

    public function stagedFile(string $operation, array $expected, array $uploadConfig = []): array
    {
        return $this->locked(fn (): array => $this->stagedFileUnlocked($operation, $expected, $uploadConfig));
    }

    private function stagedFileUnlocked(string $operation, array $expected, array $uploadConfig): array
    {
        $stage = $this->read($this->stagePath($operation));
        if (($stage['after'] ?? null) !== $expected || !is_string($stage['content_base64'] ?? null)) throw new RuntimeException('Staged media no longer matches this proposal.');
        $prepared = MachineMediaInput::prepare(['name' => $expected['original_name'], 'content_base64' => $stage['content_base64'], 'metadata' => $expected['metadata']], $uploadConfig);
        if ($prepared['sha256'] !== $expected['sha256'] || $prepared['size'] !== $expected['size'] || $prepared['mime_type'] !== $expected['mime_type'] || $prepared['dimensions'] !== $expected['dimensions']) throw new RuntimeException('Staged media integrity check failed.');
        return $prepared;
    }

    public function releaseStage(string $operation): void
    {
        $this->locked(fn () => $this->releaseStageUnlocked($operation));
    }

    private function releaseStageUnlocked(string $operation): void
    {
        $path = $this->stagePath($operation);
        if (!is_file($path)) return;
        $stage = $this->read($path);
        unset($stage['content_base64']); // Only private temporary bytes; retain the immutable manifest/history.
        $stage['state'] = 'released';
        $this->privateWrite($path, $stage);
    }

    public function applyUpload(string $operation, array $expected, array $uploadConfig = []): array
    {
        return $this->locked(function () use ($operation, $expected, $uploadConfig): array {
            if (is_file($this->receiptPath($operation))) throw new MenuConflictException('Media operation already has a receipt.');
            $prepared = $this->stagedFileUnlocked($operation, $expected, $uploadConfig);
            $manager = new AssetManager($this->paths);
            $target = $manager->prepareTarget($expected['relative']);
            if (file_exists($target) || is_link($target)) throw new MenuConflictException('Upload destination already exists.');
            $metadataRecords = $this->records('metadata', 'asset_*.json');
            if (count($metadataRecords) >= $this->limit('metadata_records', 4096)) throw new InvalidArgumentException('Media metadata inventory limit reached.');
            $total = 0;
            foreach ($metadataRecords as $path) {
                $record = $this->read($path);
                if (!($record['managed_upload'] ?? false)) continue;
                $asset = $manager->find((string)$record['storage'], (string)$record['relative']);
                $total += $asset['size'] ?? 0;
            }
            if ($total + $prepared['size'] > $this->limit('managed_bytes', 268435456)) throw new InvalidArgumentException('Managed media storage quota reached.');
            $before = $this->rawMetadata($expected['id']);
            if ($before !== []) throw new MenuConflictException('Upload metadata already exists.');
            $after = ['metadata' => $expected['metadata'], 'storage' => 'assets', 'relative' => $expected['relative'], 'managed_upload' => true];
            $result = ['id' => $expected['id'], 'url' => $expected['url'], 'revision' => ContentRevision::for(['id' => $expected['id'], 'sha256' => $expected['sha256'], 'metadata' => $expected['metadata']])];
            $journal = $this->journal($operation, 'upload', $expected['id'], $after, $before, $expected['sha256'], $result);
            $this->privateWrite($this->journalPath(), $journal);
            $this->tick('journal');
            $this->privateWrite($this->metadataPath($expected['id']), $after);
            $this->tick('metadata');
            $temporary = dirname($target) . '/.bp-upload-' . substr($operation, 9);
            $handle = @fopen($temporary, 'x');
            if ($handle === false) throw new RuntimeException('Unable to prepare atomic upload publication.');
            try {
                if (fwrite($handle, $prepared['bytes']) !== $prepared['size'] || !fflush($handle)) throw new RuntimeException('Unable to write complete media bytes.');
                chmod($temporary, 0664);
                // Same-directory hard link publishes complete bytes atomically and never replaces a target.
                if (!@link($temporary, $target)) throw new RuntimeException('Unable to publish media without replacing an existing file.');
            } finally { fclose($handle); if (is_file($temporary)) unlink($temporary); }
            $this->tick('file');
            $this->recoverLocked();
            $this->tick('receipt');
            return $result;
        });
    }

    public function applyMetadata(string $operation, string $id, array $changes, string $revision): array
    {
        return $this->locked(function () use ($operation, $id, $changes, $revision): array {
            if (is_file($this->receiptPath($operation))) throw new MenuConflictException('Media operation already has a receipt.');
            $prepared = $this->prepareMetadataUnlocked($id, $changes, $revision);
            $before = $this->rawMetadata($id);
            if ($before === [] && count($this->records('metadata', 'asset_*.json')) >= $this->limit('metadata_records', 4096)) throw new InvalidArgumentException('Media metadata inventory limit reached.');
            $after = array_replace($before, ['metadata' => $prepared['after'], 'storage' => $prepared['asset']['storage'], 'relative' => $prepared['asset']['relative']]);
            $result = ['id' => $id, 'url' => $prepared['asset']['url'], 'revision' => ContentRevision::for(['id' => $id, 'sha256' => $prepared['sha256'], 'metadata' => $prepared['after']])];
            $this->privateWrite($this->privatePath('versions/' . $operation . '.json'), $before);
            $this->privateWrite($this->journalPath(), $this->journal($operation, 'metadata', $id, $after, $before, $prepared['sha256'], $result));
            $this->tick('journal');
            $this->privateWrite($this->metadataPath($id), $after);
            $this->tick('metadata');
            $this->recoverLocked();
            $this->tick('receipt');
            return $result;
        });
    }

    public function receipt(string $operation): ?array
    {
        return $this->locked(fn (): ?array => is_file($this->receiptPath($operation)) ? $this->read($this->receiptPath($operation)) : null);
    }

    /** Called only while AssetManager's common mutation lock is held. */
    public function recoverLocked(): void
    {
        if (!is_file($this->journalPath())) return;
        $journal = $this->read($this->journalPath());
        if (($journal['state'] ?? '') !== 'pending') return;
        $id = $journal['id'];
        $current = $this->rawMetadata($id);
        if ($current !== $journal['before'] && $current !== $journal['after']) throw new RuntimeException('Media recovery stopped: metadata changed externally.');
        $asset = (new AssetManager($this->paths))->find($journal['after']['storage'], $journal['after']['relative']);
        $expectedPath = $this->paths->contentPath($journal['after']['storage'] . '/' . $journal['after']['relative']);
        if ($asset === null && (file_exists($expectedPath) || is_link($expectedPath))) throw new RuntimeException('Media recovery stopped: unsafe asset state.');
        $hash = $asset === null ? null : @hash_file('sha256', $asset['path']);
        if ($hash !== null && $hash !== $journal['sha256']) throw new RuntimeException('Media recovery stopped: asset bytes changed externally.');
        if ($journal['kind'] === 'upload') $this->cleanupTemporary($journal, $expectedPath);
        if ($journal['kind'] === 'upload' && $hash === null) {
            $this->privateWrite($this->metadataPath($id), $journal['before']);
            $journal['state'] = 'not_applied';
        } else {
            if ($hash !== $journal['sha256']) throw new RuntimeException('Media recovery stopped: asset bytes changed externally.');
            if ($journal['kind'] === 'upload') $this->privateWrite($this->metadataPath($id), $journal['after']);
            $journal['state'] = $journal['kind'] === 'upload' || $current === $journal['after'] ? 'committed' : 'not_applied';
        }
        $this->privateWrite($this->receiptPath($journal['operation']), $journal);
        $this->privateWrite($this->journalPath(), $journal);
        if ($journal['kind'] === 'upload') $this->releaseStageUnlocked($journal['operation']);
    }

    private function cleanupTemporary(array $journal, string $target): void
    {
        $temporary = dirname($target) . '/.bp-upload-' . substr($journal['operation'], 9);
        if (!file_exists($temporary) && !is_link($temporary)) return;
        // A process may have stopped while writing its hidden file or after linking it.
        // Remove only bytes independently proven to be a prefix of this journal's payload.
        (new AssetManager($this->paths))->prepareTarget($journal['after']['relative']);
        if (is_link($temporary) || !is_file($temporary) || filesize($temporary) > MachineMediaInput::MAX_BYTES) throw new RuntimeException('Media recovery stopped: unexpected temporary file.');
        $stage = $this->read($this->stagePath($journal['operation']));
        $expected = is_string($stage['content_base64'] ?? null) ? base64_decode($stage['content_base64'], true) : false;
        $actual = file_get_contents($temporary);
        if (!is_string($expected) || hash('sha256', $expected) !== $journal['sha256'] || !is_string($actual) || !str_starts_with($expected, $actual)) throw new RuntimeException('Media recovery stopped: temporary bytes changed externally.');
        if (!unlink($temporary)) throw new RuntimeException('Unable to clear interrupted media staging.');
    }

    private function journal(string $operation, string $kind, string $id, array $after, array $before, string $hash, array $result): array
    {
        $this->receiptPath($operation);
        return ['operation' => $operation, 'kind' => $kind, 'id' => $id, 'before' => $before, 'after' => $after, 'sha256' => $hash, 'state' => 'pending', 'result' => $result];
    }
    private function locked(callable $callback): mixed { return (new AssetManager($this->paths))->withMutationLock($callback); }
    private function tick(string $point): void { if ($this->checkpoint !== null) ($this->checkpoint)($point); }
    private function metadataFields(array $record): array { return array_replace(['title' => '', 'alt' => '', 'caption' => ''], MachineMediaInput::metadata($record['metadata'] ?? [])); }
    private function rawMetadata(string $id): array { $path = $this->metadataPath($id); return is_file($path) ? $this->read($path) : []; }
    private function metadataPath(string $id): string { $this->assetKey($id); return $this->privatePath('metadata/' . $id . '.json'); }
    private function assetKey(string $id): void { if (!preg_match('/^asset_[a-f0-9]{24}$/D', $id)) throw new InvalidArgumentException('Invalid media ID.'); }
    private function operationKey(string $id): void { if (!preg_match('/^proposal_[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('Invalid media operation.'); }
    private function stagePath(string $id): string { $this->operationKey($id); return $this->privatePath('staging/' . $id . '.json'); }
    private function receiptPath(string $id): string { $this->operationKey($id); return $this->privatePath('receipts/' . $id . '.json'); }
    private function journalPath(): string { return $this->privatePath('transaction.json'); }
    private function limit(string $key, int $maximum): int { return min($maximum, max(1, (int)($this->limits[$key] ?? $maximum))); }
    private function records(string $directory, string $pattern): array
    {
        $path = $this->privatePath($directory);
        $records = glob($path . '/' . $pattern) ?: [];
        if (count($records) > 4096) throw new RuntimeException('Media inventory requires operator maintenance.');
        return $records;
    }
    private function privatePath(string $relative): string
    {
        $path = $this->paths->dataPath();
        foreach (explode('/', 'integrations/media/' . $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new RuntimeException('Linked media state is not supported.');
        }
        return $path;
    }
    private function read(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 1100000) throw new RuntimeException('Invalid private media state.');
        return $this->files->readJson($path);
    }
    private function privateWrite(string $path, array $record): void
    {
        $mask = umask(0077);
        try { $this->files->writeJson($path, $record); chmod($path, 0600); }
        finally { umask($mask); }
    }
}
