<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\{FileStore, Paths};
use InvalidArgumentException;
use RuntimeException;

final class CollectionRepository
{
    public const MAX_RECORDS = 2000;
    private const MAX_DRAFT_BYTES = 1500000;
    private const MAX_FILE_BYTES = 12000000;

    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore()) {}

    public function get(string $id): ?array
    {
        if (!CollectionSchema::key($id)) return null;
        $path = $this->path($id);
        if (!is_file($path)) return null;
        if (filesize($path) > self::MAX_FILE_BYTES) throw new RuntimeException('Collection storage exceeds its size limit.');
        $collection = $this->files->readJson($path);
        self::assertDocument($collection, $id);
        return $collection;
    }

    public function summaries(): array
    {
        $directory = dirname($this->path('collection'));
        $summaries = [];
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $collection = $this->get(basename($path, '.json'));
            if ($collection === null) continue;
            $summaries[$collection['id']] = array_intersect_key($collection, array_flip(['id', 'title', 'editors', 'revision'])) + [
                'count' => count($collection['records']), 'published' => $collection['published']['at'] ?? null,
                'public_fields' => self::fieldLabels(self::publicFields($collection['fields'])),
                'published_fields' => self::fieldLabels($collection['published']['fields'] ?? []),
                'published_title' => $collection['published']['title'] ?? null,
            ];
        }
        uasort($summaries, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
        return $summaries;
    }

    public function saveSchema(string $id, string $revision, string $title, array $fields, bool $editors): array
    {
        $title = CollectionSchema::text($title, 120);
        if ($title === '') throw new InvalidArgumentException('Enter a collection name.');
        $fields = CollectionSchema::fields($fields);
        return $this->mutate($id, $revision, static function (array $collection) use ($id, $title, $fields, $editors): array {
            $collection += ['version' => 1, 'id' => $id, 'records' => [], 'published' => null, 'previous' => null];
            if (array_diff_key($collection['fields'] ?? [], $fields) !== []) throw new InvalidArgumentException('Existing field keys are permanent. To retire a field, make it optional and private.');
            $collection['title'] = $title;
            $collection['fields'] = $fields;
            $collection['editors'] = $editors;
            foreach ($collection['records'] as &$record) $record['values'] = CollectionSchema::row($fields, $record['values']);
            unset($record);
            CollectionSchema::unique($fields, $collection['records']);
            return $collection;
        });
    }

    public function saveRecord(string $id, string $revision, string $recordId, array $values): array
    {
        return $this->mutate($id, $revision, static function (array $collection) use ($recordId, $values): array {
            if ($recordId !== '' && !isset($collection['records'][$recordId])) throw new InvalidArgumentException('Record not found.');
            $key = $recordId !== '' ? $recordId : self::recordId();
            $collection['records'][$key] = ['values' => CollectionSchema::row($collection['fields'], $values), 'archived' => $collection['records'][$key]['archived'] ?? false];
            CollectionSchema::unique($collection['fields'], $collection['records']);
            return $collection;
        });
    }

    public function archive(string $id, string $revision, string $recordId, bool $archived): array
    {
        return $this->mutate($id, $revision, static function (array $collection) use ($recordId, $archived): array {
            if (!isset($collection['records'][$recordId])) throw new InvalidArgumentException('Record not found.');
            $collection['records'][$recordId]['archived'] = $archived;
            return $collection;
        });
    }

    public function applyBatch(string $id, string $revision, array $changes): array
    {
        return $this->mutate($id, $revision, static function (array $collection) use ($changes): array {
            $import = [];
            $before = self::publicationReview($collection);
            foreach ($changes as $change) {
                $key = $change['id'] ?? self::recordId();
                if ($change['id'] !== null && !isset($collection['records'][$key])) throw new InvalidArgumentException('A matching record is no longer available.');
                $collection['records'][$key] = ['values' => CollectionSchema::row($collection['fields'], $change['values']), 'archived' => $collection['records'][$key]['archived'] ?? false];
                if ($before['error'] === '' && !isset($before['changes'][$key])) {
                    $import[$key] = self::fingerprint($collection['records'][$key]);
                }
            }
            CollectionSchema::unique($collection['fields'], $collection['records']);
            if ($changes !== []) $collection['latest_import'] = $import;
            return $collection;
        });
    }

    public static function projection(array $collection): array
    {
        $fields = self::publicFields($collection['fields']);
        return ['title' => $collection['title'], 'fields' => $fields, 'rows' => array_values(self::draftRows($collection, $fields))];
    }

    public static function assertDraftCapacity(array $fields, array $records): void
    {
        if (count($records) > self::MAX_RECORDS || strlen(json_encode([$fields, $records], JSON_THROW_ON_ERROR)) > self::MAX_DRAFT_BYTES) {
            throw new InvalidArgumentException('The collection exceeds its record or storage limit.');
        }
    }

    public function publish(string $id, string $revision): array
    {
        return $this->mutate($id, $revision, static function (array $collection): array {
            $public = self::projection($collection);
            if ($public['fields'] === [] && $collection['published'] === null) throw new InvalidArgumentException('Mark at least one field public before publishing.');
            return self::publishSnapshot($collection, $public['fields'], self::draftRows($collection, $public['fields']));
        });
    }

    public static function publicationReview(array $collection): array
    {
        $fields = self::publicFields($collection['fields']);
        $published = self::identifySnapshot($collection['published'], $collection['records']);
        $error = match (true) {
            $fields === [] => 'Mark at least one field public to publish selected records.',
            $published !== null && ($published['fields'] !== $fields || $published['title'] !== $collection['title'])
                => 'The public fields or collection name changed. Use Publish entire draft to publish these changes.',
            $published !== null && !isset($published['record_ids'])
                => 'Some older published records could not be matched safely. Review the draft and use Publish entire draft to establish Record IDs.',
            default => '',
        };
        if ($error !== '') return ['error' => $error, 'fields' => $fields, 'changes' => [], 'latest' => []];

        $live = $published === null ? [] : array_combine($published['record_ids'], $published['rows']);
        $draft = self::draftRows($collection, $fields);
        $changes = $latest = [];
        foreach ($draft + $live as $id => $row) {
            $before = $live[$id] ?? null;
            $after = $draft[$id] ?? null;
            if ($before === $after) continue;
            $changes[$id] = ['before' => $before, 'after' => $after];
            if (isset($collection['latest_import'][$id]) && $collection['latest_import'][$id] === self::fingerprint($collection['records'][$id])) $latest[] = $id;
        }
        return ['error' => '', 'fields' => $fields, 'changes' => $changes, 'latest' => $latest];
    }

    public function publishRecords(string $id, string $revision, ?array $selection): array
    {
        return $this->mutate($id, $revision, static function (array $collection) use ($selection): array {
            $review = self::publicationReview($collection);
            if ($review['error'] !== '') throw new InvalidArgumentException($review['error']);
            $ids = $selection ?? $review['latest'];
            if ($ids === [] || count($ids) > self::MAX_RECORDS) throw new InvalidArgumentException('Choose pending records to publish.');
            $snapshot = $collection['published'];
            $rows = $snapshot === null ? [] : array_combine($snapshot['record_ids'], $snapshot['rows']);
            foreach ($ids as $id) {
                if (!is_string($id) || !isset($review['changes'][$id])) throw new InvalidArgumentException('Choose only pending records from the review.');
                $row = $review['changes'][$id]['after'];
                if ($row === null) unset($rows[$id]);
                else $rows[$id] = $row;
            }
            CollectionSchema::unique($review['fields'], array_map(static fn (array $values): array => ['values' => $values], $rows));
            return self::publishSnapshot($collection, $review['fields'], $rows);
        });
    }

    public function restore(string $id, string $revision): array
    {
        return $this->mutate($id, $revision, static function (array $collection): array {
            $previous = $collection['previous'];
            if ($previous === null) throw new InvalidArgumentException('There is no previous publication to restore.');
            $previous['fields'] = array_filter($previous['fields'], static fn (array $field, string $key): bool => ($collection['fields'][$key]['public'] ?? false) && $collection['fields'][$key]['type'] === $field['type'], ARRAY_FILTER_USE_BOTH);
            $previous['rows'] = array_map(static fn (array $row): array => array_intersect_key($row, $previous['fields']), $previous['rows']);
            $collection['previous'] = $collection['published'];
            $collection['published'] = $previous;
            $collection['published']['at'] = gmdate(DATE_ATOM);
            return $collection;
        });
    }

    private function mutate(string $id, string $revision, callable $change): array
    {
        return $this->files->mutateJson($this->path($id), static function (array $current) use ($id, $change, $revision): array {
            if ($current !== []) {
                self::assertDocument($current, $id);
                foreach (['published', 'previous'] as $key) $current[$key] = self::identifySnapshot($current[$key], $current['records']);
            }
            if (($current['revision'] ?? '') !== $revision) throw new CollectionConflict('This collection changed. Open the latest version before saving.');
            $next = $change($current);
            self::assertDraftCapacity($next['fields'], $next['records']);
            $next['revision'] = bin2hex(random_bytes(16));
            if (strlen(json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) + 1 > self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException('The collection and publication history exceed the storage limit.');
            }
            return $next;
        });
    }

    private function path(string $id): string
    {
        if (!CollectionSchema::key($id)) throw new InvalidArgumentException('Enter a lowercase collection key using letters, numbers, dashes, or underscores.');
        $path = $this->paths->contentPath('collections/' . $id . '.json');
        if (is_link(dirname($path)) || is_link($path) || is_link($path . '.lock')) throw new RuntimeException('Linked collection storage is unsupported.');
        return $path;
    }

    private static function assertDocument(array $collection, string $id): void
    {
        if (($collection['version'] ?? null) !== 1 || ($collection['id'] ?? null) !== $id || !is_string($collection['revision'] ?? null)
            || !is_string($collection['title'] ?? null) || !is_bool($collection['editors'] ?? null)
            || !is_array($collection['fields'] ?? null) || !is_array($collection['records'] ?? null)
            || !array_key_exists('published', $collection) || !array_key_exists('previous', $collection)) {
            throw new RuntimeException('Unsupported collection document.');
        }
    }

    private static function publicFields(array $fields): array
    {
        return array_filter($fields, static fn (array $field): bool => $field['public']);
    }

    private static function draftRows(array $collection, array $fields): array
    {
        $rows = [];
        foreach ($collection['records'] as $id => $record) {
            if (!$record['archived']) $rows[$id] = array_intersect_key($record['values'], $fields);
        }
        return $rows;
    }

    private static function identifySnapshot(?array $snapshot, array $records): ?array
    {
        if ($snapshot === null || isset($snapshot['record_ids'])) return $snapshot;
        $empty = array_fill_keys(array_keys($snapshot['fields']), null);
        $candidates = [];
        foreach ($records as $id => $record) {
            $values = array_replace($empty, array_intersect_key($record['values'], $empty));
            $candidates[self::fingerprint($values)][] = $id;
        }
        $ids = [];
        foreach ($snapshot['rows'] as $row) {
            $matches = $candidates[self::fingerprint(array_replace($empty, $row))] ?? [];
            if (count($matches) !== 1 || isset($ids[$matches[0]])) return $snapshot;
            $ids[$matches[0]] = true;
        }
        return $snapshot + ['record_ids' => array_keys($ids)];
    }

    private static function publishSnapshot(array $collection, array $fields, array $rows): array
    {
        $collection['previous'] = $collection['published'];
        $collection['published'] = ['title' => $collection['title'], 'fields' => $fields, 'rows' => array_values($rows),
            'record_ids' => array_keys($rows), 'at' => gmdate(DATE_ATOM), 'source_revision' => $collection['revision']];
        return $collection;
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private static function fieldLabels(array $fields): array
    {
        return array_map(static fn (array $field): string => $field['label'], $fields);
    }

    private static function recordId(): string { return 'rec_' . bin2hex(random_bytes(16)); }
}
