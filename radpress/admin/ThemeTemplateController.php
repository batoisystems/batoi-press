<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Theme;
use Batoi\Press\Core\ThemeManager;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\UploadGuard;
use RuntimeException;
use ZipArchive;

final class ThemeTemplateController
{
    private const EDITABLE_FILES = [
        'header' => ['label' => 'Public Header', 'file' => 'partials/header.php', 'type' => 'php', 'description' => 'Public header, brand link, and main navigation markup.'],
        'footer' => ['label' => 'Public Footer', 'file' => 'partials/footer.php', 'type' => 'php', 'description' => 'Public footer and shared closing page content.'],
        'base' => ['label' => 'Base Layout', 'file' => 'layouts/base.php', 'type' => 'php', 'description' => 'Document shell, asset loading, and wrapper around page content.'],
        'page' => ['label' => 'Page Layout', 'file' => 'layouts/page.php', 'type' => 'php', 'description' => 'Rendering structure for static pages.'],
        'post' => ['label' => 'Post Layout', 'file' => 'layouts/post.php', 'type' => 'php', 'description' => 'Rendering structure for blog posts.'],
        'blog' => ['label' => 'Blog Layout', 'file' => 'layouts/blog.php', 'type' => 'php', 'description' => 'Rendering structure for the blog index.'],
        'archive' => ['label' => 'Archive Layout', 'file' => 'layouts/archive.php', 'type' => 'php', 'description' => 'Rendering structure for archive listings.'],
        'not-found' => ['label' => '404 Layout', 'file' => 'layouts/404.php', 'type' => 'php', 'description' => 'Rendering structure for missing pages.'],
        'manifest' => ['label' => 'Theme Manifest', 'file' => 'theme.json', 'type' => 'json', 'description' => 'Theme metadata, version, author, and declared support.'],
    ];

    private const REQUIRED_THEME_FILES = [
        'theme.json',
        'layouts/base.php',
        'layouts/page.php',
        'layouts/post.php',
        'layouts/blog.php',
        'layouts/archive.php',
        'layouts/404.php',
    ];

    private const UPLOAD_EXTENSIONS = ['php', 'json', 'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'map', 'txt', 'md'];
    private const MAX_THEME_FILES = 500;
    private const MAX_THEME_FILE_BYTES = 5242880;
    private const MAX_THEME_EXTRACTED_BYTES = 52428800;

    public function __construct(
        private readonly Config $config,
        private readonly FileStore $files,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function themes(): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        $active = $this->activeTheme();
        $actions = AdminLayout::buttonLink('Edit active theme', '/admin/theme-templates', 'code', true) . AdminLayout::buttonLink('View site', '/', 'site', true);
        $body = AdminLayout::pageHeader('Themes', 'Manage installed themes, activate a theme, and upload validated theme packages.', $actions);
        $body .= AdminLayout::section('Theme operations', $this->themeOperations(), 'Activate and upload themes only after previewing and validating package contents.');

        $cards = '<div class="bp-admin-action-grid">';
        foreach ($this->themesList() as $theme) {
            $slug = (string)$theme['slug'];
            $isActive = $slug === $active;
            $cards .= '<section class="bp-admin-action-card">';
            $cards .= '<em>' . AdminLayout::icon($isActive ? 'check' : 'code') . '</em>';
            $cards .= '<strong>' . $this->e((string)$theme['name']) . '</strong>';
            $cards .= '<span>Version ' . $this->e((string)$theme['version']) . ' by ' . $this->e((string)$theme['author']) . '</span>';
            $cards .= '<small>' . ($isActive ? 'Active theme' : 'Installed theme') . ' · ' . (int)$theme['asset_count'] . ' assets · ' . ((bool)$theme['valid'] ? 'Valid' : 'Needs attention') . '</small>';
            if (!(bool)$theme['valid']) {
                $cards .= '<span class="bp-error">' . $this->e(implode(' ', (array)$theme['errors'])) . '</span>';
            }
            $cards .= '<div class="bp-uif-toolbar">';
            $cards .= AdminLayout::buttonLink('Edit', '/admin/theme-templates?theme=' . rawurlencode($slug), 'code', true);
            $cards .= AdminLayout::buttonLink('Preview', '/admin/themes/preview/' . rawurlencode($slug) . '?layout=home', 'site', true);
            if (!$isActive && (bool)$theme['valid']) {
                $cards .= '<form method="post" action="/admin/themes/activate" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="theme" value="' . $this->e($slug) . '">' . AdminLayout::submitButton('Activate', 'check') . '</form>';
            }
            $cards .= '</div></section>';
        }
        $cards .= '</div>';

        $upload = '<form method="post" action="/admin/themes/upload" enctype="multipart/form-data" class="bp-form bp-compact-form">';
        $upload .= $this->csrf->field();
        $upload .= '<label>Theme ZIP <input type="file" name="theme_zip" accept=".zip" required><span class="bp-field-help">The package must include <code>theme.json</code> and required layout files.</span></label>';
        $upload .= AdminLayout::submitButton('Upload Theme', 'upload') . '</form>';

        $body .= AdminLayout::section('Installed themes', $cards, 'Active theme: ' . $active);
        $body .= AdminLayout::section('Upload theme', $upload, 'Install a new theme from a validated ZIP package.');

        return Response::html($this->layout('Themes', $body));
    }

    public function activate(Request $request): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Themes', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $theme = $this->sanitizeSlug($request->input('theme'));
        if ($theme === '' || !$this->themeExists($theme)) {
            return Response::html($this->layout('Themes', '<p class="bp-error">Theme not found.</p><p>' . AdminLayout::buttonLink('Back to themes', '/admin/themes', 'back', true) . '</p>'), 404);
        }
        $validation = (new ThemeManager($this->config->paths(), $this->files))->validate($theme);
        if (!($validation['ok'] ?? false)) {
            return Response::html($this->layout('Themes', '<p class="bp-error">Theme cannot be activated: ' . $this->e(implode(' ', (array)$validation['errors'])) . '</p><p>' . AdminLayout::buttonLink('Back to themes', '/admin/themes', 'back', true) . '</p>'), 400);
        }

        $site = $this->config->site();
        $site['theme'] = $theme;
        $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'theme.activated', $theme, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/themes');
    }

