<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\WidgetRenderer;

final class WidgetRepository
{
    public function __construct(private readonly Paths $paths) {}

    public function load(): array
    {
        $raw = (new WebsiteDocumentStore($this->paths))->read('widgets');
        $widgets = array_values(array_filter((array)($raw['widgets'] ?? []), 'is_array'));
        if (!array_filter($widgets, static fn (array $widget): bool => ($widget['type'] ?? '') === 'recent_posts')) array_unshift($widgets, ['type' => 'recent_posts', 'title' => 'Recent posts', 'target' => 'all_sidebars']);
        return ['widgets' => $widgets, 'revision' => ContentRevision::for($raw)];
    }

    public function prepareSave(array $widgets, string $expectedRevision, bool $strict = true): array
    {
        $before = $this->load();
        if (!hash_equals($before['revision'], trim($expectedRevision, '" '))) throw new MenuConflictException('Widgets changed. Reload before saving.');
        return ['before' => ['widgets' => $before['widgets']], 'after' => ['widgets' => WidgetRenderer::normalizeList($widgets, $strict)], 'base_revision' => $before['revision']];
    }

    public function save(array $widgets, string $expectedRevision, ?string $operationId = null, bool $strict = true): array
    {
        $prepared = $this->prepareSave($widgets, $expectedRevision, $strict);
        return (new WebsiteDocumentStore($this->paths))->commit('widgets', $prepared['after'], $prepared['base_revision'], $operationId);
    }
}
