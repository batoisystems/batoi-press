<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\BrandAssetManager;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class SettingsController
{
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
        return Response::html($this->layout('Settings', $this->form($this->config->site(), $this->config->editor())));
    }

    private function form(array $site, array $editor, string $error = ''): string
    {
        $body = AdminLayout::pageHeader(
            'Settings',
            'Control site identity, URLs, localization, and active theme configuration.'
        );
        if ($error !== '') {
            $body .= '<p class="bp-error">' . $this->e($error) . '</p>';
        }
        $body .= '<form method="post" action="/admin/settings/save" enctype="multipart/form-data" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= AdminLayout::section('Change guidance', $this->changeGuidance(), 'Review these notes before changing site-wide configuration.');
        $body .= $this->section('Identity', 'Public site name and supporting text.', '<div class="bp-form-grid">' . $this->input('Site Name', 'name', (string)($site['name'] ?? '')) . $this->input('Tagline', 'tagline', (string)($site['tagline'] ?? '')) . '</div>');
        $body .= $this->section('Branding', 'Control the public header identity and browser favicon.', $this->brandingField($site));
        $body .= $this->section('URLs', 'Canonical public URL used for links, feeds, and update metadata.', $this->input('Base URL', 'base_url', (string)($site['base_url'] ?? '')));
        $body .= $this->section('Localization', 'Locale and timezone used for date formatting and future language-aware features.', '<div class="bp-form-grid">' . $this->input('Locale', 'locale', (string)($site['locale'] ?? 'en')) . $this->input('Timezone', 'timezone', (string)($site['timezone'] ?? 'UTC')) . '</div>');
        $body .= $this->section('Editor', 'Configure the body editor used by pages and posts.', '<div class="bp-form-grid">' . $this->editorSelect((string)($editor['body_editor'] ?? 'rich_html')) . $this->input('Editor Height', 'editor_html_height', (string)($editor['html_height'] ?? '24rem')) . '<label class="bp-field-wide">HTML Toolbar <input type="text" name="editor_html_toolbar" value="' . $this->e((string)($editor['html_toolbar'] ?? 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source')) . '" required><span class="bp-field-help">Space-separated Batoi UIF editor commands.</span></label></div>');
        $body .= $this->section('Theme', 'Current frontend theme and shared public shell templates.', '<dl class="bp-meta-list"><div><dt>Active theme</dt><dd>' . $this->e((string)($site['theme'] ?? 'default')) . '</dd></div></dl><p>' . AdminLayout::buttonLink('Manage Themes', '/admin/themes', 'code', true) . AdminLayout::buttonLink('Edit Templates', '/admin/theme-templates', 'code', true) . '</p>');
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin', 'back', true) . AdminLayout::submitButton('Save Settings', 'save') . '</div></form>';
        return $body;
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Settings', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $original = $this->config->site();
        $site = $original;
        foreach (['name', 'tagline', 'base_url', 'locale', 'timezone'] as $key) {
            $site[$key] = $request->input($key);
        }
        $site['theme'] = $site['theme'] ?? 'default';
        $site['brand_display'] = in_array($request->input('brand_display'), ['text', 'logo', 'logo_with_text'], true)
            ? $request->input('brand_display')
            : 'text';
        $site['brand_logo_alt'] = trim($request->input('brand_logo_alt')) !== '' ? trim($request->input('brand_logo_alt')) : $site['name'];
        $bodyEditor = in_array($request->input('editor_body_editor'), ['rich_html', 'source_html'], true) ? $request->input('editor_body_editor') : 'rich_html';
        $editor = [
            'body_editor' => $bodyEditor,
            'html_toolbar' => trim($request->input('editor_html_toolbar')) !== '' ? trim($request->input('editor_html_toolbar')) : 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source',
            'html_height' => trim($request->input('editor_html_height')) !== '' ? trim($request->input('editor_html_height')) : '24rem',
        ];

        $branding = new BrandAssetManager($this->config->paths());
        $newFiles = [];
        try {
            $logo = $branding->saveUpload((array)($_FILES['brand_logo'] ?? []), 'logo');
            if ($logo !== null) {
                $newFiles[] = $logo;
                $site['brand_logo'] = $logo;
            } elseif ($request->input('remove_brand_logo') === '1') {
                unset($site['brand_logo']);
                $site['brand_display'] = 'text';
            }

            $favicon = $branding->saveUpload((array)($_FILES['favicon'] ?? []), 'favicon');
            if ($favicon !== null) {
                $newFiles[] = $favicon;
                $site['favicon'] = $favicon;
            } elseif ($request->input('remove_favicon') === '1') {
                unset($site['favicon']);
            }

            if ($site['brand_display'] !== 'text' && $branding->resolveUrl((string)($site['brand_logo'] ?? '')) === null) {
                throw new RuntimeException('Upload a valid brand logo before selecting a logo display mode.');
            }

            $this->files->writeJson($this->config->paths()->configPath('editor.json'), $editor);
            $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
        } catch (RuntimeException $exception) {
            foreach ($newFiles as $newFile) {
                $branding->removeOwned($newFile);
            }
            return Response::html($this->layout('Settings', $this->form($site, $editor, $exception->getMessage())), 400);
        }

        foreach (['brand_logo', 'favicon'] as $key) {
            $before = (string)($original[$key] ?? '');
            $after = (string)($site[$key] ?? '');
            if ($before !== '' && $before !== $after) {
                $branding->removeOwned($before);
            }
            if ($before !== $after) {
                $action = 'branding.' . $key . '.' . ($after === '' ? 'removed' : ($before === '' ? 'uploaded' : 'replaced'));
                $this->audit->record((string)($this->user['username'] ?? 'admin'), $action, $after !== '' ? $after : $before, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            }
        }
        if ((string)($original['brand_display'] ?? 'text') !== (string)$site['brand_display']) {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'branding.display.updated', (string)$site['brand_display'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'settings.updated', 'site', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/settings');
    }

    private function input(string $label, string $name, string $value): string
    {
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '" required></label>';
    }

    private function brandingField(array $site): string
    {
        $manager = new BrandAssetManager($this->config->paths());
        $branding = $manager->branding($site);
        $logo = (string)($branding['logo_url'] ?? '');
        $logoPreview = '<div class="bp-branding-current"><strong>Current public identity</strong>'
            . ($logo !== '' ? '<img src="' . $this->e($logo) . '" alt="' . $this->e((string)$branding['logo_alt']) . '">' : '<span>' . $this->e((string)$branding['site_name']) . '</span>')
            . '<small>Effective mode: ' . $this->e(str_replace('_', ' + ', (string)$branding['display'])) . '</small></div>';

        $display = (string)($site['brand_display'] ?? 'text');
        $modes = '<fieldset class="bp-field-wide"><legend>Header identity</legend><div class="bp-segmented-control">';
        foreach (['text' => 'Text', 'logo' => 'Logo', 'logo_with_text' => 'Logo + text'] as $value => $label) {
            $modes .= '<label><input type="radio" name="brand_display" value="' . $this->e($value) . '"' . ($display === $value ? ' checked' : '') . '><span>' . $this->e($label) . '</span></label>';
        }
        $modes .= '</div></fieldset>';

        $logoFields = '<label>Logo alt text <input type="text" name="brand_logo_alt" value="' . $this->e((string)($site['brand_logo_alt'] ?? $site['name'] ?? '')) . '"><span class="bp-field-help">Defaults to the site name.</span></label>'
            . '<label>Upload brand logo <input type="file" name="brand_logo" accept=".svg,.png,.jpg,.jpeg,.gif,.webp,image/svg+xml,image/png,image/jpeg,image/gif,image/webp"><span class="bp-field-help">SVG, PNG, JPG, GIF, or WebP up to 2 MB and 4096 x 4096.</span></label>';
        if ((string)($site['brand_logo'] ?? '') !== '') {
            $logoFields .= '<label class="bp-field-wide"><input type="checkbox" name="remove_brand_logo" value="1"> Remove current brand logo</label>';
        }

        $favicon = (string)($site['favicon'] ?? '');
        $fallbackUrl = $this->defaultFaviconDataUri() ?: $this->assetUrl('/assets/img/batoi-press/press-color-tile-32.png');
        $effectiveFavicon = (string)($branding['favicon_url'] ?? '');
        $previewUrl = $effectiveFavicon !== '' ? (str_starts_with($effectiveFavicon, '/assets/images/site/') ? $effectiveFavicon : $this->assetUrl($effectiveFavicon)) : $fallbackUrl;
        $previewLabel = $favicon !== '' && $manager->resolveUrl($favicon) !== null ? 'Current favicon' : 'Current favicon (default)';
        $notice = $favicon !== '' && $manager->resolveUrl($favicon) === null ? '<small>Configured favicon file was not found. Showing the default Batoi Press icon.</small>' : '';
        $preview = '<div class="bp-favicon-current"><span>' . $this->e($previewLabel) . '</span><img src="' . $this->e($previewUrl) . '" alt="' . ($favicon !== '' ? 'Configured favicon preview' : 'Default Batoi Press favicon') . '">' . $notice . '</div>';
        $faviconFields = '<label class="bp-field-wide">Upload favicon <input type="file" name="favicon" accept=".ico,.png,.jpg,.jpeg,.webp,.svg,image/x-icon,image/png,image/jpeg,image/webp,image/svg+xml"><span class="bp-field-help">SVG, ICO, PNG, JPG, or WebP up to 1 MB. The admin favicon remains the Batoi Press logo.</span></label>';
        if ($favicon !== '') {
            $faviconFields .= '<label class="bp-field-wide"><input type="checkbox" name="remove_favicon" value="1"> Restore default favicon</label>';
        }

        return '<div class="bp-form-grid">' . $logoPreview . $preview . $modes . $logoFields . $faviconFields . '</div>';
    }

    private function editorSelect(string $value): string
    {
        $rich = $value === 'rich_html' ? ' selected' : '';
        $source = $value === 'source_html' ? ' selected' : '';
        return '<label>Body Editor <select name="editor_body_editor"><option value="rich_html"' . $rich . '>Batoi UIF Rich HTML</option><option value="source_html"' . $source . '>HTML Source</option></select><span class="bp-field-help">' . $this->e(ContentEditor::storageDescription()) . '</span></label>';
    }

    private function section(string $title, string $description, string $body): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }

    private function changeGuidance(): string
    {
        return '<div class="bp-admin-guidance-grid">'
            . $this->guidanceCard('Public identity', 'Name, logo, favicon, locale, and timezone affect public presentation.', 'site')
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

    private function assetUrl(string $path): string
    {
        $file = $this->publicFile($path);
        return '/' . ltrim($path, '/') . (is_file($file) ? '?v=' . filemtime($file) : '');
    }

    private function defaultFaviconDataUri(): string
    {
        $file = $this->publicFile('/assets/img/batoi-press/press-color-tile-32.png');
        $contents = is_file($file) ? file_get_contents($file) : false;
        return is_string($contents) && $contents !== '' ? 'data:image/png;base64,' . base64_encode($contents) : '';
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
