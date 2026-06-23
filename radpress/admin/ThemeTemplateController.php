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

    private const UPLOAD_EXTENSIONS = ['php', 'json', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'txt', 'md'];

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

        $cards = '<div class="bp-admin-action-grid">';
        foreach ($this->themesList() as $theme) {
            $slug = (string)$theme['slug'];
            $isActive = $slug === $active;
            $cards .= '<section class="bp-admin-action-card">';
            $cards .= '<em>' . AdminLayout::icon($isActive ? 'check' : 'code') . '</em>';
            $cards .= '<strong>' . $this->e((string)$theme['name']) . '</strong>';
            $cards .= '<span>Version ' . $this->e((string)$theme['version']) . ' by ' . $this->e((string)$theme['author']) . '</span>';
            $cards .= '<small>' . ($isActive ? 'Active theme' : 'Installed theme') . '</small>';
            $cards .= '<div class="bp-uif-toolbar">';
            $cards .= AdminLayout::buttonLink('Edit', '/admin/theme-templates?theme=' . rawurlencode($slug), 'code', true);
            $cards .= AdminLayout::buttonLink('Preview', '/admin/themes/preview/' . rawurlencode($slug), 'site', true);
            if (!$isActive) {
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

    public function preview(string $theme): Response
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
        $page = $pages->findBySlug('home');

        $previewBanner = '<div class="bp-preview-banner"><div class="bp-preview-banner-inner"><span class="bp-preview-badge">Preview</span><strong>' . $this->e($this->themeName($theme)) . '</strong><span>Reviewing theme without changing the live site.</span><a href="' . $this->e(\bp_url('/admin/themes')) . '">Back to Themes</a></div></div>';
        if ($page !== null) {
            $response = (new Theme($this->config->paths(), $site))->render('page', ['page' => $page, 'title' => (string)($page['title'] ?? 'Home')]);
        } else {
            $response = (new Theme($this->config->paths(), $site))->render('blog', ['posts' => $posts->allPublished(), 'title' => 'Blog']);
        }

        $body = $response->content();
        $body = str_replace('</head>', '<style>' . $this->previewCss() . '</style></head>', $body);
        $body = str_replace('<body class="bp-public-body">', '<body class="bp-public-body is-theme-preview">' . $previewBanner, $body);
        return Response::html($body);
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
        $body .= AdminLayout::section('Template safety', '<p class="bp-muted">Only approved files inside the selected theme can be edited. PHP templates are syntax-checked before saving and the previous version is snapshotted.</p>', 'Constrained theme source editing.');

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
        $body .= '<form method="post" action="/admin/theme-templates/save" class="bp-form bp-admin-editor">';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="theme" value="' . $this->e($theme) . '">';
        $body .= '<input type="hidden" name="template" value="' . $this->e($key) . '">';
        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Template code', $this->codeEditor($this->files->read($path), (string)$template['type']), 'Edit source carefully. PHP templates are checked before saving.') . '</div>';
        $body .= '<aside class="bp-editor-side">' . $this->editorPanel('Reference', $this->referencePanel($theme, $key, $path), 'Available context and file ownership.') . '</aside>';
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
            $themes[] = [
                'slug' => $slug,
                'name' => (string)($manifest['name'] ?? ucfirst($slug)),
                'version' => (string)($manifest['version'] ?? 'unknown'),
                'author' => (string)($manifest['author'] ?? 'Unknown'),
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
            . '.bp-preview-banner strong{font-size:.95rem;font-weight:760}'
            . '.bp-preview-banner span{color:#cbd5e1;font-size:.86rem}'
            . '.bp-preview-banner .bp-preview-badge{background:rgba(0,182,150,.16);border:1px solid rgba(0,182,150,.42);border-radius:999px;color:#99f6e4;font-size:.72rem;font-weight:800;letter-spacing:.02em;padding:4px 8px;text-transform:uppercase}'
            . '.bp-preview-banner a{background:#fff;border:1px solid rgba(255,255,255,.78);border-radius:3px;color:#0f172a;font-size:.84rem;font-weight:760;margin-left:auto;padding:7px 10px;text-decoration:none}'
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

        $tmp = $this->config->paths()->dataPath('tmp/theme-upload-' . bin2hex(random_bytes(4)));
        if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
            $zip->close();
            throw new RuntimeException('Unable to prepare theme upload workspace.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                $this->validateZipEntry($name);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $target = $tmp . '/' . $name;
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

            $source = $this->locateThemeRoot($tmp);
            $this->validateThemeRoot($source);
            $slug = $this->themeSlugFromUpload($source, $originalName);
            $target = $this->config->paths()->themePath($slug);
            if (is_dir($target)) {
                throw new RuntimeException('A theme with this slug already exists.');
            }
            $this->copyDirectory($source, $target);
        } finally {
            if (isset($zip) && $zip instanceof ZipArchive) {
                $zip->close();
            }
            $this->removeDirectory($tmp);
        }

        return $slug;
    }

    private function validateZipEntry(string $name): void
    {
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../') || str_contains($normalized, '..\\')) {
            throw new RuntimeException('Theme ZIP contains an unsafe path.');
        }
        if (str_ends_with($normalized, '/')) {
            return;
        }
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if (!in_array($extension, self::UPLOAD_EXTENSIONS, true)) {
            throw new RuntimeException('Theme ZIP contains an unsupported file type: ' . $extension);
        }
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
        return '<label class="bp-field-wide bp-code-editor-label"><span>Source</span><small>' . $this->e($language) . ' source editor</small><textarea class="bp-editor-textarea bp-template-code-editor" name="source" rows="28" spellcheck="false" autocomplete="off" autocapitalize="off" autocorrect="off" wrap="off">' . $this->e($source) . '</textarea></label>';
    }

    private function validateSource(string $source, string $type): ?string
    {
        if ($type === 'json') {
            json_decode($source, true);
            return json_last_error() === JSON_ERROR_NONE ? null : 'JSON is invalid: ' . json_last_error_msg();
        }

        $tmp = $this->config->paths()->dataPath('tmp/template-lint-' . bin2hex(random_bytes(4)) . '.php');
        $this->files->write($tmp, $source);
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        unlink($tmp);
        return $code === 0 ? null : 'PHP syntax check failed: ' . implode(' ', $output);
    }

    private function snapshot(string $theme, string $key, string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $template = $this->template($key);
        $extension = pathinfo((string)$template['file'], PATHINFO_EXTENSION) ?: 'txt';
        $target = $this->config->paths()->dataPath('versions/theme/' . $theme . '/' . $key . '/' . date('Ymd-His') . '.' . $extension);
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
            $items .= '<form method="post" action="/admin/theme-templates/restore" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="theme" value="' . $this->e($theme) . '"><input type="hidden" name="template" value="' . $this->e($key) . '"><input type="hidden" name="snapshot" value="' . $this->e($name) . '"><span>' . $this->e($name) . '</span>' . AdminLayout::submitButton('Restore', 'refresh', 'class="bp-button bp-button-secondary"') . '</form>';
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
