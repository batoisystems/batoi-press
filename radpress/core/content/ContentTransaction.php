<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Slug;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Application\ContentRevision;
use Closure;
use RuntimeException;
use Throwable;

/** Recoverable meta/body/hierarchy writes. Repository reads share this storage lock. */
final class ContentTransaction
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore(), private readonly ?Closure $checkpoint = null)
    {
    }

    public function read(string $type, callable $reader): array
    {
        // Exclusive because a previous process may have stopped between file writes.
        return $this->locked($type, function () use ($type, $reader): array {
            $this->recover($type);
            return $reader();
        });
    }

    public function commit(string $type, array $prepared): array
    {
        return $this->locked($type, function () use ($type, $prepared): array {
            $this->recover($type);
            $meta = $prepared['meta'];
            $target = $this->slug((string)$meta['slug']);
            $source = $this->slug((string)($prepared['original_slug'] ?: $target));
            $base = $this->base($type);
            $rename = $source !== $target && is_dir($base . '/' . $source);
            if ($rename && file_exists($base . '/' . $target)) throw new RuntimeException('The destination content directory already exists.');
            $this->safePath($base, $source . '/meta.json');
            $this->safePath($base, $target . '/meta.json');
            $currentRevision = null;
            if (is_file($base . '/' . $source . '/meta.json') && is_file($base . '/' . $source . '/body.html')) {
                $current = $this->files->readJson($base . '/' . $source . '/meta.json');
                $current['body'] = (new HtmlContent())->sanitize($this->files->read($base . '/' . $source . '/body.html'));
                $currentRevision = ContentRevision::for($current);
            }
            if (!array_key_exists('base_revision', $prepared) || $prepared['base_revision'] !== $currentRevision) {
                throw new RuntimeException('Content changed between preparation and storage. Reload before saving.');
            }
            if (isset($prepared['hierarchy_revision']) && $prepared['hierarchy_revision'] !== $this->hierarchyFingerprint($type)) {
                throw new RuntimeException('Content hierarchy changed between preparation and storage. Review the affected routes again.');
            }
            $entries = [];
            foreach (['meta.json' => $this->encode($meta), 'body.html' => (string)$prepared['body']] as $name => $after) {
                $beforePath = $this->safePath($base, $source . '/' . $name);
                $entries[] = ['path' => $target . '/' . $name, 'before' => is_file($beforePath) ? base64_encode($this->files->read($beforePath)) : null, 'after' => base64_encode($after)];
            }
            if ($rename) {
                foreach (glob($base . '/*/meta.json') ?: [] as $path) {
                    if (basename(dirname($path)) === $source) continue;
                    $relative = $this->slug(basename(dirname($path))) . '/meta.json';
                    $this->safePath($base, $relative);
                    $before = $this->files->read($path);
                    $child = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
                    if (Slug::normalize((string)($child['parent_slug'] ?? '')) !== $source) continue;
                    $child['parent_slug'] = $target;
                    $child['updated_at'] = $meta['updated_at'];
                    $entries[] = ['path' => $relative, 'before' => base64_encode($before), 'after' => base64_encode($this->encode($child))];
                }
            }
            $journal = ['state' => 'pending', 'type' => $type, 'source' => $source, 'target' => $target,
                'rename' => $rename, 'new_directory' => !is_dir($base . '/' . $target) && !$rename,
                'transaction_id' => date('Ymd-His') . '-' . bin2hex(random_bytes(6)), 'entries' => $entries,
                'operation_id' => (string)($prepared['operation_id'] ?? ''),
                'result' => ['id' => $meta['id'], 'type' => $type, 'slug' => $target, 'status' => $meta['status'],
                    'revision' => ContentRevision::for($meta + ['body' => $prepared['body']]), 'updated_at' => $meta['updated_at']]];
            if ($journal['operation_id'] !== '') $this->receiptPath($journal['operation_id']);
            if (strlen($this->encode($journal)) > 33554432) throw new RuntimeException('Content transaction exceeds the 32 MiB recovery limit.');
            // Preserve compatible version snapshots before publishing a journal or changing content.
            foreach ($entries as $entry) {
                if ($entry['before'] === null) continue;
                $relative = $entry['path'];
                if ($rename && str_starts_with($relative, $target . '/')) $relative = $source . substr($relative, strlen($target));
                [$slug, $name] = explode('/', $relative);
                $this->files->write($this->paths->dataPath('versions/' . $type . 's/' . $slug . '/' . $journal['transaction_id'] . '/' . $name), $this->decode($entry['before']));
            }
            $this->writeJournal($type, $journal);
            try {
                $this->tick('journal');
                if ($rename && !rename($base . '/' . $source, $base . '/' . $target)) throw new RuntimeException('Unable to move the content directory.');
                $this->tick('rename');
                foreach ($entries as $index => $entry) {
                    $this->files->write($this->safePath($base, $entry['path']), $this->decode($entry['after']));
                    $this->tick('file:' . $index);
                }
                $journal['state'] = 'committed';
                $this->writeJournal($type, $journal);
                $this->tick('committed');
                $this->preserveReceipt($journal);
                return $meta;
            } catch (Throwable $error) {
                // Preflight recovery checks every file before modifying any; unknown edits fail closed.
                $this->recover($type);
                throw $error;
            }
        });
    }

    public function hierarchyRevision(string $type): string
    {
        return $this->read($type, fn (): array => ['revision' => $this->hierarchyFingerprint($type)])['revision'];
    }

    private function hierarchyFingerprint(string $type): string
    {
        $base = $this->base($type);
        $records = [];
        foreach (glob($base . '/*/meta.json') ?: [] as $path) {
            $slug = $this->slug(basename(dirname($path)));
            $record = $this->files->readJson($this->safePath($base, $slug . '/meta.json'));
            $records[$slug] = array_intersect_key($record, array_flip(['id', 'slug', 'parent_slug', 'post_type']));
        }
        ksort($records);
        return ContentRevision::for($records);
    }

    private function recover(string $type): void
    {
        $path = $this->journalPath($type);
        if (!is_file($path)) return;
        $journal = $this->files->readJson($path);
        if (in_array($journal['state'] ?? '', ['committed', 'rolled_back'], true)) {
            $this->preserveReceipt($journal);
            return;
        }
        if (($journal['state'] ?? '') !== 'pending') throw new RuntimeException('Invalid content recovery state.');
        $base = $this->base($type);
        $source = $this->slug((string)($journal['source'] ?? ''));
        $target = $this->slug((string)($journal['target'] ?? ''));
        if (($journal['type'] ?? '') !== $type || !is_array($journal['entries'] ?? null)) throw new RuntimeException('Invalid content recovery journal.');
        $moved = false;
        if ($journal['rename']) {
            if (is_dir($base . '/' . $source) && is_dir($base . '/' . $target)) throw new RuntimeException('Content recovery found conflicting directories; operator review required.');
            $moved = !is_dir($base . '/' . $source) && is_dir($base . '/' . $target);
            if (!$moved && !is_dir($base . '/' . $source)) throw new RuntimeException('Content recovery source is missing.');
        }
        $restore = [];
        foreach ($journal['entries'] as $entry) {
            $relative = (string)$entry['path'];
            if ($journal['rename'] && !$moved && str_starts_with($relative, $target . '/')) $relative = $source . substr($relative, strlen($target));
            $currentPath = $this->safePath($base, $relative);
            $current = is_file($currentPath) ? $this->files->read($currentPath) : null;
            $before = $entry['before'] === null ? null : $this->decode($entry['before']);
            $after = $this->decode($entry['after']);
            if ($current !== $before && $current !== $after) throw new RuntimeException('Content recovery detected an external edit; operator review required.');
            $restore[] = ['path' => $relative, 'before' => $before];
        }
        if ($moved && !rename($base . '/' . $target, $base . '/' . $source)) throw new RuntimeException('Unable to restore the content directory.');
        foreach ($restore as $entry) {
            $relative = $entry['path'];
            if ($moved && str_starts_with($relative, $target . '/')) $relative = $source . substr($relative, strlen($target));
            $file = $this->safePath($base, $relative);
            if ($entry['before'] === null) {
                // Only a file created by this interrupted transaction, validated above, is removed.
                if (is_file($file) && !unlink($file)) throw new RuntimeException('Unable to remove an incomplete transaction file.');
            } else {
                $this->files->write($file, $entry['before']);
            }
        }
        if ($journal['new_directory'] && is_dir($base . '/' . $target)) {
            $remaining = array_diff(scandir($base . '/' . $target) ?: [], ['.', '..']);
            if ($remaining === []) rmdir($base . '/' . $target);
        }
        $journal['state'] = 'rolled_back';
        $this->writeJournal($type, $journal);
        $this->preserveReceipt($journal);
    }

    public function receipt(string $type, string $operation): ?array
    {
        $result = $this->read($type, function () use ($operation): array {
            $path = $this->receiptPath($operation);
            return is_file($path) ? $this->files->readJson($path) : [];
        });
        return $result === [] ? null : $result;
    }

    private function preserveReceipt(array $journal): void
    {
        if (($journal['operation_id'] ?? '') === '' || !in_array($journal['state'] ?? '', ['committed', 'rolled_back'], true)) return;
        $path = $this->receiptPath($journal['operation_id']);
        if (is_file($path)) return;
        $this->files->writeJson($path, array_intersect_key($journal, array_flip(['operation_id', 'transaction_id', 'type', 'state', 'result'])));
        @chmod($path, 0600);
    }

    private function receiptPath(string $operation): string
    {
        if (preg_match('/^proposal_[a-f0-9]{32}$/D', $operation) !== 1) throw new RuntimeException('Invalid transaction operation.');
        return $this->paths->dataPath('integrations/content-transactions/receipts/' . $operation . '.json');
    }

    private function locked(string $type, callable $callback): array
    {
        $this->base($type);
        $path = $this->paths->dataPath('locks/storage-' . $type . '.lock');
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new RuntimeException('Unable to create content storage lock.');
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock content storage.');
        try { return $callback(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function base(string $type): string
    {
        if (!in_array($type, ['page', 'post'], true)) throw new RuntimeException('Unsupported transaction content type.');
        return $this->paths->contentPath($type . 's');
    }

    private function slug(string $slug): string
    {
        if ($slug === '' || Slug::normalize($slug) !== $slug || strlen($slug) > 240) throw new RuntimeException('Invalid content directory name.');
        return $slug;
    }

    private function safePath(string $base, string $relative): string
    {
        if (preg_match('#^([a-z0-9-]{1,240})/(meta\.json|body\.html)$#D', $relative, $match) !== 1) throw new RuntimeException('Invalid transaction file.');
        $this->slug($match[1]);
        if (is_link($base . '/' . $match[1]) || is_link($base . '/' . $relative)) throw new RuntimeException('Symlinked content cannot participate in a transaction.');
        return $base . '/' . $relative;
    }

    private function journalPath(string $type): string { return $this->paths->dataPath('integrations/content-transactions/' . $type . '.json'); }
    private function writeJournal(string $type, array $record): void
    {
        if (in_array($record['state'] ?? '', ['committed', 'rolled_back'], true)) unset($record['entries']);
        $path = $this->journalPath($type);
        $this->files->writeJson($path, $record);
        @chmod($path, 0600);
    }
    private function encode(array $data): string { return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) throw new RuntimeException('Invalid content recovery data.');
        return $decoded;
    }
    private function tick(string $stage): void { if ($this->checkpoint !== null) ($this->checkpoint)($stage); }
}
