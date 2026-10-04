<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\Paths;
use Batoi\Press\Core\FileStore;

final class RateLimiter
{
    public function __construct(private readonly Paths $paths, private readonly int $maxAttempts = 5, private readonly int $windowSeconds = 300) {}

    public function reserve(string $key): ?string
    {
        $ticket = bin2hex(random_bytes(12));
        $reserved = false;
        try {
            (new FileStore())->mutateJson($this->file($key), function (array $state) use ($ticket, &$reserved): array {
                $attempts = $this->valid($state);
                if (count($attempts) >= $this->maxAttempts) return $attempts;
                $attempts[] = ['time' => time(), 'id' => $ticket];
                $reserved = true;
                return $attempts;
            });
        } catch (\RuntimeException) { return null; }
        return $reserved ? $ticket : null;
    }

    public function consume(string $key): bool { return $this->reserve($key) !== null; }
    public function hit(string $key): void { $this->reserve($key); }

    public function tooManyAttempts(string $key): bool
    {
        try {
            $state = is_file($this->file($key)) ? (new FileStore())->readJson($this->file($key)) : [];
            return count($this->valid($state)) >= $this->maxAttempts;
        } catch (\RuntimeException) { return true; }
    }

    public function refund(string $key, string $ticket): void
    {
        (new FileStore())->mutateJson($this->file($key), function (array $state) use ($ticket): array {
            return array_values(array_filter($this->valid($state), static fn(array $entry): bool => $entry['id'] !== $ticket));
        });
    }

    public function clear(string $key): void
    {
        (new FileStore())->mutateJson($this->file($key), static fn(array $state): array => []);
    }

    private function valid(array $state): array
    {
        if (!array_is_list($state)) throw new \RuntimeException('Invalid rate-limit state.');
        $attempts = [];
        foreach ($state as $entry) {
            if (is_int($entry)) $entry = ['time' => $entry, 'id' => 'legacy'];
            if (!is_array($entry) || !is_int($entry['time'] ?? null) || !is_string($entry['id'] ?? null)) throw new \RuntimeException('Invalid rate-limit state.');
            if ($entry['time'] >= time() - $this->windowSeconds) $attempts[] = $entry;
        }
        return $attempts;
    }

    private function file(string $key): string { return $this->paths->dataPath('tmp/rate/' . hash('sha256', $key) . '.json'); }
}
