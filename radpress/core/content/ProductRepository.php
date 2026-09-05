<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Slug;
use RuntimeException;

final class ProductRepository
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files, private readonly HtmlContent $html)
    {
    }

    public function all(): array
    {
        $base = $this->paths->contentPath('products');
        $items = [];
        foreach (is_dir($base) ? (glob($base . '/*', GLOB_ONLYDIR) ?: []) : [] as $dir) {
            if (!is_file($dir . '/meta.json') || !is_file($dir . '/body.html')) continue;
            $item = $this->files->readJson($dir . '/meta.json');
            $item['body'] = $this->html->sanitize($this->files->read($dir . '/body.html'));
            $items[] = $item;
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
        return $items;
    }

    public function published(): array
    {
        return array_values(array_filter($this->all(), static fn(array $product): bool => ($product['status'] ?? 'draft') === 'published'));
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->all() as $product) if (($product['slug'] ?? '') === $slug) return $product;
        return null;
    }

    public function save(array $input, string $actor): array
    {
        $slug = Slug::normalize(trim((string)($input['slug'] ?? '')) ?: (string)($input['title'] ?? ''));
        if ($slug === '') $slug = 'product-' . date('Ymd-His');
        $originalSlug = Slug::normalize((string)($input['original_slug'] ?? ''));
        $existing = $originalSlug !== '' ? $this->findBySlug($originalSlug) : $this->findBySlug($slug);
        if ($originalSlug === '' && $existing !== null) throw new RuntimeException('A product with this slug already exists.');
        if ($originalSlug !== '' && $existing === null) throw new RuntimeException('The product being edited no longer exists.');
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->findBySlug($slug) !== null) throw new RuntimeException('A product with this slug already exists.');
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Product title is required.');
        if (strlen($title) > 200 || strlen((string)($input['body'] ?? '')) > 1048576) throw new RuntimeException('Product title or description exceeds its allowed size.');
        $price = trim((string)($input['price'] ?? '0'));
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $price)) throw new RuntimeException('Enter a valid non-negative price with up to two decimal places.');
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) throw new RuntimeException('Currency must be a three-letter code.');
        $image = trim((string)($input['image'] ?? ''));
        if ($image !== '' && !(str_starts_with($image, '/') && !str_starts_with($image, '//')) && !(filter_var($image, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $image))) $image = '';
        $now = date(DATE_ATOM);
        $meta = [
            'id' => (string)($existing['id'] ?? 'prd_' . bin2hex(random_bytes(6))),
            'type' => 'product', 'title' => $title, 'slug' => $slug,
            'sku' => substr(trim((string)($input['sku'] ?? '')), 0, 80),
            'category' => substr(trim((string)($input['category'] ?? 'General')), 0, 100),
            'status' => ($input['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'price' => number_format((float)$price, 2, '.', ''), 'currency' => $currency,
            'inventory' => max(0, min(999999999, (int)($input['inventory'] ?? 0))),
            'image' => $image, 'image_alt' => substr(trim((string)($input['image_alt'] ?? '')), 0, 300),
            'author' => (string)($existing['author'] ?? $actor),
            'created_at' => (string)($existing['created_at'] ?? $now), 'updated_at' => $now,
        ];
        $dir = $this->paths->contentPath('products/' . $slug);
        if ($originalSlug !== '' && $originalSlug !== $slug) {
            $source = $this->paths->contentPath('products/' . $originalSlug);
            if (is_dir($source) && !rename($source, $dir)) throw new RuntimeException('Unable to rename product content directory.');
        }
        $this->files->writeJson($dir . '/meta.json', $meta);
        $this->files->write($dir . '/body.html', $this->html->sanitize((string)($input['body'] ?? '')));
        return $meta + ['body' => $this->html->sanitize((string)($input['body'] ?? ''))];
    }
}
