<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Core\{AuditLog, Config, Money, Request, Response};
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class ProductController
{
    public function __construct(private readonly Config $config, private readonly ProductRepository $products, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user) {}

    public function index(Request $request): Response
    {
        $q = substr($request->input('q'), 0, 160);
        $status = in_array($request->input('status'), ['draft','published'], true) ? $request->input('status') : '';
        $items = array_values(array_filter($this->products->metadata(), static fn(array $p): bool => ($status === '' || ($p['status'] ?? '') === $status) && ($q === '' || stripos(implode(' ', [(string)($p['title'] ?? ''), (string)($p['sku'] ?? ''), (string)($p['category'] ?? '')]), $q) !== false)));
        $total = count($items);
        $pages = max(1, (int)ceil($total / 25));
        $page = max(1, min($pages, (int)$request->input('page', '1')));
        $body = AdminLayout::pageHeader('Products', 'Manage catalogue details, exact prices and available stock.', AdminLayout::buttonLink('Create Product', '/admin/products/new', 'plus'));
        $body .= '<form method="get" action="/admin/products" class="bp-filter-form bp-form"><label>Search products <input type="search" name="q" maxlength="160" value="' . self::e($q) . '" placeholder="Title, SKU or category"></label><label>Status <select name="status"><option value="">All statuses</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Published</option></select></label><button type="submit" class="bp-button">Filter</button>' . AdminLayout::buttonLink('Reset', '/admin/products', 'refresh', true) . '</form>';
        $body .= '<p class="bp-muted" role="status">' . $total . ' matching product' . ($total === 1 ? '' : 's') . ' · Page ' . $page . ' of ' . $pages . '</p>';
        if ($items === []) $body .= '<section class="bp-empty-state"><h2>No matching products</h2><p>Create a product or adjust your search and filters.</p></section>';
        else {
            $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><caption class="uif-sr-only">Product catalogue</caption><thead><tr><th scope="col">Product</th><th scope="col">Status</th><th scope="col">SKU</th><th scope="col">Price</th><th scope="col">Inventory</th><th scope="col">Actions</th></tr></thead><tbody>';
            foreach (array_slice($items, ($page - 1) * 25, 25) as $item) {
                $slug = rawurlencode((string)$item['slug']);
                $view = ($item['status'] ?? '') === 'published' ? AdminLayout::buttonLink('View', '/product/' . $slug, 'site', true) : '';
                $body .= '<tr><td><strong>' . self::e((string)$item['title']) . '</strong><small>' . self::e((string)($item['category'] ?? 'General')) . '</small></td><td><span class="bp-status">' . self::e(ucfirst((string)($item['status'] ?? 'draft'))) . '</span></td><td><code>' . self::e((string)($item['sku'] ?? '')) . '</code></td><td>' . self::e((string)($item['currency'] ?? 'USD') . ' ' . (string)($item['price'] ?? '0.00')) . '</td><td>' . (int)($item['inventory'] ?? 0) . '</td><td><div class="bp-table-actions">' . $view . AdminLayout::buttonLink('Edit', '/admin/products/edit/' . $slug, 'edit', true) . '</div></td></tr>';
            }
            $body .= '</tbody></table></div><nav aria-label="Product pagination" class="bp-pagination">';
            foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $number) {
                if ($number < 1 || $number > $pages) continue;
                $body .= AdminLayout::buttonLink($label, '/admin/products?' . http_build_query(['q'=>$q, 'status'=>$status, 'page'=>$number]), $label === 'Next' ? 'plus' : 'back', true);
            }
            $body .= '</nav>';
        }
        return Response::html(AdminLayout::render('Products', $body));
    }

    public function edit(?string $slug = null): Response
    {
        $product = $slug !== null ? $this->products->findBySlug($slug) : null;
        if ($slug !== null && $product === null) return Response::html(AdminLayout::message('Products', 'Product not found.', true), 404);
        if ($product !== null) $product['expected_revision'] = ContentRevision::for($product);
        return Response::html($this->form($product ?? []));
    }

    private function form(array $product, string $error = ''): string
    {
        $editing = ($product['original_slug'] ?? $product['slug'] ?? '') !== '' && ($product['expected_revision'] ?? '') !== '';
        $title = $editing ? 'Edit Product' : 'Create Product';
        $body = AdminLayout::pageHeader($title, 'Maintain catalogue details and the public description.', AdminLayout::buttonLink('Back to products', '/admin/products', 'back', true));
        if ($error !== '') $body .= '<div class="bp-error" role="alert"><h2>Product was not saved</h2><p>' . self::e($error) . '</p><p>Your submitted values are retained below.</p></div>';
        $body .= '<form method="post" action="/admin/products/save" class="bp-form bp-admin-editor">' . $this->csrf->field() . '<input type="hidden" name="original_slug" value="' . self::e((string)($product['original_slug'] ?? ($editing ? $product['slug'] : ''))) . '"><input type="hidden" name="expected_revision" value="' . self::e((string)($product['expected_revision'] ?? '')) . '"><div class="bp-editor-main">';
        $body .= AdminLayout::section('Product content', '<div class="bp-form-grid">' . $this->input('Title', 'title', $product, true, 'data-bp-slug-source') . $this->input('Slug', 'slug', $product, true, 'data-bp-slug-target') . $this->input('SKU', 'sku', $product) . $this->input('Category', 'category', $product + ['category'=>'General']) . ContentEditor::render($this->config, (string)($product['body'] ?? ''), 'Describe the product using clean HTML.', 'bp-product-body') . '</div>');
        $currencies = '';
        $currency = (string)($product['currency'] ?? 'USD');
        foreach (Money::CURRENCIES as $code => $exponent) $currencies .= '<option value="' . $code . '"' . ($currency === $code ? ' selected' : '') . '>' . $code . '</option>';
        if (!isset(Money::CURRENCIES[$currency])) $currencies = '<option selected value="' . self::e($currency) . '">' . self::e($currency) . ' (unsupported; select a currency)</option>' . $currencies;
        $body .= '</div><aside class="bp-editor-side">' . AdminLayout::section('Publishing and pricing', '<label>Status <select name="status"><option value="draft"' . (($product['status'] ?? 'draft') === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . (($product['status'] ?? '') === 'published' ? ' selected' : '') . '>Published</option></select></label>' . $this->input('Price', 'price', $product + ['price'=>'0.00'], true, 'inputmode="decimal"') . '<label>Currency <select name="currency">' . $currencies . '</select></label><label>Inventory <input type="number" name="inventory" min="0" max="999999999" value="' . self::e((string)($product['inventory'] ?? 0)) . '" required></label><p class="bp-field-help">Use the decimal precision for the selected currency. Prices are stored exactly.</p>') . AdminLayout::section('Image', $this->input('Image URL', 'image', $product) . $this->input('Image alt text', 'image_alt', $product)) . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/products', 'back', true) . AdminLayout::submitButton('Save Product', 'save') . '</div></form>';
        return AdminLayout::render($title, $body);
    }

    public function save(Request $request): Response
    {
        if ($request->method !== 'POST') return Response::html('POST required.', 405)->withHeader('Allow', 'POST');
        if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('Products', 'Security token expired.', true), 400);
        $input = [];
        foreach (['original_slug','expected_revision','title','slug','sku','category','body','status','price','currency','inventory','image','image_alt'] as $key) $input[$key] = $request->input($key);
        try { $saved = $this->products->save($input, (string)($this->user['username'] ?? 'admin')); }
        catch (RuntimeException $exception) { return Response::html($this->form($input, $exception->getMessage()), str_starts_with($exception->getMessage(), 'Product changed') ? 409 : 422); }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'product.saved', (string)$saved['slug'], (string)($request->server['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/products');
    }

    private function input(string $label, string $name, array $record, bool $required = false, string $attributes = ''): string
    {
        return '<label>' . self::e($label) . ' <input type="text" name="' . $name . '" value="' . self::e((string)($record[$name] ?? '')) . '"' . ($required ? ' required' : '') . ($attributes !== '' ? ' ' . $attributes : '') . '></label>';
    }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
