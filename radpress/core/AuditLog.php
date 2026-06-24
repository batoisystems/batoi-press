<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class AuditLog
{
    public const MIN_RETENTION_DAYS = 90;

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
        return array_slice($this->allEntries(), 0, $limit);
    }

    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $perPage = max(10, min(100, $perPage));
        $page = max(1, $page);
        $entries = $this->filtered($filters);
        $total = count($entries);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);

        return [
            'entries' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'filters' => $filters,
        ];
    }

    public function filtered(array $filters): array
    {
        return $this->filterEntries($this->allEntries(), $filters);
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

    public function export(array $filters, string $format = 'csv'): array
    {
        $entries = $this->filterEntries($this->allEntries(), $filters);
        if ($format === 'jsonl') {
            $lines = array_map(static fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_SLASHES), $entries);
            return [
                'name' => 'audit-log-' . date('Ymd-His') . '.jsonl',
                'type' => 'application/x-ndjson; charset=UTF-8',
                'body' => implode("\n", $lines) . ($lines !== [] ? "\n" : ''),
            ];
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['name' => 'audit-log.csv', 'type' => 'text/csv; charset=UTF-8', 'body' => ''];
        }
        fputcsv($handle, ['time', 'user', 'action', 'outcome', 'target', 'ip', 'details'], ',', '"', '', "\n");
        foreach ($entries as $entry) {
            fputcsv($handle, [
                (string)$entry['time'],
                (string)$entry['user'],
                (string)$entry['action'],
                (string)$entry['outcome'],
                (string)$entry['target'],
                (string)$entry['ip'],
                json_encode($entry['details'] ?? [], JSON_UNESCAPED_SLASHES),
            ], ',', '"', '', "\n");
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return [
            'name' => 'audit-log-' . date('Ymd-His') . '.csv',
            'type' => 'text/csv; charset=UTF-8',
            'body' => is_string($body) ? $body : '',
        ];
    }

    public function cleanup(int $retentionDays): array
    {
        if ($retentionDays < self::MIN_RETENTION_DAYS) {
            return [
                'ok' => false,
                'removed' => 0,
                'kept' => count($this->allEntries()),
                'error' => 'Audit retention cannot be less than ' . self::MIN_RETENTION_DAYS . ' days.',
            ];
        }

        $cutoff = time() - ($retentionDays * 86400);
        $kept = [];
        $removed = 0;
        foreach ($this->allEntries(false) as $entry) {
            $timestamp = strtotime((string)($entry['time'] ?? ''));
            if ($timestamp !== false && $timestamp < $cutoff) {
                $removed++;
                continue;
            }
            $kept[] = $entry;
        }

        $body = '';
        foreach ($kept as $entry) {
            $body .= json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $this->files->write($this->path(), $body);

        return ['ok' => true, 'removed' => $removed, 'kept' => count($kept), 'error' => ''];
    }

    private function allEntries(bool $newestFirst = true): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $this->normalizeEntry($decoded);
            }
        }

        return $newestFirst ? array_reverse($entries) : $entries;
    }

    private function filterEntries(array $entries, array $filters): array
    {
        $search = strtolower(trim((string)($filters['q'] ?? '')));
        $action = trim((string)($filters['action'] ?? ''));
        $outcome = trim((string)($filters['outcome'] ?? ''));
        $actor = strtolower(trim((string)($filters['actor'] ?? '')));
        $from = strtotime((string)($filters['from'] ?? '')) ?: null;
        $to = strtotime((string)($filters['to'] ?? '')) ?: null;
        if ($to !== null) {
            $to += 86399;
        }

        return array_values(array_filter($entries, static function (array $entry) use ($search, $action, $outcome, $actor, $from, $to): bool {
            if ($action !== '' && (string)$entry['action'] !== $action) {
                return false;
            }
            if ($outcome !== '' && (string)$entry['outcome'] !== $outcome) {
                return false;
            }
            if ($actor !== '' && !str_contains(strtolower((string)$entry['user']), $actor)) {
                return false;
            }
            $timestamp = strtotime((string)$entry['time']);
            if ($from !== null && ($timestamp === false || $timestamp < $from)) {
                return false;
            }
            if ($to !== null && ($timestamp === false || $timestamp > $to)) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $haystack = strtolower((string)$entry['user'] . ' ' . (string)$entry['action'] . ' ' . (string)$entry['outcome'] . ' ' . (string)$entry['target'] . ' ' . (string)$entry['ip'] . ' ' . json_encode($entry['details'] ?? []));
            return str_contains($haystack, $search);
        }));
    }

    public function facets(): array
    {
        $actions = [];
        $outcomes = [];
        foreach ($this->allEntries() as $entry) {
            $actions[(string)$entry['action']] = true;
            $outcomes[(string)$entry['outcome']] = true;
        }
        $actions = array_keys($actions);
        $outcomes = array_keys($outcomes);
        sort($actions);
        sort($outcomes);

        return ['actions' => $actions, 'outcomes' => $outcomes];
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

    private function path(): string
    {
        return $this->paths->dataPath('log/audit.jsonl');
    }
}