    public function upload(): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        if (!$this->csrf->validate((string)($_POST['csrf_token'] ?? ''))) {
            return Response::html($this->layout('Themes', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $file = $_FILES['theme_zip'] ?? [];
        $guard = new UploadGuard(['zip'], 10 * 1024 * 1024);
        $error = $guard->validate($file);
        if ($error !== null) {
            return Response::html($this->layout('Themes', '<p class="bp-error">' . $this->e($error) . '</p><p>' . AdminLayout::buttonLink('Back to themes', '/admin/themes', 'back', true) . '</p>'), 400);
        }

        try {
            $slug = $this->installThemeZip((string)$file['tmp_name'], (string)$file['name']);
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Themes', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to themes', '/admin/themes', 'back', true) . '</p>'), 400);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'theme.uploaded', $slug, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/themes');
    }

    public function preview(string $theme, string $layout = 'home'): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        $theme = $this->resolveTheme($theme);
        $site = $this->config->site();
        $site['theme'] = $theme;
        $files = new FileStore();
        $html = new HtmlContent();
        $pages = new PageRepository($this->config->paths(), $files, $html);
        $posts = new PostRepository($this->config->paths(), $files, $html);
        $previewTargets = ['home', 'page', 'landing', 'shop', 'product', 'cart', 'checkout', 'account', 'contact', 'post', 'blog', 'archive', '404'];
        $layout = in_array($layout, $previewTargets, true) ? $layout : 'home';
        $page = $layout === 'home' ? $pages->findBySlug('home') : ($pages->allPublished()[0] ?? null);
        $post = $posts->allPublished()[0] ?? null;
        $pageTargets = ['home', 'page', 'landing', 'shop', 'product', 'cart', 'checkout', 'account', 'contact'];
        if (in_array($layout, $pageTargets, true) && ($layout !== 'home' || !is_array($page))) {
            $page = $this->previewPage($layout);
        }
        if ($layout === 'post' && !is_array($post)) {
            $post = ['title' => 'Preview Post', 'body' => '<h1>Preview Post</h1><p>Theme post layout preview.</p>', 'published_at' => date(DATE_ATOM)];
        }

        $previewLinks = '';
        foreach ($previewTargets as $target) {
            $label = $target === '404' ? '404' : ucwords(str_replace('-', ' ', $target));
            $previewLinks .= '<a' . ($layout === $target ? ' class="is-current"' : '') . ' href="' . $this->e(\bp_url('/admin/themes/preview/' . rawurlencode($theme)) . '?layout=' . $target) . '">' . $this->e($label) . '</a>';
        }
        $previewBanner = '<div class="bp-preview-banner"><div class="bp-preview-banner-inner"><span class="bp-preview-badge">Preview</span><strong>' . $this->e($this->themeName($theme)) . '</strong><nav aria-label="Preview layout">' . $previewLinks . '</nav><a href="' . $this->e(\bp_url('/admin/themes')) . '">Back to Themes</a></div></div>';

        $renderLayout = $layout;
        $data = ['title' => ucfirst($layout)];
        if (in_array($layout, $pageTargets, true) && is_array($page)) {
            $renderer = new Theme($this->config->paths(), $site);
            $renderLayout = $renderer->pageLayout((string)($page['template'] ?? 'page'));
            $data = ['page' => $page, 'title' => (string)($page['title'] ?? ucfirst($layout))];
        } elseif ($layout === 'post' && is_array($post)) {
            $data = ['post' => $post, 'title' => (string)($post['title'] ?? 'Post')];
        } elseif ($layout === 'blog') {
            $data = ['posts' => $posts->allPublished(), 'title' => 'Blog'];
        } elseif ($layout === 'archive') {
            $data = ['posts' => $posts->allPublished(), 'title' => 'Archive'];
        }

        $response = (new Theme($this->config->paths(), $site))->render($renderLayout, $data, $layout === '404' ? 404 : 200, ['preview_banner' => $previewBanner]);

