<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use RuntimeException;

final class SessionRegistry
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore())
    {
    }

    public function touch(string $sessionId, string $username, int $createdAt, int $lastSeenAt, string $ip = '', string $userAgent = ''): void
    {
        if ($sessionId === '' || $username === '') return;
        $hash = hash('sha256', $sessionId);
        $this->mutate(function (array $records) use ($hash, $username, $createdAt, $lastSeenAt, $ip, $userAgent): array {
            $records[$hash] = [
                'id' => $hash,
                'username' => substr($username, 0, 100),
                'created_at' => $createdAt,
                'last_seen_at' => $lastSeenAt,
                'ip' => substr($ip, 0, 64),
                'user_agent' => substr($userAgent, 0, 240),
            ];
            return $this->prune($records);
        });
    }

    public function allFor(string $username): array
    {
        $records = array_values(array_filter($this->read(), static fn (array $record): bool => ($record['username'] ?? '') === $username));
        usort($records, static fn (array $a, array $b): int => (int)($b['last_seen_at'] ?? 0) <=> (int)($a['last_seen_at'] ?? 0));
        return $records;
    }

    public function revoke(string $hash, string $username): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
        $found = false;
        $this->mutate(function (array $records) use ($hash, $username, &$found): array {
            if (($records[$hash]['username'] ?? '') !== $username) return $records;
            $found = true;
            unset($records[$hash]);
            return $records;
        });
        if (!$found) return false;
        $directory = $this->paths->dataPath('security/revoked-sessions');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to prepare session revocation storage.');
        return file_put_contents($directory . '/' . $hash, (string)time(), LOCK_EX) !== false;
    }

    public function isRevoked(string $sessionId): bool
    {
        return $sessionId !== '' && is_file($this->paths->dataPath('security/revoked-sessions/' . hash('sha256', $sessionId)));
    }

    public function forget(string $sessionId): void
    {
        if ($sessionId === '') return;
        $hash = hash('sha256', $sessionId);
        $this->mutate(static function (array $records) use ($hash): array { unset($records[$hash]); return $records; });
    }

    private function read(): array
    {
        $path = $this->path();
        $decoded = is_file($path) ? $this->files->readJson($path) : [];
        return is_array($decoded['sessions'] ?? null) ? $decoded['sessions'] : [];
    }

    private function mutate(callable $callback): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to prepare session inventory storage.');
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock session inventory.');
        try {
            $records = $this->read();
            $records = $callback($records);
            $this->files->writeJson($path, ['sessions' => $records]);
            @chmod($path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prune(array $records): array
    {
        $cutoff = time() - 2592000;
        $records = array_filter($records, static fn (array $record): bool => (int)($record['last_seen_at'] ?? 0) >= $cutoff);
        uasort($records, static fn (array $a, array $b): int => (int)($b['last_seen_at'] ?? 0) <=> (int)($a['last_seen_at'] ?? 0));
        return array_slice($records, 0, 200, true);
    }

    private function path(): string
    {
        return $this->paths->dataPath('security/session-inventory.json');
    }
}
