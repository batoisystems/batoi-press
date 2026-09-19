<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use RuntimeException;

final class MenuRepository
{
    public const SCHEMA_VERSION = 2;
    public const MAX_ITEMS = 100;
    public const MAX_DEPTH = 4;
    public const MAX_MEGA_COLUMNS = 4;
    public const TYPES = ['link', 'page', 'post', 'archive', 'heading', 'separator'];
    public const PRESENTATIONS = ['link', 'dropdown', 'mega'];

    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files,
        private readonly ?\Closure $checkpoint = null
    ) {
    }

    public function load(string $name = 'main'): array
    {
        return $this->withLock($name, function () use ($name): array {
            $this->recoverOperation($name);
            $path = $this->path($name);
            return is_file($path) ? $this->normalizeLoaded($this->files->readJson($path), $name) : $this->emptyDocument($name);
        });
    }

    /** Validate a proposed menu without changing the live document or writing a snapshot. */
    public function prepareSave(array $document, string $actor, int $expectedRevision, string $name = 'main'): array
    {
        $current = $this->load($name);
        if ($expectedRevision !== (int)$current['revision']) {
            throw new MenuConflictException('This menu changed after the editor was opened. Reload it before saving again.');
        }
        return $this->normalizeForSave($document, $current, $actor, $name, $expectedRevision + 1);
    }

    public function save(array $document, string $actor, ?int $expectedRevision = null, string $name = 'main', ?string $operationId = null, ?string $expectedHash = null): array
    {
        return $this->withLock($name, function () use ($document, $actor, $expectedRevision, $name, $operationId, $expectedHash): array {
            $this->recoverOperation($name);
            $path = $this->path($name);
            $currentRaw = is_file($path) ? $this->files->readJson($path) : [];
            $current = $this->normalizeLoaded($currentRaw, $name);
            $currentRevision = (int)($current['revision'] ?? 0);
            if (($expectedRevision !== null && $expectedRevision !== $currentRevision)
                || ($expectedHash !== null && !hash_equals($expectedHash, \Batoi\Press\Application\ContentRevision::for($current)))) {
                throw new MenuConflictException('This menu changed after the editor was opened. Reload it before saving again.');
            }
            if ($operationId !== null && is_file($this->receiptPath($operationId))) throw new MenuConflictException('This menu operation already has a receipt.');
            $normalized = $this->normalizeForSave($document, $current, $actor, $name, $currentRevision + 1);
            if ($currentRaw !== []) $this->snapshot($name, $currentRaw, $currentRevision);
            if ($operationId !== null) {
                $journal = ['state' => 'pending', 'id' => $operationId, 'key' => $name,
                    'before_hash' => \Batoi\Press\Application\ContentRevision::for($currentRaw),
                    'after_hash' => \Batoi\Press\Application\ContentRevision::for($normalized),
                    'result' => ['id' => $normalized['id'], 'type' => 'menu', 'key' => $name, 'revision' => $normalized['revision']]];
                $this->files->writeJson($this->journalPath($name), $journal);
                @chmod($this->journalPath($name), 0600);
                if ($this->checkpoint !== null) ($this->checkpoint)('journal');
            }
            $this->atomicWrite($path, $normalized);
            if ($operationId !== null && $this->checkpoint !== null) ($this->checkpoint)('written');
            if ($operationId !== null) $this->recoverOperation($name);
            return $normalized;
        });
    }

    public function operationReceipt(string $name, string $id): ?array
    {
        return $this->withLock($name, function () use ($name, $id): array {
            $this->recoverOperation($name);
            $path = $this->receiptPath($id);
            $receipt = is_file($path) ? $this->files->readJson($path) : [];
            if ($receipt !== [] && ($receipt['key'] ?? '') !== $name) throw new RuntimeException('Menu receipt target mismatch.');
            return $receipt;
        }) ?: null;
    }

    private function recoverOperation(string $name): void
    {
        $journalPath = $this->journalPath($name);
        if (!is_file($journalPath)) return;
        $journal = $this->files->readJson($journalPath);
        if (($journal['state'] ?? '') !== 'pending') return;
        if (($journal['key'] ?? '') !== $name || !is_string($journal['before_hash'] ?? null) || !is_string($journal['after_hash'] ?? null)) throw new RuntimeException('Invalid menu recovery journal.');
        $path = $this->path($name);
        $current = is_file($path) ? $this->files->readJson($path) : [];
        $hash = \Batoi\Press\Application\ContentRevision::for($current);
        if (hash_equals($journal['after_hash'], $hash)) $journal['state'] = 'committed';
        elseif (hash_equals($journal['before_hash'], $hash)) $journal['state'] = 'not_applied';
        else throw new RuntimeException('Menu recovery requires operator review: unexpected external changes.');
        $receiptPath = $this->receiptPath($journal['id']);
        $this->files->writeJson($receiptPath, $journal);
        @chmod($receiptPath, 0600);
        $this->files->writeJson($journalPath, $journal);
    }

    private function journalPath(string $name): string
    {
        $this->path($name);
        return $this->paths->dataPath('integrations/menu-transactions/' . $name . '.json');
    }

    private function receiptPath(string $id): string
    {
        if (!preg_match('/^proposal_[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid menu operation ID.');
        return $this->paths->dataPath('integrations/menu-transactions/receipts/' . $id . '.json');
    }

    private function withLock(string $name, callable $callback): array
    {
        $path = $this->path($name);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create menu storage directory.');
        }

        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock menu storage.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function legacyLines(array $document): string
    {
        return implode("\n", array_map(static function (array $item): string {
            $line = (string)($item['label'] ?? '') . '|' . (string)($item['url'] ?? '');
            return !empty($item['parent']) ? $line . '|' . (string)$item['parent'] : $line;
        }, (array)($document['items'] ?? [])));
    }

    public function importLegacyLines(string $lines, string $name = 'main'): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                $items[] = [
                    'label' => $parts[0],
                    'url' => $parts[1],
                    'parent' => (string)($parts[2] ?? ''),
                ];
            }
        }
        return $this->migrateLegacy(['items' => $items], $name);
    }

    private function normalizeLoaded(array $document, string $name): array
    {
        if ((int)($document['schema_version'] ?? 0) >= self::SCHEMA_VERSION) {
            $items = array_values(array_filter((array)($document['items'] ?? []), 'is_array'));
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'id' => $this->validMenuId((string)($document['id'] ?? '')) ?: 'menu_' . $name,
                'name' => $this->bounded((string)($document['name'] ?? $this->defaultLabel($name)), 80),
                'location' => $this->location((string)($document['location'] ?? $this->defaultLocation($name))),
                'revision' => max(0, (int)($document['revision'] ?? 0)),
                'updated_at' => (string)($document['updated_at'] ?? ''),
                'updated_by' => (string)($document['updated_by'] ?? ''),
                'items' => $items,
            ];
        }

        return $this->migrateLegacy($document, $name);
    }

    private function migrateLegacy(array $document, string $name): array
    {
        $legacyItems = array_values(array_filter((array)($document['items'] ?? []), 'is_array'));
        $items = [];
        $urlIds = [];
        foreach ($legacyItems as $index => $legacy) {
            $label = $this->bounded(trim((string)($legacy['label'] ?? '')), 120);
            $url = trim((string)($legacy['url'] ?? ''));
            if ($label === '' || !$this->safeUrl($url)) {
                continue;
            }
            $id = 'mi_' . substr(hash('sha256', $name . '|' . $index . '|' . $url), 0, 16);
            $urlIds[$url] ??= $id;
            $items[] = [
                'id' => $id,
                'type' => 'link',
                'label' => $label,
                'url' => $url,
                'parent_id' => null,
                'parent' => trim((string)($legacy['parent'] ?? '')),
                'presentation' => 'link',
                'description' => '',
                'column' => 1,
                'target' => '_self',
                'enabled' => true,
            ];
        }

        $hasChildren = [];
        foreach ($items as $index => $item) {
            $parentUrl = (string)($item['parent'] ?? '');
            $parentId = $parentUrl !== '' ? ($urlIds[$parentUrl] ?? null) : null;
            if ($parentId === $item['id']) {
                $parentId = null;
            }
            $items[$index]['parent_id'] = $parentId;
            if ($parentId !== null) {
                $hasChildren[$parentId] = true;
            }
        }
        foreach ($items as $index => $item) {
            if (isset($hasChildren[$item['id']]) && $item['parent_id'] === null) {
                $items[$index]['presentation'] = 'dropdown';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => 'menu_' . $name,
            'name' => $this->defaultLabel($name),
            'location' => $this->defaultLocation($name),
            'revision' => max(0, (int)($document['revision'] ?? 0)),
            'updated_at' => (string)($document['updated_at'] ?? ''),
            'updated_by' => (string)($document['updated_by'] ?? ''),
            'items' => $items,
        ];
    }

    private function normalizeForSave(array $document, array $current, string $actor, string $name, int $revision): array
    {
        $submitted = array_values(array_filter((array)($document['items'] ?? []), 'is_array'));
        if (count($submitted) > self::MAX_ITEMS) {
            throw new MenuValidationException('The menu contains too many items.', ['items' => 'Use no more than ' . self::MAX_ITEMS . ' items.']);
        }

        $errors = [];
        $items = [];
        $ids = [];
        foreach ($submitted as $index => $item) {
            $id = trim((string)($item['id'] ?? ''));
            if ($id === '') {
                $id = 'mi_' . bin2hex(random_bytes(8));
            }
            if (!$this->validItemId($id)) {
                $errors[$id !== '' ? $id : 'item_' . $index] = 'The item ID is invalid.';
                continue;
            }
            if (isset($ids[$id])) {
                $errors[$id] = 'The item ID is duplicated.';
                continue;
            }
            $ids[$id] = true;

            $type = strtolower(trim((string)($item['type'] ?? 'link')));
            if (!in_array($type, self::TYPES, true)) {
                $errors[$id] = 'Select a supported item type.';
                continue;
            }
            $label = $this->bounded(trim((string)($item['label'] ?? '')), 120);
            $url = trim((string)($item['url'] ?? ''));
            if ($type !== 'separator' && $label === '') {
                $errors[$id] = 'Enter a menu label.';
            }
            if (!in_array($type, ['heading', 'separator'], true) && !$this->safeUrl($url)) {
                $errors[$id] = 'Enter a safe internal, HTTP(S), mail, telephone, or fragment URL.';
            }
            if (in_array($type, ['heading', 'separator'], true)) {
                $url = '';
            }

            $presentation = strtolower(trim((string)($item['presentation'] ?? 'link')));
            if (!in_array($presentation, self::PRESENTATIONS, true)) {
                $presentation = 'link';
            }
            $target = (string)($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
            $items[] = [
                'id' => $id,
                'type' => $type,
                'label' => $label,
                'url' => $url,
                'parent_id' => $this->nullableId($item['parent_id'] ?? null),
                'parent' => '',
                'presentation' => $presentation,
                'description' => $this->bounded(trim((string)($item['description'] ?? '')), 180),
                'column' => max(1, min(self::MAX_MEGA_COLUMNS, (int)($item['column'] ?? 1))),
                'target' => $target,
                'enabled' => $this->boolean($item['enabled'] ?? false),
            ];
        }

        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }
        foreach ($items as $index => $item) {
            $parentId = $item['parent_id'];
            if ($parentId !== null && (!isset($byId[$parentId]) || $parentId === $item['id'])) {
                $errors[$item['id']] = 'Select a valid parent item.';
                continue;
            }
            if ($parentId !== null && $item['presentation'] !== 'link') {
                $items[$index]['presentation'] = 'link';
            }
        }

        $parentMap = [];
        foreach ($items as $item) {
            $parentMap[$item['id']] = $item['parent_id'];
        }
        foreach ($items as $item) {
            $trail = [];
            $cursor = $item['id'];
            $depth = 0;
            while ($cursor !== null) {
                if (isset($trail[$cursor])) {
                    $errors[$item['id']] = 'Menu hierarchy cannot contain a cycle.';
                    break;
                }
                $trail[$cursor] = true;
                $cursor = $parentMap[$cursor] ?? null;
                if ($cursor !== null && ++$depth >= self::MAX_DEPTH) {
                    $errors[$item['id']] = 'Menu items may be nested no more than ' . self::MAX_DEPTH . ' levels.';
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw new MenuValidationException('Review the highlighted menu items and try again.', $errors);
        }

        $urlById = [];
        $hasChildren = [];
        foreach ($items as $item) {
            $urlById[$item['id']] = $item['url'];
            if ($item['parent_id'] !== null) {
                $hasChildren[$item['parent_id']] = true;
            }
        }
        foreach ($items as $index => $item) {
            $parentId = $item['parent_id'];
            $items[$index]['parent'] = $parentId !== null ? (string)($urlById[$parentId] ?? '') : '';
            if ($parentId === null && isset($hasChildren[$item['id']]) && $item['presentation'] === 'link') {
                $items[$index]['presentation'] = 'dropdown';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $this->validMenuId((string)($document['id'] ?? '')) ?: (string)($current['id'] ?? 'menu_' . $name),
            'name' => $this->bounded(trim((string)($document['name'] ?? $current['name'] ?? $this->defaultLabel($name))), 80) ?: $this->defaultLabel($name),
            'location' => $this->location((string)($document['location'] ?? $current['location'] ?? $this->defaultLocation($name))),
            'revision' => $revision,
            'updated_at' => date(DATE_ATOM),
            'updated_by' => $this->bounded(trim($actor), 120),
            'items' => $items,
        ];
    }

    private function emptyDocument(string $name): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => 'menu_' . $name,
            'name' => $this->defaultLabel($name),
            'location' => $this->defaultLocation($name),
            'revision' => 0,
            'updated_at' => '',
            'updated_by' => '',
            'items' => [],
        ];
    }

    private function snapshot(string $name, array $document, int $revision): void
    {
        $target = $this->paths->dataPath('versions/menus/' . $name . '/' . date('Ymd-His') . '-r' . $revision . '-' . bin2hex(random_bytes(3)) . '.json');
        $this->files->writeJson($target, $document);
    }

    private function atomicWrite(string $path, array $document): void
    {
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode menu data.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish menu data.');
        }
    }

    private function path(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new RuntimeException('Invalid menu name.');
        }
        return $this->paths->contentPath('menus/' . $name . '.json');
    }

    private function safeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $url) !== 1;
        }
        if (str_starts_with($url, '#')) {
            return preg_match('/^#[A-Za-z][A-Za-z0-9_:.-]*$/D', $url) === 1;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }
        if (str_starts_with(strtolower($url), 'mailto:')) {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        return preg_match('/^tel:\+?[0-9(). -]{3,32}$/Di', $url) === 1;
    }

    private function validItemId(string $id): bool
    {
        return preg_match('/^mi_[A-Za-z0-9_-]{3,64}$/D', $id) === 1;
    }

    private function validMenuId(string $id): bool
    {
        return preg_match('/^menu_[A-Za-z0-9_-]{1,64}$/D', $id) === 1;
    }

    private function nullableId(mixed $id): ?string
    {
        $id = trim((string)$id);
        return $id === '' ? null : $id;
    }

    private function location(string $location): string
    {
        $location = strtolower(trim($location));
        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $location) === 1 ? $location : 'primary';
    }

    private function defaultLocation(string $name): string
    {
        return $name === 'main' ? 'primary' : $this->location($name);
    }

    private function defaultLabel(string $name): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $this->defaultLocation($name))) . ' navigation';
    }

    private function bounded(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        $value = substr($value, 0, $bytes);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