        $body = $response->content();
        $body = str_replace('</head>', '<style>' . $this->previewCss() . '</style></head>', $body);
        return Response::html($body);
    }

    private function previewPage(string $template): array
    {
        $asset = '/assets/img/batoi-press/press-color-512.png';
        $fixtures = [
            'page' => '<h1>Built for clear communication</h1><p>This standard page supports long-form company, service, policy, and informational content.</p><h2>Flexible by design</h2><p>Use headings, lists, tables, media, quotes, and links while the theme keeps reading width and rhythm consistent.</p>',
            'landing' => '<section class="bp-hero"><div class="bp-hero-copy"><p class="bp-eyebrow">A stronger digital presence</p><h1>Publish a site that works as hard as you do</h1><p class="bp-hero-lead">A versatile foundation for company sites, campaigns, services, editorial content, and commerce journeys.</p><div class="bp-button-row"><a class="bp-button" href="#capabilities">Explore capabilities</a><a class="bp-button bp-button-secondary" href="/contact">Talk to us</a></div></div><div class="bp-hero-media"><img src="' . $asset . '" alt="Batoi Press"></div></section><section class="bp-section bp-section-alt" id="capabilities"><div class="bp-section-inner"><header class="bp-section-header"><p class="bp-eyebrow">One theme, many uses</p><h2>Adapt without rebuilding</h2></header><div class="bp-grid bp-grid-3"><article class="bp-feature"><span class="bp-feature-mark">01</span><h3>Corporate</h3><p>Present services, teams, credentials, and contact paths.</p></article><article class="bp-feature"><span class="bp-feature-mark">02</span><h3>Publishing</h3><p>Share articles and durable reference content.</p></article><article class="bp-feature"><span class="bp-feature-mark">03</span><h3>Commerce</h3><p>Build product discovery and purchase handoff pages.</p></article></div></div></section>',
            'shop' => '<header class="bp-commerce-toolbar"><div><p class="bp-eyebrow">Collection</p><h1>Shop essentials</h1><p>Thoughtfully selected products for modern work.</p></div><div class="bp-filter-row"><a class="bp-filter-chip is-active" href="#">All</a><a class="bp-filter-chip" href="#">Featured</a><a class="bp-filter-chip" href="#">New</a></div></header><section class="bp-product-grid">' . $this->previewProducts($asset) . '</section>',
            'product' => '<section class="bp-product-detail"><div class="bp-product-gallery"><div class="bp-product-thumbnails"><button class="bp-product-thumbnail"><img src="' . $asset . '" alt="Product thumbnail"></button></div><div class="bp-product-gallery-main"><img src="' . $asset . '" alt="Batoi Press product preview"></div></div><div class="bp-product-summary"><p class="bp-eyebrow">Featured</p><h1>Versatile digital edition</h1><p class="bp-rating" aria-label="Rated 4.8 out of 5">4.8 / 5 · 24 reviews</p><p class="bp-price">$49.00</p><p>A focused product summary with clear benefits, options, fulfilment notes, and a purchase handoff.</p><fieldset class="bp-option-group"><legend>Edition</legend><div class="bp-swatches"><button class="bp-swatch is-selected" type="button" data-bp-swatch>Standard</button><button class="bp-swatch" type="button" data-bp-swatch>Extended</button></div></fieldset><div class="bp-purchase-row"><div class="bp-quantity" data-bp-quantity><button type="button" data-step="-1" aria-label="Decrease quantity">−</button><input type="number" min="1" max="99" value="1" aria-label="Quantity"><button type="button" data-step="1" aria-label="Increase quantity">+</button></div><a class="bp-button" href="/cart">Add to cart</a></div><div class="bp-product-notes"><p><strong>Delivery:</strong> Instant digital access</p><p><strong>Support:</strong> Email assistance included</p></div></div></section>',
            'cart' => '<header class="bp-page-heading"><p class="bp-eyebrow">Your order</p><h1>Shopping cart</h1></header><div class="bp-commerce-shell"><section class="bp-cart-list"><article class="bp-cart-item"><img class="bp-cart-item-media" src="' . $asset . '" alt="Digital edition"><div><h2>Versatile digital edition</h2><p>Standard edition</p><a class="bp-text-link" href="#">Remove</a></div><div class="bp-quantity" data-bp-quantity><button type="button" data-step="-1" aria-label="Decrease quantity">−</button><input type="number" min="1" max="99" value="1" aria-label="Quantity"><button type="button" data-step="1" aria-label="Increase quantity">+</button></div><strong>$49.00</strong></article></section>' . $this->previewOrderSummary('Proceed to checkout', '/checkout') . '</div>',
            'checkout' => '<header class="bp-page-heading"><p class="bp-eyebrow">Secure checkout</p><h1>Complete your order</h1></header><div class="bp-commerce-shell"><form><section class="bp-form-section"><h2>Contact</h2><div class="bp-field-grid"><label class="bp-field-wide">Email address<input type="email" autocomplete="email"></label></div></section><section class="bp-form-section"><h2>Billing details</h2><div class="bp-field-grid"><label>First name<input type="text" autocomplete="given-name"></label><label>Last name<input type="text" autocomplete="family-name"></label><label class="bp-field-wide">Address<input type="text" autocomplete="street-address"></label><label>City<input type="text" autocomplete="address-level2"></label><label>Postal code<input type="text" autocomplete="postal-code"></label></div></section><button type="submit">Continue to payment provider</button></form>' . $this->previewOrderSummary('Order total', '#') . '</div>',
            'account' => '<header class="bp-page-heading"><p class="bp-eyebrow">Customer area</p><h1>Your account</h1><p>Review profile details, purchases, and available downloads.</p></header><nav class="bp-account-nav" aria-label="Account sections"><a aria-current="page" href="#">Overview</a><a href="#">Orders</a><a href="#">Downloads</a><a href="#">Profile</a></nav><section class="bp-card"><h2>Recent orders</h2><div class="bp-data-list"><div class="bp-data-row"><strong>#BP-1042</strong><span>Versatile digital edition</span><span>Complete</span><a class="bp-text-link" href="#">View</a></div></div></section>',
            'contact' => '<div class="bp-contact-grid"><section><p class="bp-eyebrow">Contact</p><h1>Let us help</h1><p>Tell us what you are building and the team will route your enquiry.</p><div class="bp-card"><h2>Support</h2><p>support@example.com<br>Monday–Friday, 09:00–17:00</p></div></section><form><div class="bp-field-grid"><label>First name<input type="text"></label><label>Last name<input type="text"></label><label class="bp-field-wide">Email<input type="email"></label><label class="bp-field-wide">How can we help?<textarea></textarea></label></div><div class="bp-button-row"><button type="submit">Send enquiry</button></div></form></div>',
        ];
        $resolved = $template === 'home' ? 'landing' : $template;
        return ['title' => ucwords($resolved), 'template' => $resolved, 'body' => $fixtures[$resolved] ?? $fixtures['page'], 'seo_description' => 'Theme preview for ' . $resolved . '.'];
    }

    private function previewProducts(string $asset): string
    {
        $items = '';
        foreach ([['Essential edition', '$29.00'], ['Versatile edition', '$49.00'], ['Team edition', '$89.00'], ['Complete collection', '$129.00']] as [$name, $price]) {
            $items .= '<article class="bp-product-card"><a class="bp-product-card-media" href="/product"><img src="' . $asset . '" alt="' . $this->e($name) . '"></a><h2><a href="/product">' . $this->e($name) . '</a></h2><p class="bp-price">' . $this->e($price) . '</p></article>';
        }
        return $items;
    }

    private function previewOrderSummary(string $label, string $url): string
    {
        return '<aside class="bp-order-summary"><h2>Summary</h2><div class="bp-order-line"><span>Subtotal</span><strong>$49.00</strong></div><div class="bp-order-line"><span>Delivery</span><span>Digital</span></div><div class="bp-order-line bp-order-total"><span>Total</span><strong>$49.00</strong></div><a class="bp-button" href="' . $this->e($url) . '">' . $this->e($label) . '</a></aside>';
    }

    public function index(?string $theme = null): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        $theme = $this->resolveTheme($theme);
        $body = AdminLayout::pageHeader(
            'Theme Templates',
            'Edit approved source files for the selected theme.',
            AdminLayout::buttonLink('Themes', '/admin/themes', 'back', true) . AdminLayout::buttonLink('View site', '/', 'site', true)
        );

        $cards = '<div class="bp-admin-action-grid">';
        foreach (self::EDITABLE_FILES as $key => $template) {
            $path = $this->templatePath($theme, $key);
            $status = is_file($path) && is_writable($path) ? 'Editable' : (is_file($path) ? 'Read only' : 'Missing');
            $cards .= '<a class="bp-admin-action-card" href="/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key) . '"><em>' . AdminLayout::icon('code') . '</em><strong>' . $this->e((string)$template['label']) . '</strong><span>' . $this->e((string)$template['description']) . '</span><small>' . $this->e($status) . '</small></a>';
        }
        $cards .= '</div>';

        $body .= AdminLayout::section('Editable files', $cards, 'Theme: ' . $theme);
        $body .= AdminLayout::section('Template safety', $this->templateSafety(), 'Constrained theme source editing.');

        return Response::html($this->layout('Theme Templates', $body));
    }

    public function edit(string $target): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        [$theme, $key] = $this->parseTarget($target);
        try {
            $template = $this->template($key);
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to templates', '/admin/theme-templates', 'back', true) . '</p>'), 404);
        }

        $path = $this->templatePath($theme, $key);
        if (!is_file($path)) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">Template file is missing.</p><p>' . AdminLayout::buttonLink('Back to templates', '/admin/theme-templates?theme=' . rawurlencode($theme), 'back', true) . '</p>'), 404);
        }

        $body = AdminLayout::pageHeader(
            (string)$template['label'],
            (string)$template['description'],
            AdminLayout::buttonLink('Back to templates', '/admin/theme-templates?theme=' . rawurlencode($theme), 'back', true) . AdminLayout::buttonLink('View site', '/', 'site', true)
        );
        $body .= '<form method="post" action="/admin/theme-templates/save" class="bp-form bp-admin-editor" autocomplete="off">';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="theme" value="' . $this->e($theme) . '">';
        $body .= '<input type="hidden" name="template" value="' . $this->e($key) . '">';
        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Template code', $this->codeEditor($this->files->read($path), (string)$template['type']), 'Edit source carefully. PHP templates are checked before saving.') . '</div>';
        $body .= '<aside class="bp-editor-side">' . $this->editorPanel('Reference', $this->referencePanel($theme, $key, $path), 'Available context and file ownership.') . $this->editorPanel('Editing standard', $this->editingStandard(), 'Required checks before saving and previewing templates.') . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/theme-templates?theme=' . rawurlencode($theme), 'back', true) . AdminLayout::submitButton('Save Template', 'save') . '</div></form>';

        return Response::html($this->layout((string)$template['label'], $body));
    }

    public function save(Request $request): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $theme = $this->resolveTheme($request->input('theme'));
        $key = $request->input('template');
        try {
            $template = $this->template($key);
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to templates', '/admin/theme-templates?theme=' . rawurlencode($theme), 'back', true) . '</p>'), 404);
        }

        $path = $this->templatePath($theme, $key);
        $source = $request->input('source');
        if (trim($source) === '') {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">Template source cannot be empty.</p><p>' . AdminLayout::buttonLink('Back to editor', '/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key), 'back', true) . '</p>'), 400);
        }

        $validationError = $this->validateSource($source, (string)$template['type']);
        if ($validationError !== null) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">' . $this->e($validationError) . '</p><p>' . AdminLayout::buttonLink('Back to editor', '/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key), 'back', true) . '</p>'), 400);
        }

        try {
            $this->snapshot($theme, $key, $path);
            $this->files->write($path, $source);
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to editor', '/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key), 'back', true) . '</p>'), 500);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'theme.template.updated', $theme . ':' . $key, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key));
    }

    public function restore(Request $request): Response
    {
        if ($blocked = $this->authorize()) {
            return $blocked;
        }

        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $theme = $this->resolveTheme($request->input('theme'));
        $key = $request->input('template');
        $snapshot = $request->input('snapshot');
        try {
            $this->template($key);
            $snapshotPath = $this->snapshotPath($theme, $key, $snapshot);
            if ($snapshotPath === null || !is_file($snapshotPath)) {
                throw new RuntimeException('Snapshot not found.');
            }
            $target = $this->templatePath($theme, $key);
            $this->snapshot($theme, $key, $target);
            $this->files->write($target, $this->files->read($snapshotPath));
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Theme Templates', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to editor', '/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key), 'back', true) . '</p>'), 400);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'theme.template.restored', $theme . ':' . $key, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/theme-templates/edit/' . rawurlencode($theme) . '/' . rawurlencode($key));
    }

    private function themesList(): array
    {
        $themes = [];
        foreach (glob($this->config->paths()->themePath('*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            $manifest = $this->readManifest($slug);
            $manager = new ThemeManager($this->config->paths(), $this->files);
            $validation = $manager->validate($slug);
            $themes[] = [
                'slug' => $slug,
                'name' => (string)($manifest['name'] ?? ucfirst($slug)),
                'version' => (string)($manifest['version'] ?? 'unknown'),
                'author' => (string)($manifest['author'] ?? 'Unknown'),
                'asset_count' => count($manager->assetFiles($slug)),
                'valid' => (bool)($validation['ok'] ?? false),
                'errors' => (array)($validation['errors'] ?? []),
            ];
        }
        usort($themes, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
        return $themes;
    }

    private function readManifest(string $theme): array
    {
        $path = $this->config->paths()->themePath($theme . '/theme.json');
        if (!is_file($path)) {
            return [];
        }
        try {
            return $this->files->readJson($path);
        } catch (RuntimeException) {
            return [];
        }
    }

    private function themeName(string $theme): string
    {
        $manifest = $this->readManifest($theme);
        $name = trim((string)($manifest['name'] ?? ''));
        return $name !== '' ? $name : ucfirst($theme);
    }

    private function previewCss(): string
    {
        return '.bp-preview-banner{background:#0f172a;border-bottom:3px solid #00b696;color:#fff;position:sticky;top:0;z-index:9999;box-shadow:0 8px 24px rgba(15,23,42,.18)}'
            . '.bp-preview-banner-inner{align-items:center;display:flex;gap:12px;justify-content:center;margin:0 auto;max-width:1120px;min-height:46px;padding:8px 20px}'
            . '.bp-preview-banner strong{font-size:.95rem;font-weight:600}'
            . '.bp-preview-banner span{color:#cbd5e1;font-size:.86rem}'
            . '.bp-preview-banner .bp-preview-badge{background:rgba(0,182,150,.16);border:1px solid rgba(0,182,150,.42);border-radius:999px;color:#99f6e4;font-size:.72rem;font-weight:600;letter-spacing:0;padding:4px 8px;text-transform:uppercase}'
            . '.bp-preview-banner a{background:#fff;border:1px solid rgba(255,255,255,.78);border-radius:3px;color:#0f172a;font-size:.84rem;font-weight:600;margin-left:auto;padding:7px 10px;text-decoration:none}'
            . '.bp-preview-banner nav{align-items:center;display:flex;flex-wrap:wrap;gap:4px}.bp-preview-banner nav a{background:transparent;border-color:transparent;color:#cbd5e1;margin:0;padding:5px 7px}.bp-preview-banner nav a.is-current{background:rgba(255,255,255,.14);color:#fff}'
            . '.bp-preview-banner a:hover{background:#e6f4ff;color:#07497c}'
            . '@media(max-width:720px){.bp-preview-banner-inner{align-items:flex-start;flex-direction:column;gap:6px}.bp-preview-banner a{margin-left:0}}';
    }

    private function installThemeZip(string $uploadPath, string $originalName): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Theme uploads require the PHP Zip extension.');
        }

        $zip = new ZipArchive();
        if ($zip->open($uploadPath) !== true) {
            throw new RuntimeException('Unable to open theme ZIP.');
        }
        $zipOpen = true;

        $tmp = $this->config->paths()->dataPath('tmp/theme-upload-' . bin2hex(random_bytes(4)));
        if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
            $zip->close();
            throw new RuntimeException('Unable to prepare theme upload workspace.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_THEME_FILES) {
                throw new RuntimeException('Theme ZIP contains too many files.');
            }
            $seen = [];
            $totalBytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                $normalized = $this->validateZipEntry($name);
                if (isset($seen[$normalized])) {
                    throw new RuntimeException('Theme ZIP contains a duplicate path: ' . $normalized);
                }
                $seen[$normalized] = true;
                $stat = $zip->statIndex($i);
                $entryBytes = (int)($stat['size'] ?? 0);
                if ($entryBytes > self::MAX_THEME_FILE_BYTES) {
                    throw new RuntimeException('Theme ZIP entry is too large: ' . $normalized);
                }
                $totalBytes += $entryBytes;
                if ($totalBytes > self::MAX_THEME_EXTRACTED_BYTES) {
                    throw new RuntimeException('Theme ZIP extracted size is too large.');
                }
                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if (($attributes & 0xF0000000) === 0xA0000000) {
                    throw new RuntimeException('Theme ZIP cannot contain symbolic links.');
                }
                if (isset($stat['encryption_method']) && (int)$stat['encryption_method'] !== 0) {
                    throw new RuntimeException('Theme ZIP cannot contain encrypted entries.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $target = $tmp . '/' . $normalized;
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new RuntimeException('Unable to read ZIP entry: ' . $name);
                }
                $this->files->write($target, $contents);
            }
            $zip->close();
            $zipOpen = false;

            $source = $this->locateThemeRoot($tmp);
            $this->validateThemeRoot($source);
            $slug = $this->themeSlugFromUpload($source, $originalName);
            $target = $this->config->paths()->themePath($slug);
            $staging = $this->config->paths()->themePath('.install-' . $slug . '-' . bin2hex(random_bytes(3)));
            $this->copyDirectory($source, $staging);
            if (is_dir($target)) {
                $backup = $this->config->paths()->dataPath('versions/theme-packages/' . $slug . '/' . date('Ymd-His'));
                $this->copyDirectory($target, $backup);
                $rollback = $this->config->paths()->themePath('.rollback-' . $slug . '-' . bin2hex(random_bytes(3)));
                if (!rename($target, $rollback)) {
                    $this->removeDirectory($staging);
                    throw new RuntimeException('Unable to prepare the existing theme for upgrade.');
                }
                if (!rename($staging, $target)) {
                    rename($rollback, $target);
                    $this->removeDirectory($staging);
                    throw new RuntimeException('Unable to publish the validated theme upgrade.');
                }
                $this->removeDirectory($rollback);
            } elseif (!rename($staging, $target)) {
                $this->removeDirectory($staging);
                throw new RuntimeException('Unable to publish the validated theme.');
            }
        } finally {
            if (($zipOpen ?? false) && isset($zip) && $zip instanceof ZipArchive) {
                $zip->close();
            }
            $this->removeDirectory($tmp);
        }

        return $slug;
    }

    private function validateZipEntry(string $name): string
    {
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[a-z]:/i', $normalized) === 1 || preg_match('/[\x00-\x1f\x7f]/', $normalized) === 1) {
            throw new RuntimeException('Theme ZIP contains an unsafe path.');
        }
        foreach (explode('/', rtrim($normalized, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Theme ZIP contains an unsafe path.');
            }
        }
        if (str_ends_with($normalized, '/')) {
            return $normalized;
        }
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if (!in_array($extension, self::UPLOAD_EXTENSIONS, true)) {
            throw new RuntimeException('Theme ZIP contains an unsupported file type: ' . $extension);
        }
        if ($extension === 'php' && preg_match('#(?:^|/)(?:layouts|partials)/[^/]+\.php$#', $normalized) !== 1) {
            throw new RuntimeException('Theme PHP files are restricted to layouts and partials.');
        }
        return $normalized;
    }

    private function locateThemeRoot(string $tmp): string
    {
        if (is_file($tmp . '/theme.json')) {
            return $tmp;
        }

        $children = array_values(array_filter(glob($tmp . '/*') ?: [], 'is_dir'));
        $matches = array_values(array_filter($children, static fn (string $dir): bool => is_file($dir . '/theme.json')));
        if (count($matches) !== 1) {
            throw new RuntimeException('Theme ZIP must contain one theme root with theme.json.');
        }
        return $matches[0];
    }

    private function validateThemeRoot(string $source): void
    {
        foreach (self::REQUIRED_THEME_FILES as $file) {
            if (!is_file($source . '/' . $file)) {
                throw new RuntimeException('Theme ZIP is missing required file: ' . $file);
            }
        }
        $manifest = $this->files->readJson($source . '/theme.json');
        $slug = $this->sanitizeSlug((string)($manifest['slug'] ?? $manifest['name'] ?? 'theme'));
        $normalized = (new ThemeManager($this->config->paths(), $this->files))->normalizeManifest($slug, $manifest);
        foreach (['styles', 'scripts'] as $group) {
            foreach ($normalized['assets'][$group] as $entry) {
                if (!is_file($source . '/assets/' . (string)$entry['file'])) {
                    throw new RuntimeException('Theme ZIP is missing declared asset: assets/' . (string)$entry['file']);
                }
            }
        }
    }

    private function themeSlugFromUpload(string $source, string $originalName): string
    {
        $manifest = $this->files->readJson($source . '/theme.json');
        $base = (string)($manifest['slug'] ?? $manifest['name'] ?? pathinfo($originalName, PATHINFO_FILENAME));
        $slug = $this->sanitizeSlug($base);
        if ($slug === '' || $slug === 'default') {
            $slug = 'theme-' . date('Ymd-His');
        }
        return $slug;
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0775, true);
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $target . '/' . $entry;
            if (is_dir($from)) {
                $this->copyDirectory($from, $to);
                continue;
            }
            $this->files->write($to, $this->files->read($from));
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function codeEditor(string $source, string $type): string
    {
        $language = $type === 'json' ? 'JSON' : 'PHP / HTML';
        return '<label class="bp-field-wide bp-code-editor-label" for="theme-template-source"><span>Source</span><small>' . $this->e($language) . ' source editor</small><textarea id="theme-template-source" class="bp-editor-textarea bp-template-code-editor" name="source" rows="28" spellcheck="false" autocomplete="off" autocapitalize="off" autocorrect="off" wrap="off" aria-readonly="false">' . $this->e($source) . '</textarea></label>';
    }

    private function themeOperations(): string
    {
        return '<div class="bp-admin-guidance-grid">'
            . $this->guidanceCard('Preview first', 'Use Preview to review public rendering before activating another theme.', 'site')
            . $this->guidanceCard('Validated upload', 'ZIP packages are checked for safe paths, required files, and supported file types.', 'upload')
            . $this->guidanceCard('Audit trail', 'Theme uploads, activations, template saves, and restores are recorded.', 'shield')
            . '</div>';
    }

    private function templateSafety(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Only approved files inside the selected theme can be edited.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>PHP templates are syntax-checked before saving when a PHP CLI binary is available.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>The previous version is snapshotted before each successful save.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Use the cache page after template changes if public output looks stale.</span></li>'
            . '</ul>';
    }

    private function editingStandard(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Escape dynamic output with the documented helper for its context.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Keep layout changes compatible with page, post, blog, and 404 views.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Save, preview the site, and clear cache if rendered output does not refresh.</span></li>'
            . '</ul>';
    }

    private function guidanceCard(string $title, string $description, string $icon): string
    {
        return '<article><span>' . AdminLayout::icon($icon) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></article>';
    }

    private function validateSource(string $source, string $type): ?string
    {
        if ($type === 'json') {
            json_decode($source, true);
            return json_last_error() === JSON_ERROR_NONE ? null : 'JSON is invalid: ' . json_last_error_msg();
        }

        $tmpDir = $this->config->paths()->dataPath('tmp');
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            return 'Unable to prepare the template syntax check workspace.';
        }

        $tmp = $tmpDir . '/template-lint-' . bin2hex(random_bytes(4)) . '.php';
        try {
            $this->files->write($tmp, $source);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
        $php = $this->phpCliBinary();
        if ($php === null) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            return null;
        }

        $command = escapeshellarg($php) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        if (is_file($tmp)) {
            unlink($tmp);
        }
        if ($code !== 0 && $this->isPhpRuntimeStartupNoise($output)) {
            return null;
        }
        return $code === 0 ? null : 'PHP syntax check failed: ' . implode(' ', $output);
    }

    private function phpCliBinary(): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $candidates = [
            PHP_BINDIR . '/php',
            dirname(PHP_BINARY) . '/php',
            PHP_BINARY,
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];

        foreach ($candidates as $candidate) {
            $candidate = (string)$candidate;
            if ($candidate === '' || !is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            if ($this->isCliCandidateName(basename($candidate)) && $this->isCliPhpBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isCliCandidateName(string $name): bool
    {
        return preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?(?:\.exe)?$/i', $name) === 1;
    }

    private function isCliPhpBinary(string $candidate): bool
    {
        $command = escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);

        if ($code !== 0 || $output === []) {
            return false;
        }

        $sapi = strtolower(trim((string)$output[0]));
        return in_array($sapi, ['cli', 'phpdbg'], true);
    }

    private function isPhpRuntimeStartupNoise(array $output): bool
    {
        $message = strtolower(implode(' ', array_map('strval', $output)));
        if ($message === '') {
            return false;
        }

        $hasSyntaxError = str_contains($message, 'parse error')
            || str_contains($message, 'syntax error')
            || str_contains($message, 'errors parsing');
        if ($hasSyntaxError) {
            return false;
        }

        return str_contains($message, 'mpm_winnt')
            || str_contains($message, 'unable to retrieve my generation from the parent')
            || preg_match('/\bah\d{5}\b/', $message) === 1;
    }

    private function snapshot(string $theme, string $key, string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $template = $this->template($key);
        $extension = pathinfo((string)$template['file'], PATHINFO_EXTENSION) ?: 'txt';
        $target = $this->config->paths()->dataPath('versions/theme/' . $theme . '/' . $key . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension);
        $this->files->write($target, $this->files->read($path));
    }

    private function snapshots(string $theme, string $key): array
    {
        $dir = $this->config->paths()->dataPath('versions/theme/' . $theme . '/' . $key);
        $files = is_dir($dir) ? glob($dir . '/*') ?: [] : [];
        rsort($files);
        return array_slice(array_values(array_filter($files, 'is_file')), 0, 8);
    }

    private function snapshotPath(string $theme, string $key, string $snapshot): ?string
    {
        $safe = basename($snapshot);
        if ($safe === '' || $safe !== $snapshot) {
            return null;
        }

        $path = $this->config->paths()->dataPath('versions/theme/' . $theme . '/' . $key . '/' . $safe);
        return is_file($path) ? $path : null;
    }

    private function referencePanel(string $theme, string $key, string $path): string
    {
        $relative = str_replace($this->config->paths()->root() . '/', '', $path);
        $items = '<dl class="bp-meta-list">';
        $items .= '<div><dt>Theme</dt><dd>' . $this->e($theme) . '</dd></div>';
        $items .= '<div><dt>Template</dt><dd>' . $this->e($key) . '</dd></div>';
        $items .= '<div><dt>File</dt><dd><code>' . $this->e($relative) . '</code></dd></div>';
        $items .= '<div><dt>Context</dt><dd><code>$site</code>, <code>$title</code>, <code>$page</code>, <code>$post</code>, and URL/escaping helpers from the active layout.</dd></div>';
        $items .= '</dl><p class="bp-field-help">Avoid unescaped dynamic output. Use <code>bp_esc()</code> for text, <code>bp_attr()</code> for attributes, and <code>bp_url()</code> for local URLs.</p>';
        $snapshots = $this->snapshots($theme, $key);
        if ($snapshots === []) {
            return $items . '<p class="bp-muted">No saved snapshots yet.</p>';
        }

        $items .= '<div class="bp-snapshot-list">';
        foreach ($snapshots as $snapshot) {
            $name = basename($snapshot);
            $items .= '<div class="bp-inline-form"><span>' . $this->e($name) . '</span>'
                . AdminLayout::submitButton('Restore', 'refresh', 'class="bp-button bp-button-secondary" name="snapshot" value="' . $this->e($name) . '" formaction="' . $this->e(\bp_url('/admin/theme-templates/restore')) . '" formmethod="post"')
                . '</div>';
        }
        $items .= '</div>';
        return $items;
    }

    private function authorize(): ?Response
    {
        $role = (string)($this->user['role'] ?? 'admin');
        $username = (string)($this->user['username'] ?? '');
        if (in_array($role, ['owner', 'admin'], true) || $username === 'admin') {
            return null;
        }

        return Response::html(AdminLayout::message('Themes', 'You do not have permission to manage themes.', true), 403);
    }

    private function editorPanel(string $title, string $body, string $description): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }

    private function template(string $key): array
    {
        if (!array_key_exists($key, self::EDITABLE_FILES)) {
            throw new RuntimeException('Unknown theme template.');
        }

        return self::EDITABLE_FILES[$key];
    }

    private function templatePath(string $theme, string $key): string
    {
        $template = $this->template($key);
        return $this->config->paths()->themePath($theme . '/' . (string)$template['file']);
    }

    private function parseTarget(string $target): array
    {
        $parts = array_values(array_filter(explode('/', trim($target, '/')), static fn (string $part): bool => $part !== ''));
        if (count($parts) >= 2) {
            return [$this->resolveTheme(rawurldecode($parts[0])), rawurldecode($parts[1])];
        }
        return [$this->activeTheme(), rawurldecode($parts[0] ?? '')];
    }

    private function resolveTheme(?string $theme): string
    {
        $theme = $this->sanitizeSlug((string)($theme ?? ''));
        $theme = $theme !== '' ? $theme : $this->activeTheme();
        if (!$this->themeExists($theme)) {
            return $this->activeTheme();
        }
        return $theme;
    }

    private function activeTheme(): string
    {
        $theme = $this->sanitizeSlug((string)($this->config->site()['theme'] ?? 'default'));
        return $theme !== '' ? $theme : 'default';
    }

    private function themeExists(string $theme): bool
    {
        return is_dir($this->config->paths()->themePath($theme)) && is_file($this->config->paths()->themePath($theme . '/theme.json'));
    }

    private function sanitizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?: '';
        return trim($slug, '-_');
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
