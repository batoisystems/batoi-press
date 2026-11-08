<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Slug;
use Batoi\Press\Core\Money;
use Batoi\Press\Application\ContentRevision;
use RuntimeException;

final class ProductRepository
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files, private readonly HtmlContent $html)
    {
    }

    public function all(): array
    {
        return (new ContentTransaction($this->paths, $this->files))->read('product', fn(): array => $this->loadAll());
    }

    private function loadAll(bool $withBody = true): array
    {
        $base = $this->paths->contentPath('products');
        $items = [];
        foreach (is_dir($base) ? (glob($base . '/*', GLOB_ONLYDIR) ?: []) : [] as $dir) {
            if (!is_file($dir . '/meta.json') || !is_file($dir . '/body.html')) continue;
            $item = $this->files->readJson($dir . '/meta.json');
            if ($withBody) $item['body'] = $this->html->sanitize($this->files->read($dir . '/body.html'));
            $items[] = $item;
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
        return $items;
    }

    public function metadata(): array
    {
        return (new ContentTransaction($this->paths, $this->files))->read('product', fn(): array => $this->loadAll(false));
    }

    public function published(): array
    {
        return array_values(array_filter($this->all(), static fn(array $product): bool => ($product['status'] ?? 'draft') === 'published'));
    }

    public function findBySlug(string $slug): ?array
    {
        if ($slug === '' || Slug::normalize($slug) !== $slug || strlen($slug) > 240) return null;
        $result = (new ContentTransaction($this->paths, $this->files))->read('product', function () use ($slug): array {
            $dir = $this->paths->contentPath('products/' . $slug);
            if (is_link($dir) || is_link($dir . '/meta.json') || is_link($dir . '/body.html')) throw new RuntimeException('Symlinked products are unsupported.');
            if (!is_file($dir . '/meta.json') || !is_file($dir . '/body.html')) return [];
            $record = $this->files->readJson($dir . '/meta.json');
            if (($record['slug'] ?? '') !== $slug) return [];
            $record['body'] = $this->html->sanitize($this->files->read($dir . '/body.html'));
            return $record;
        });
        return $result === [] ? null : $result;
    }

    public function save(array $input, string $actor): array
    {
        $prepared = $this->prepareSave($input, $actor);
        $meta = (new ContentTransaction($this->paths, $this->files))->commit('product', $prepared);
        return $meta + ['body' => $prepared['body']];
    }

    public function prepareSave(array $input, string $actor): array
    {
        $slug = Slug::normalize(trim((string)($input['slug'] ?? '')) ?: (string)($input['title'] ?? ''));
        if ($slug === '') $slug = 'product-' . date('Ymd-His');
        $originalSlug = Slug::normalize((string)($input['original_slug'] ?? ''));
        $existing = $originalSlug !== '' ? $this->findBySlug($originalSlug) : $this->findBySlug($slug);
        if ($originalSlug === '' && $existing !== null) throw new RuntimeException('A product with this slug already exists.');
        if ($originalSlug !== '' && $existing === null) throw new RuntimeException('The product being edited no longer exists.');
        if ($existing !== null && (!is_string($input['expected_revision'] ?? null) || !hash_equals(ContentRevision::for($existing), $input['expected_revision']))) throw new RuntimeException('Product changed. Reload it before saving; your changes have not been applied.');
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->findBySlug($slug) !== null) throw new RuntimeException('A product with this slug already exists.');
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Product title is required.');
        if (strlen($title) > 200 || strlen((string)($input['body'] ?? '')) > 1048576) throw new RuntimeException('Product title or description exceeds its allowed size.');
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        $minor = Money::parse(trim((string)($input['price'] ?? '0')), $currency);
        $sku = trim((string)($input['sku'] ?? ''));
        if ($sku !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $sku) !== 1) throw new RuntimeException('SKU must use letters, numbers, dots, underscores or hyphens (80 characters maximum).');
        $inventory = (string)($input['inventory'] ?? '0');
        if (preg_match('/^(0|[1-9][0-9]{0,8})$/D', $inventory) !== 1) throw new RuntimeException('Inventory must be a non-negative whole number (up to 999999999).');
        $image = trim((string)($input['image'] ?? ''));
        if ($image !== '' && !(str_starts_with($image, '/') && !str_starts_with($image, '//')) && !(filter_var($image, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $image))) $image = '';
        $now = date(DATE_ATOM);
        $meta = [
            'id' => (string)($existing['id'] ?? 'prd_' . bin2hex(random_bytes(6))),
            'type' => 'product', 'title' => $title, 'slug' => $slug,
            'sku' => $sku,
            'category' => substr(trim((string)($input['category'] ?? 'General')), 0, 100),
            'status' => ($input['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'price' => Money::format($minor, $currency), 'price_minor' => $minor, 'currency' => $currency, 'currency_exponent' => Money::exponent($currency), 'schema' => 2,
            'inventory' => (int)$inventory,
            'image' => $image, 'image_alt' => substr(trim((string)($input['image_alt'] ?? '')), 0, 300),
            'author' => (string)($existing['author'] ?? $actor),
            'created_at' => (string)($existing['created_at'] ?? $now), 'updated_at' => $now,
        ];
        // Preserve additive site/plugin metadata when upgrading legacy records.
        $meta += array_diff_key($existing ?? [], ['body'=>true]);
        return ['meta' => $meta, 'body' => $this->html->sanitize((string)($input['body'] ?? '')), 'original_slug' => $originalSlug, 'base_revision' => $existing === null ? null : ContentRevision::for($existing)];
    }
}
