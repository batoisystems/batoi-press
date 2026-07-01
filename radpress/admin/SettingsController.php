<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class SettingsController
{
    private const FAVICON_EXTENSIONS = ['ico', 'png', 'jpg', 'jpeg', 'webp', 'svg'];
    private const FAVICON_MAX_BYTES = 1048576;

    public function __construct(
        private readonly Config $config,
        private readonly FileStore $files,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function edit(): Response
    {
        $site = $this->config->site();
        $editor = $this->config->editor();
        $body = AdminLayout::pageHeader(
            'Settings',
            'Control site identity, URLs, localization, and active theme configuration.'
        );
        $body .= '<form method="post" action="/admin/settings/save" enctype="multipart/form-data" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= AdminLayout::section('Change guidance', $this->changeGuidance(), 'Review these notes before changing site-wide configuration.');
        $body .= $this->section('Identity', 'Public site name and supporting text.', '<div class="bp-form-grid">' . $this->input('Site Name', 'name', (string)($site['name'] ?? '')) . $this->input('Tagline', 'tagline', (string)($site['tagline'] ?? '')) . '</div>');
        $body .= $this->section('Branding', 'Website favicon shown in browser tabs and bookmarks.', $this->faviconField($site));
        $body .= $this->section('URLs', 'Canonical public URL used for links, feeds, and update metadata.', $this->input('Base URL', 'base_url', (string)($site['base_url'] ?? '')));
        $body .= $this->section('Localization', 'Locale and timezone used for date formatting and future language-aware features.', '<div class="bp-form-grid">' . $this->input('Locale', 'locale', (string)($site['locale'] ?? 'en')) . $this->input('Timezone', 'timezone', (string)($site['timezone'] ?? 'UTC')) . '</div>');
        $body .= $this->section('Editor', 'Configure the body editor used by pages and posts.', '<div class="bp-form-grid">' . $this->editorSelect((string)($editor['body_editor'] ?? 'rich_html')) . $this->input('Editor Height', 'editor_html_height', (string)($editor['html_height'] ?? '24rem')) . '<label class="bp-field-wide">HTML Toolbar <input type="text" name="editor_html_toolbar" value="' . $this->e((string)($editor['html_toolbar'] ?? 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source')) . '" required><span class="bp-field-help">Space-separated Batoi UIF editor commands.</span></label></div>');
        $body .= $this->section('Theme', 'Current frontend theme and shared public shell templates.', '<dl class="bp-meta-list"><div><dt>Active theme</dt><dd>' . $this->e((string)($site['theme'] ?? 'default')) . '</dd></div></dl><p>' . AdminLayout::buttonLink('Manage Themes', '/admin/themes', 'code', true) . AdminLayout::buttonLink('Edit Templates', '/admin/theme-templates', 'code', true) . '</p>');
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin', 'back', true) . AdminLayout::submitButton('Save Settings', 'save') . '</div></form>';
        return Response::html($this->layout('Settings', $body));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Settings', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $site = $this->config->site();
        foreach (['name', 'tagline', 'base_url', 'locale', 'timezone'] as $key) {
            $site[$key] = $request->input($key);
        }
        $site['theme'] = $site['theme'] ?? 'default';
        $faviconResult = $this->saveFaviconUpload();
        if (is_string($faviconResult) && str_starts_with($faviconResult, 'error:')) {
            return Response::html($this->layout('Settings', '<p class="bp-error">' . $this->e(substr($faviconResult, 6)) . '</p>'), 400);
        }
        if (is_string($faviconResult) && $faviconResult !== '') {
            $site['favicon'] = $faviconResult;
        }
        $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
        $bodyEditor = in_array($request->input('editor_body_editor'), ['rich_html', 'source_html'], true) ? $request->input('editor_body_editor') : 'rich_html';
        $editor = [
            'body_editor' => $bodyEditor,
            'html_toolbar' => trim($request->input('editor_html_toolbar')) !== '' ? trim($request->input('editor_html_toolbar')) : 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source',
            'html_height' => trim($request->input('editor_html_height')) !== '' ? trim($request->input('editor_html_height')) : '24rem',
        ];
        $this->files->writeJson($this->config->paths()->configPath('editor.json'), $editor);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'settings.updated', 'site', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/settings');
    }

    private function input(string $label, string $name, string $value): string
    {
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '" required></label>';
    }

    private function faviconField(array $site): string
    {
        $favicon = (string)($site['favicon'] ?? '');
        $faviconExists = $favicon !== '' && is_file($this->publicFile($favicon));
        $previewUrl = $faviconExists ? $this->faviconUrl($favicon) : $this->assetUrl('/assets/img/batoi-press/press-color-tile-32.png');
        $previewLabel = $faviconExists ? 'Current favicon' : 'Current favicon (default)';
        $notice = $favicon !== '' && !$faviconExists ? '<small>Configured favicon file was not found. Showing the default Batoi Press icon.</small>' : '';
        $preview = '<div class="bp-favicon-current"><span>' . $this->e($previewLabel) . '</span><img src="' . $this->e($previewUrl) . '" alt="">' . $notice . '</div>';
        if ($favicon !== '') {
            $preview .= '<input type="hidden" name="current_favicon" value="' . $this->e($favicon) . '">';
        }

        return '<div class="bp-form-grid">' . $preview . '<label class="bp-field-wide">Upload Favicon <input type="file" name="favicon" accept=".ico,.png,.jpg,.jpeg,.webp,.svg,image/x-icon,image/png,image/jpeg,image/webp,image/svg+xml"><span class="bp-field-help">Use SVG, ICO, PNG, JPG, or WebP up to 1 MB. The admin favicon always remains the Batoi Press logo.</span></label></div>';
    }

    private function editorSelect(string $value): string
    {
        $rich = $value === 'rich_html' ? ' selected' : '';
        $source = $value === 'source_html' ? ' selected' : '';
        return '<label>Body Editor <select name="editor_body_editor"><option value="rich_html"' . $rich . '>Batoi UIF Rich HTML</option><option value="source_html"' . $source . '>HTML Source</option></select><span class="bp-field-help">Markdown will need a storage/rendering migration before it is enabled here.</span></label>';
    }

    private function section(string $title, string $description, string $body): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }

    private function changeGuidance(): string
    {
        return '<div class="bp-admin-guidance-grid">'
            . $this->guidanceCard('Public identity', 'Name, tagline, favicon, locale, and timezone can affect public presentation.', 'site')
            . $this->guidanceCard('Canonical URL', 'Base URL is used by feeds, sitemap output, and static export metadata.', 'file')
            . $this->guidanceCard('Editor behavior', 'Editor settings apply to page and post body fields after the next editor load.', 'edit')
            . '</div>';
    }

    private function guidanceCard(string $title, string $description, string $icon): string
    {
        return '<article><span>' . AdminLayout::icon($icon) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></article>';
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function saveFaviconUpload(): ?string
    {
        $upload = $_FILES['favicon'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'error:Unable to read the favicon upload.';
        }

        $name = (string)($upload['name'] ?? '');
        $tmpName = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

        if ($size <= 0 || $size > self::FAVICON_MAX_BYTES) {
            return 'error:Favicon must be a non-empty file up to 1 MB.';
        }
        if (!in_array($extension, self::FAVICON_EXTENSIONS, true)) {
            return 'error:Favicon must be SVG, ICO, PNG, JPG, or WebP.';
        }
        if ($tmpName === '' || !is_file($tmpName)) {
            return 'error:Favicon upload was not saved by PHP.';
        }
        if ($extension === 'svg') {
            $svgError = $this->validateSvgFavicon($tmpName);
            if ($svgError !== null) {
                return 'error:' . $svgError;
            }
        } else {
            $imageError = $this->validateImageFavicon($tmpName, $extension);
            if ($imageError !== null) {
                return 'error:' . $imageError;
            }
        }

        $relative = '/assets/site/favicon.' . $extension;
        $directory = dirname(__DIR__, 2) . '/public_html/assets/site';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return 'error:Unable to prepare the favicon directory.';
        }

        $destination = $directory . '/favicon.' . $extension;
        if (!move_uploaded_file($tmpName, $destination)) {
            return 'error:Unable to save the favicon upload.';
        }

        chmod($destination, 0664);
        return $relative;
    }

    private function validateSvgFavicon(string $path): ?string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return 'SVG favicon is empty.';
        }

        $lower = strtolower($contents);
        if (!str_contains($lower, '<svg')) {
            return 'SVG favicon must contain an SVG root.';
        }

        foreach (['<script', 'javascript:', '<foreignobject', 'data:'] as $blocked) {
            if (str_contains($lower, $blocked)) {
                return 'SVG favicon contains unsupported active content.';
            }
        }

        if (preg_match('/\son[a-z]+\s*=/i', $contents) === 1) {
            return 'SVG favicon cannot contain event handler attributes.';
        }

        return null;
    }

    private function validateImageFavicon(string $path, string $extension): ?string
    {
        if ($extension === 'ico') {
            $handle = fopen($path, 'rb');
            $header = is_resource($handle) ? fread($handle, 4) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            return $header === "\x00\x00\x01\x00" ? null : 'ICO favicon has an invalid file header.';
        }

        $info = @getimagesize($path);
        if (!is_array($info) || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
            return 'Favicon must be a valid image file.';
        }

        $allowedMimes = [
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $mime = (string)($info['mime'] ?? '');
        if (($allowedMimes[$extension] ?? '') !== $mime) {
            return 'Favicon image type does not match the file extension.';
        }

        return null;
    }

    private function faviconUrl(string $favicon): string
    {
        $path = $this->publicFile($favicon);
        $version = is_file($path) ? '?v=' . filemtime($path) : '';
        return '/' . ltrim($favicon, '/') . $version;
    }

    private function assetUrl(string $path): string
    {
        $file = $this->publicFile($path);
        return '/' . ltrim($path, '/') . (is_file($file) ? '?v=' . filemtime($file) : '');
    }

    private function publicFile(string $path): string
    {
        return dirname(__DIR__, 2) . '/public_html/' . ltrim($path, '/');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
