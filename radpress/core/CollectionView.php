<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Content\{CollectionRepository, CollectionSchema};
use InvalidArgumentException;

final class CollectionView
{
    public const LAYOUTS = ['cards' => 'Cards', 'list' => 'List', 'table' => 'Table'];
    public const BLOCK_FIELDS = ['collection', 'collection_layout', 'collection_fields', 'collection_filter', 'collection_value', 'collection_sort', 'collection_order'];
    private array $snapshots = [];

    public function __construct(private readonly Paths $paths, private readonly array $site) {}

    public static function normalize(array $block): array
    {
        $id = trim((string)($block['collection'] ?? ''));
        $layout = trim((string)($block['collection_layout'] ?? 'cards')) ?: 'cards';
        $filter = trim((string)($block['collection_filter'] ?? ''));
        $sort = trim((string)($block['collection_sort'] ?? ''));
        $order = (string)($block['collection_order'] ?? 'asc') ?: 'asc';
        if (!CollectionSchema::key($id) || !CollectionSchema::key($layout) || ($filter !== '' && !CollectionSchema::key($filter))
            || ($sort !== '' && !CollectionSchema::key($sort)) || !in_array($order, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Choose a collection and valid view settings.');
        }
        $selected = preg_split('/\s*,\s*/', trim((string)($block['collection_fields'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($selected) > CollectionSchema::MAX_FIELDS) throw new InvalidArgumentException('Too many collection fields selected.');
        foreach ($selected as $field) if (!CollectionSchema::key($field)) throw new InvalidArgumentException('Choose valid collection field keys.');
        return array_combine(self::BLOCK_FIELDS, [$id, $layout, implode(',', array_unique($selected)), $filter,
            CollectionSchema::text((string)($block['collection_value'] ?? ''), CollectionSchema::MAX_VALUE), $sort, $order]);
    }

    public function render(array $block, ?array $preview = null): string
    {
        $id = (string)($block['collection'] ?? '');
        if ($preview === null) {
            if (!array_key_exists($id, $this->snapshots)) $this->snapshots[$id] = (new CollectionRepository($this->paths))->get($id)['published'] ?? null;
            $snapshot = $this->snapshots[$id];
        } else $snapshot = $preview;
        if ($snapshot === null) return '';
        $fields = $snapshot['fields'];
        $rows = $snapshot['rows'];
        $filter = (string)($block['collection_filter'] ?? '');
        if ($filter !== '') {
            if (!isset($fields[$filter])) return '';
            try { $wanted = CollectionSchema::value($fields[$filter], $block['collection_value'] ?? ''); }
            catch (InvalidArgumentException) { return ''; }
            $rows = array_values(array_filter($rows, static fn (array $row): bool => ($row[$filter] ?? null) === $wanted));
        }
        $sort = (string)($block['collection_sort'] ?? '');
        if (isset($fields[$sort])) {
            $direction = ($block['collection_order'] ?? 'asc') === 'desc' ? -1 : 1;
            $numeric = $fields[$sort]['type'] === 'number';
            usort($rows, static function (array $a, array $b) use ($sort, $direction, $numeric): int {
                $left = $a[$sort] ?? null;
                $right = $b[$sort] ?? null;
                if ($left === null || $right === null) return ($left === null) <=> ($right === null);
                return $direction * ($numeric ? self::compareNumbers($left, $right) : strnatcasecmp($left, $right));
            });
        }
        $selected = (string)($block['collection_fields'] ?? '');
        if ($selected !== '') {
            $ordered = [];
            foreach (explode(',', $selected) as $key) if (isset($fields[$key])) $ordered[$key] = $fields[$key];
            $fields = $ordered;
        }
        if ($fields === []) return '';
        $items = array_map(static fn (array $row): array => array_intersect_key($row, $fields), $rows);
        $labels = array_map(static fn (array $field): array => array_intersect_key($field, ['label' => true, 'type' => true]), $fields);
        return ThemePartial::renderCollection($this->paths, $this->site, (string)($block['collection_layout'] ?? 'cards'), [
            'title' => (string)($block['title'] ?? ''), 'fields' => $labels, 'items' => $items,
        ]);
    }

    private static function compareNumbers(string $left, string $right): int
    {
        $aNegative = $left[0] === '-';
        $bNegative = $right[0] === '-';
        if ($aNegative !== $bNegative) return $bNegative <=> $aNegative;
        $magnitude = static function (string $value): string {
            $parts = explode('.', ltrim($value, '-'), 2);
            return str_pad($parts[0], 12, '0', STR_PAD_LEFT) . str_pad($parts[1] ?? '', 8, '0');
        };
        return ($aNegative ? -1 : 1) * strcmp($magnitude($left), $magnitude($right));
    }
}
