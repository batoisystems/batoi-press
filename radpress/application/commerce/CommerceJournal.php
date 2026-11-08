<?php
declare(strict_types=1);

namespace Batoi\Press\Commerce;

use Batoi\Press\Core\{FileStore, Paths};
use RuntimeException;

/** Called while the product storage lock is held. Prepared journals roll forward. */
final class CommerceJournal
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore()) {}

    public function locked(callable $work): array
    {
        $dir = $this->paths->dataPath('commerce');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to prepare commerce storage.');
        $lock = fopen($dir . '/storage.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock commerce storage.');
        try { $this->recover(); return $work($this); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function commit(array $writes, ?\Closure $checkpoint = null): void
    {
        $entries = [];
        foreach ($writes as $path => $value) {
            $file = $this->path($path);
            $after = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $entries[] = ['path'=>$path, 'before'=>is_file($file) ? base64_encode($this->files->read($file)) : null, 'after'=>base64_encode($after)];
        }
        $journal = ['schema'=>1,'state'=>'prepared','entries'=>$entries];
        if (strlen(json_encode($journal, JSON_THROW_ON_ERROR)) > 2097152) throw new RuntimeException('Commerce transaction exceeds its recovery limit.');
        $this->files->writeJson($this->journal(), $journal);
        chmod($this->journal(), 0600);
        if ($checkpoint !== null) $checkpoint('prepared');
        foreach ($entries as $i => $entry) {
            $this->write($entry['path'], $this->decode($entry['after']));
            if ($checkpoint !== null) $checkpoint('file:' . $i);
        }
        $this->files->writeJson($this->journal(), ['schema'=>1,'state'=>'committed']);
        chmod($this->journal(), 0600);
    }

    public function read(string $path): array
    {
        $file = $this->path($path);
        return is_file($file) ? $this->files->readJson($file) : [];
    }

    private function recover(): void
    {
        if (!is_file($this->journal())) return;
        $journal = $this->files->readJson($this->journal());
        if (($journal['state'] ?? '') === 'committed') return;
        if (($journal['schema'] ?? null) !== 1 || ($journal['state'] ?? '') !== 'prepared' || !is_array($journal['entries'] ?? null) || count($journal['entries']) > 100) throw new RuntimeException('Commerce recovery requires operator review.');
        foreach ($journal['entries'] as $entry) {
            $file = $this->path((string)$entry['path']);
            $current = is_file($file) ? $this->files->read($file) : null;
            $before = $entry['before'] === null ? null : $this->decode($entry['before']);
            $after = $this->decode($entry['after']);
            if ($current !== $before && $current !== $after) throw new RuntimeException('Commerce recovery detected an external edit; operator review required.');
        }
        foreach ($journal['entries'] as $entry) $this->write($entry['path'], $this->decode($entry['after']));
        $this->files->writeJson($this->journal(), ['schema'=>1,'state'=>'committed']);
        chmod($this->journal(), 0600);
    }

    private function write(string $path, string $value): void
    {
        $file = $this->path($path);
        $this->files->write($file, $value);
        if (str_starts_with($path, 'commerce/')) chmod($file, 0600);
    }

    private function path(string $path): string
    {
        if (preg_match('#^products/([a-z0-9-]{1,240})/meta.json$#D', $path)) $file = $this->paths->contentPath($path);
        elseif (preg_match('#^commerce/(state|orders/ord_[a-f0-9]{32}|events/[a-f0-9]{64}|outbox/ord_[a-f0-9]{32}|payments/[a-f0-9]{64})\.json$#D', $path)) $file = $this->paths->dataPath($path);
        else throw new RuntimeException('Invalid commerce transaction path.');
        for ($dir = $file; $dir !== dirname($dir); $dir = dirname($dir)) {
            if (is_link($dir)) throw new RuntimeException('Symlinked commerce storage is unsupported.');
            if ($dir === $this->paths->root()) break;
        }
        return $file;
    }
    private function decode(string $value): string { $value = base64_decode($value, true); if ($value === false) throw new RuntimeException('Invalid commerce recovery data.'); return $value; }
    private function journal(): string { return $this->paths->dataPath('commerce/journal.json'); }
}
