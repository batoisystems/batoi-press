<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class AuditLog
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files
    ) {
    }

    public function record(string $user, string $action, string $target, string $ip = '', string $outcome = 'success', array $details = []): void
    {
        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'time' => date(DATE_ATOM),
            'user' => $user !== '' ? $user : 'system',
            'action' => $action,
            'target' => $target,
            'outcome' => $outcome,
            'ip' => $ip,
        ];
        if ($details !== []) {
            $entry['details'] = $this->safeDetails($details);
        }

        $this->files->write(
            $this->paths->dataPath('log/audit.jsonl'),
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            append: true
        );
    }

    public function recordRequest(array $user, Request $request, string $outcome = 'seen'): void
    {
        $path = $request->path;
        $kind = match (true) {
            str_contains($path, '/download/') => 'admin.download',
            $request->method === 'POST' => 'admin.action',
            default => 'admin.view',
        };

        $this->record(
            (string)($user['username'] ?? 'admin'),
            $kind,
            $path,
            (string)($request->server['REMOTE_ADDR'] ?? ''),
            $outcome,
            [
                'method' => $request->method,
                'route' => $path,
                'query' => $this->safeQuery($request->query),
                'user_agent' => (string)($request->server['HTTP_USER_AGENT'] ?? ''),
            ]
        );
    }

    public function entries(int $limit = 200): array
    {
        $path = $this->paths->dataPath('log/audit.jsonl');
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach (array_slice(array_reverse($lines), 0, $limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $this->normalizeEntry($decoded);
            }
        }

        return $entries;
    }

    public function summary(array $entries): array
    {
        $actions = [];
        $failures = 0;
        foreach ($entries as $entry) {
            $action = (string)($entry['action'] ?? '');
            $actions[$action] = ($actions[$action] ?? 0) + 1;
            if (($entry['outcome'] ?? 'success') !== 'success' && ($entry['outcome'] ?? '') !== 'seen') {
                $failures++;
            }
        }

        arsort($actions);
        return [
            'events' => count($entries),
            'failures' => $failures,
            'top_action' => array_key_first($actions) ?? 'None',
            'actors' => count(array_unique(array_map(static fn (array $entry): string => (string)($entry['user'] ?? 'system'), $entries))),
        ];
    }

    private function normalizeEntry(array $entry): array
    {
        $entry['id'] = (string)($entry['id'] ?? '');
        $entry['time'] = (string)($entry['time'] ?? '');
        $entry['user'] = (string)($entry['user'] ?? 'system');
        $entry['action'] = (string)($entry['action'] ?? '');
        $entry['target'] = (string)($entry['target'] ?? '');
        $entry['outcome'] = (string)($entry['outcome'] ?? 'success');
        $entry['ip'] = (string)($entry['ip'] ?? '');
        $entry['details'] = is_array($entry['details'] ?? null) ? $entry['details'] : [];
        return $entry;
    }

    private function safeQuery(array $query): array
    {
        unset($query['csrf_token'], $query['password']);
        return $this->safeDetails($query);
    }

    private function safeDetails(array $details): array
    {
        $safe = [];
        foreach ($details as $key => $value) {
            $key = (string)$key;
            if (in_array(strtolower($key), ['password', 'csrf_token', 'token'], true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = substr((string)$value, 0, 500);
            }
        }
        return $safe;
    }
}
