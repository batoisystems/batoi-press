<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\{CollectionConflict, CollectionRepository, CollectionSchema};
use Batoi\Press\Core\{FileStore, Paths};
use InvalidArgumentException;

final class CollectionImport
{
    private readonly string $path;

    public function __construct(Paths $paths, string $actor, private readonly FileStore $files = new FileStore())
    {
        $this->path = $paths->dataPath('tmp/collection-imports/' . hash('sha256', $actor) . '.json');
    }

    public function start(array $collection, string $bytes, string $format): array
    {
        $sheets = $format === 'xlsx' ? CollectionSpreadsheet::sheets($bytes) : [1 => 'Data'];
        $stage = ['collection' => $collection['id'], 'revision' => $collection['revision'], 'token' => bin2hex(random_bytes(16)),
            'expires' => time() + 1800, 'bytes' => base64_encode($bytes), 'format' => $format, 'sheets' => $sheets];
        return $this->files->mutateJson($this->path, static fn (): array => $stage);
    }

    public function read(array $collection, string $token): array
    {
        $stage = is_file($this->path) ? $this->files->readJson($this->path) : [];
        if (($stage['token'] ?? null) !== $token || ($stage['collection'] ?? null) !== $collection['id']) {
            throw new CollectionConflict('This import is no longer available. Upload the source again.');
        }
        if ($stage['expires'] < time()) {
            $this->clear($token);
            throw new CollectionConflict('This import has expired. Upload the source again.');
        }
        if ($stage['revision'] !== $collection['revision']) throw new CollectionConflict('The collection changed during import. Start a new preview to use the latest version.');
        return $stage;
    }

    public function prepare(array $stage, int $sheet, array $mapping, string $match): array
    {
        $token = $stage['token'];
        return $this->files->mutateJson($this->path, static function (array $current) use ($stage, $token, $sheet, $mapping, $match): array {
            if (($current['token'] ?? null) !== $token) throw new CollectionConflict('A newer import is already open.');
            return array_replace($stage, ['sheet' => $sheet, 'mapping' => $mapping, 'match' => $match, 'token' => bin2hex(random_bytes(16))]);
        });
    }

    public function clear(string $token): void
    {
        $this->files->mutateJson($this->path, static fn (array $stage): array => ($stage['token'] ?? null) === $token ? [] : $stage);
    }

    public static function table(array $stage, int $sheet): array
    {
        if (!isset($stage['sheets'][$sheet])) throw new InvalidArgumentException('Select a worksheet.');
        return CollectionSpreadsheet::read(base64_decode($stage['bytes'], true) ?: '', $stage['format'], $sheet);
    }

    public static function plan(array $collection, array $table, array $mapping, string $match): array
    {
        $fields = $collection['fields'];
        $records = $collection['records'];
        $allowed = ['_record_id' => true] + $fields;
        $columns = [];
        foreach ($mapping as $index => $key) {
            if ($key === '') continue;
            if (!is_string($key) || !isset($table['headers'][$index], $allowed[$key]) || isset($columns[$key])) {
                throw new InvalidArgumentException('Map each destination field to at most one source column.');
            }
            $columns[$key] = $index;
        }
        if (array_diff_key($columns, ['_record_id' => true]) === []) throw new InvalidArgumentException('Map at least one record field.');
        if ($match !== '' && (!isset($columns[$match]) || ($match !== '_record_id' && !($fields[$match]['unique'] ?? false)))) {
            throw new InvalidArgumentException('Map the chosen unique matching field or Record ID.');
        }
        $index = [];
        if ($match !== '' && $match !== '_record_id') {
            foreach ($records as $id => $record) {
                $key = $record['values'][$match];
                if ($key !== null) $index[$key] = $id;
            }
        }
        $changes = $errors = $matched = [];
        $adds = $updates = $unchanged = 0;
        foreach ($table['rows'] as $line => $row) {
            try {
                $input = [];
                foreach ($columns as $key => $column) {
                    $value = $row[$column] ?? null;
                    if ($value === false) throw new InvalidArgumentException('Replace spreadsheet errors or durations with plain values first.');
                    if ((is_int($value) || is_float($value)) && ($fields[$key]['type'] ?? null) === 'text' && $fields[$key]['unique']) {
                        throw new InvalidArgumentException('Format ' . $fields[$key]['label'] . ' as text in Excel to preserve leading zeros.');
                    }
                    $input[$key] = $value;
                }
                $id = null;
                if ($match === '_record_id') {
                    $id = trim((string)($input[$match] ?? '')) ?: null;
                    if ($id !== null && !isset($records[$id])) throw new InvalidArgumentException('The matching Record ID does not exist.');
                } elseif ($match !== '') {
                    $key = CollectionSchema::value($fields[$match], $input[$match]);
                    if ($key === null) throw new InvalidArgumentException('The matching value is blank.');
                    $id = $index[$key] ?? null;
                }
                if ($id !== null && isset($matched[$id])) throw new InvalidArgumentException('More than one source row matches this record.');
                $values = CollectionSchema::row($fields, $input + ($records[$id]['values'] ?? []));
                if ($id !== null) $matched[$id] = true;
                if ($id !== null && $values === $records[$id]['values']) { $unchanged++; continue; }
                $id === null ? $adds++ : $updates++;
                $changes[$line] = ['id' => $id, 'values' => $values];
            } catch (InvalidArgumentException $error) {
                $errors[$line] = $error->getMessage();
            }
        }
        self::checkUnique($fields, $records, $changes, $errors);
        if ($errors === [] && $changes !== []) {
            foreach ($changes as $line => $change) {
                $id = $change['id'] ?? str_pad('preview_' . $line, 36, '_');
                $records[$id] = ['values' => $change['values'], 'archived' => $records[$id]['archived'] ?? false];
            }
            try { CollectionRepository::assertDraftCapacity($fields, $records); }
            catch (InvalidArgumentException $error) { $errors[0] = $error->getMessage(); }
        }
        ksort($errors, SORT_NUMERIC);
        return compact('changes', 'errors', 'adds', 'updates', 'unchanged');
    }

    private static function checkUnique(array $fields, array $records, array $changes, array &$errors): void
    {
        foreach ($changes as $change) {
            if ($change['id'] !== null) unset($records[$change['id']]);
        }
        foreach ($fields as $key => $field) {
            if (!$field['unique']) continue;
            $seen = [];
            foreach ($records as $record) {
                $value = $record['values'][$key];
                if ($value !== null) $seen[$value] = 0;
            }
            foreach ($changes as $line => $change) {
                $value = $change['values'][$key];
                if ($value === null) continue;
                if (isset($seen[$value])) {
                    $errors[$line] = $field['label'] . ' duplicates an existing or imported value.';
                    if ($seen[$value] !== 0) $errors[$seen[$value]] = $errors[$line];
                } else $seen[$value] = $line;
            }
        }
    }
}
