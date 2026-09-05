<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Slug;
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class ProductController
{
    public function __construct(private readonly Config $config, private readonly ProductRepository $products, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user)
    {
    }

    public function index(): Response
    {
        $body = AdminLayout::pageHeader('Products', 'Manage the flat-file product catalogue, pricing, inventory, and publication state.', AdminLayout::buttonLink('Create Product', '/admin/products/new', 'plus'));
        $items = $this->products->all();
        if ($items === []) $body .= '<section class="bp-empty-state"><h2>No products yet</h2><p>Create the first product for the public shop.</p></section>';
        else {
            $body .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Product</th><th>Status</th><th>SKU</th><th>Price</th><th>Inventory</th><th>Actions</th></tr></thead><tbody>';
            foreach ($items as $item) {
                $slug = (string)($item['slug'] ?? '');
                $body .= '<tr><td><strong>' . $this->e((string)($item['title'] ?? 'Untitled')) . '</strong><small>' . $this->e((string)($item['category'] ?? 'General')) . '</small></td><td><span class="bp-status">' . $this->e(ucfirst((string)($item['status'] ?? 'draft'))) . '</span></td><td><code>' . $this->e((string)($item['sku'] ?? '')) . '</code></td><td>' . $this->e((string)($item['currency'] ?? 'USD') . ' ' . (string)($item['price'] ?? '0.00')) . '</td><td>' . (int)($item['inventory'] ?? 0) . '</td><td><div class="bp-table-actions"><a href="/product/' . rawurlencode($slug) . '">View</a><a href="/admin/products/edit/' . rawurlencode($slug) . '">Edit</a></div></td></tr>';
            }
            $body .= '</tbody></table></div>';
        }
        return Response::html(AdminLayout::render('Products', $body));
    }

    public function edit(?string $slug = null): Response
    {
        $product = $slug !== null ? $this->products->findBySlug($slug) : null;
        $body = AdminLayout::pageHeader($product ? 'Edit Product' : 'Create Product', 'Maintain catalogue details and the public product description.', AdminLayout::buttonLink('Back to products', '/admin/products', 'back', true));
        $body .= '<form method="post" action="/admin/products/save" class="bp-form bp-admin-editor">' . $this->csrf->field() . '<input type="hidden" name="original_slug" value="' . $this->e((string)($product['slug'] ?? '')) . '"><div class="bp-editor-main">';
        $body .= AdminLayout::section('Product content', '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($product['title'] ?? ''), true, 'data-bp-slug-source') . $this->input('Slug', 'slug', (string)($product['slug'] ?? ''), true, 'data-bp-slug-target') . $this->input('SKU', 'sku', (string)($product['sku'] ?? ''), false) . $this->input('Category', 'category', (string)($product['category'] ?? 'General'), true) . ContentEditor::render($this->config, (string)($product['body'] ?? ''), 'Describe the product using clean HTML.', 'bp-product-body') . '</div>');
        $body .= '</div><aside class="bp-editor-side">' . AdminLayout::section('Commerce', '<label>Status <select name="status"><option value="draft"' . (($product['status'] ?? 'draft') === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . (($product['status'] ?? '') === 'published' ? ' selected' : '') . '>Published</option></select></label>' . $this->input('Price', 'price', (string)($product['price'] ?? '0.00'), true) . $this->input('Currency', 'currency', (string)($product['currency'] ?? 'USD'), true) . '<label>Inventory <input type="number" name="inventory" min="0" max="999999999" value="' . (int)($product['inventory'] ?? 0) . '" required></label>') . AdminLayout::section('Image', $this->input('Image URL', 'image', (string)($product['image'] ?? ''), false) . $this->input('Image alt text', 'image_alt', (string)($product['image_alt'] ?? ''), false)) . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/products', 'back', true) . AdminLayout::submitButton('Save Product', 'save') . '</div></form>';
        return Response::html(AdminLayout::render($product ? 'Edit Product' : 'Create Product', $body));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('Products', 'Security token expired.', true), 400);
        try { $saved = $this->products->save($request->post, (string)($this->user['username'] ?? 'admin')); }
        catch (RuntimeException $exception) { return Response::html(AdminLayout::message('Products', $exception->getMessage(), true), 422); }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'product.saved', (string)$saved['slug'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/products');
    }

    private function input(string $label, string $name, string $value, bool $required, string $attributes = ''): string
    {
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . ($required ? ' required' : '') . ($attributes !== '' ? ' ' . $attributes : '') . '></label>';
    }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
