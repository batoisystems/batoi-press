<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Core\Paths;

/** A bounded, deliberately reduced audit projection for administrator connections. */
final class ActivityReadService
{
    public const MAX_BYTES = 1048576;
    public const MAX_LINES = 2000;

    public function __construct(private readonly Paths $paths) {}

    public function report(array $filters = []): array
    {
        if (array_diff(array_keys($filters), ['limit', 'action', 'outcome']) !== []) $this->invalid();
        $limit = $filters['limit'] ?? 25;
        if ((!is_int($limit) && !is_string($limit)) || !preg_match('/^[0-9]{1,3}$/D', (string)$limit) || (int)$limit < 1 || (int)$limit > 100) $this->invalid();
        foreach (['action', 'outcome'] as $key) {
            if (isset($filters[$key]) && (!is_string($filters[$key]) || !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $filters[$key]))) $this->invalid();
        }
        $path = $this->paths->dataPath('log/audit.jsonl');
        if (!is_file($path)) return ['data' => [], 'window_truncated' => false, 'results_truncated' => false];
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new ContentMutationException('Activity history is unavailable.', 'activity_unavailable', 503);
        try {
            $size = (int)(fstat($handle)['size'] ?? 0);
            $start = max(0, $size - self::MAX_BYTES);
            if (fseek($handle, $start) !== 0) throw new ContentMutationException('Activity history is unavailable.', 'activity_unavailable', 503);
            $tail = stream_get_contents($handle, self::MAX_BYTES);
            if ($tail === false) throw new ContentMutationException('Activity history is unavailable.', 'activity_unavailable', 503);
        } finally { fclose($handle); }
        // Discard a potentially partial first record and any unfinished append.
        if ($start > 0) $tail = str_contains($tail, "\n") ? substr($tail, strpos($tail, "\n") + 1) : '';
        $last = strrpos($tail, "\n");
        $lines = $last === false ? [] : explode("\n", substr($tail, 0, $last));
        $truncated = $start > 0 || count($lines) > self::MAX_LINES;
        $rows = [];
        foreach (array_reverse(array_slice($lines, -self::MAX_LINES)) as $line) {
            $entry = json_decode($line, true, 8);
            if (!is_array($entry)) continue;
            $row = $this->project($entry);
            if ($row === null) continue;
            foreach (['action', 'outcome'] as $key) {
                if (isset($filters[$key]) && $filters[$key] !== $row[$key]) continue 2;
            }
            $rows[] = $row;
            if (count($rows) > (int)$limit) break;
        }
        return ['data' => array_slice($rows, 0, (int)$limit), 'window_truncated' => $truncated, 'results_truncated' => count($rows) > (int)$limit];
    }

    private function project(array $entry): ?array
    {
        foreach (['id' => '/^[a-f0-9]{16}$/D', 'time' => '/^[0-9T:+Z.-]{20,35}$/D', 'action' => '/^[a-z][a-z0-9_.-]{0,79}$/D', 'outcome' => '/^[a-z][a-z0-9_.-]{0,79}$/D'] as $key => $pattern) {
            if (!is_string($entry[$key] ?? null) || !preg_match($pattern, $entry[$key])) return null;
        }
        $row = array_intersect_key($entry, array_flip(['id', 'time', 'action', 'outcome']));
        // Do not return arbitrary targets: admin routes can contain private paths or query values.
        if (is_string($entry['target'] ?? null) && preg_match('/^proposal_[a-f0-9]{32}$/D', $entry['target'])) $row['proposal_id'] = $entry['target'];
        // Actor names are intentional audit data; never include IP, user agent, query or raw details.
        if (is_string($entry['user'] ?? null) && strlen($entry['user']) <= 200) $row['actor'] = $entry['user'];
        $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];
        if (is_string($details['revision'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/D', $details['revision'])) $row['revision'] = $details['revision'];
        return $row;
    }

    private function invalid(): never
    {
        throw new ContentMutationException('Use limit 1–100 and optional exact action/outcome filters.', 'validation_failed', 422);
    }
}
