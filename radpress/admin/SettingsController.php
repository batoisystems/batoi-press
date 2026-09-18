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
use Batoi\Press\Security\SecretStore;
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
        return Response::html($this->layout('Settings', $this->form($this->config->site(), $this->config->editor(), '', $this->config->integrations())));
    }

    private function form(array $site, array $editor, string $error = '', ?array $integrations = null): string
    {
        $integrations ??= $this->config->integrations();
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
        $body .= $this->section('Appearance', 'Set public color mode, brand colors, typography, and footer text.', $this->appearanceFields($site));
        $body .= $this->section('URLs', 'Canonical public URL used for links, feeds, and update metadata.', $this->input('Base URL', 'base_url', (string)($site['base_url'] ?? '')));
        $body .= $this->section('Localization', 'Locale and timezone used for date formatting and future language-aware features.', '<div class="bp-form-grid">' . $this->input('Locale', 'locale', (string)($site['locale'] ?? 'en')) . $this->timezoneSelect((string)($site['timezone'] ?? 'UTC')) . '</div>');
        $body .= $this->section('Posts', 'Control the public blog listing without changing individual posts.', '<div class="bp-form-grid"><label>Posts per page <input type="number" name="posts_per_page" min="1" max="48" value="' . $this->e((string)max(1, min(48, (int)($site['posts_per_page'] ?? 12)))) . '" required><span class="bp-field-help">The blog adds Previous and Next navigation when more posts are available.</span></label></div>');
        $body .= $this->section('Editor', 'Configure the body editor used by pages and posts.', '<div class="bp-form-grid">' . $this->editorSelect((string)($editor['body_editor'] ?? 'rich_html')) . $this->input('Editor Height', 'editor_html_height', (string)($editor['html_height'] ?? '24rem')) . '<label class="bp-field-wide">HTML Toolbar <input type="text" name="editor_html_toolbar" value="' . $this->e((string)($editor['html_toolbar'] ?? 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source')) . '" required><span class="bp-field-help">Space-separated Batoi UIF editor commands.</span></label></div>');
        $body .= $this->section('Mail and integrations', 'Configure contact delivery, human verification, and analytics.', $this->integrationFields($integrations));
        $body .= $this->section('Import site content', 'Import pages, posts, and base64-encoded media from a Batoi Press XML document.', '<p class="bp-field-help">The import is additive: existing slugs are skipped and no current content is deleted.</p><p>' . AdminLayout::buttonLink('Open XML Import', '/admin/import', 'upload', true) . '</p>');
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
        $site['posts_per_page'] = max(1, min(48, (int)$request->input('posts_per_page', '12')));
        $site['appearance_mode'] = in_array($request->input('appearance_mode'), ['light', 'dark', 'system'], true) ? $request->input('appearance_mode') : 'system';
        $site['show_theme_toggle'] = $request->input('show_theme_toggle') === '1';
        $site['posts_load_more'] = $request->input('posts_load_more') === '1';
        foreach (['light', 'dark'] as $mode) {
            foreach (\Batoi\Press\Core\Appearance::palette($site, $mode) as $key => $fallback) {
                $value = $request->input('palette_' . $mode . '_' . $key, $fallback);
                $site['palette_' . $mode][$key] = preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtoupper($value) : $fallback;
            }
        }
        foreach (['brand_primary_color' => '#0E68B0', 'brand_accent_color' => '#00B696'] as $field => $fallback) {
            $candidate = strtoupper(trim($request->input($field)));
            $site[$field] = preg_match('/^#[0-9A-F]{6}$/', $candidate) === 1 ? $candidate : $fallback;
        }
        $site['font_family'] = substr(trim($request->input('font_family')), 0, 120);
        $fontUrl = trim($request->input('font_stylesheet_url'));
        $site['font_stylesheet_url'] = $fontUrl === '' || (filter_var($fontUrl, FILTER_VALIDATE_URL) && str_starts_with(strtolower($fontUrl), 'https://')) ? $fontUrl : '';
        $site['footer_text'] = substr(trim($request->input('footer_text')), 0, 500);
        $site['footer_bottom_text'] = substr(trim($request->input('footer_bottom_text')), 0, 500);
        $site['footer_icon_links'] = substr(trim($request->input('footer_icon_links')), 0, 4000);
        foreach (['footer_top_columns', 'footer_bottom_columns'] as $field) $site[$field] = max(1, min(4, (int)$request->input($field, '2')));
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
        $integrations = $this->config->integrations();
        $integrations['mail_provider'] = in_array($request->input('mail_provider'), ['disabled', 'php_mail', 'mailgun'], true) ? $request->input('mail_provider') : 'disabled';
        $integrations['mail_from'] = trim($request->input('mail_from'));
        $integrations['mail_to'] = trim($request->input('mail_to'));
        $integrations['mailgun_domain'] = trim($request->input('mailgun_domain'));
        $integrations['mailgun_region'] = $request->input('mailgun_region') === 'eu' ? 'eu' : 'us';
        $integrations['recaptcha_site_key'] = trim($request->input('recaptcha_site_key'));
        $measurementId = strtoupper(trim($request->input('analytics_measurement_id')));
        $integrations['analytics_measurement_id'] = preg_match('/^G-[A-Z0-9]{4,20}$/', $measurementId) === 1 ? $measurementId : '';

        $branding = new BrandAssetManager($this->config->paths());
        $newFiles = [];
        try {
            if (!$this->isSupportedTimezone((string)$site['timezone'])) {
                throw new RuntimeException('Select a timezone supported by this server.');
            }
            foreach (['mail_from', 'mail_to'] as $mailField) {
                if ($integrations[$mailField] !== '' && filter_var($integrations[$mailField], FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException(ucwords(str_replace('_', ' ', $mailField)) . ' must be a valid email address.');
                }
            }
            $secretStore = new SecretStore($this->config->paths());
            if (trim($request->input('mailgun_api_key')) !== '') {
                $integrations['mailgun_api_key'] = $secretStore->encrypt(trim($request->input('mailgun_api_key')));
            } elseif ($request->input('remove_mailgun_api_key') === '1') {
                unset($integrations['mailgun_api_key']);
            }
            if (trim($request->input('recaptcha_secret_key')) !== '') {
                $integrations['recaptcha_secret_key'] = $secretStore->encrypt(trim($request->input('recaptcha_secret_key')));
            } elseif ($request->input('remove_recaptcha_secret_key') === '1') {
                unset($integrations['recaptcha_secret_key']);
            }
            if ($integrations['mail_provider'] === 'mailgun' && ($integrations['mailgun_domain'] === '' || empty($integrations['mailgun_api_key']))) {
                throw new RuntimeException('Mailgun requires a domain and API key.');
            }
            if (($integrations['recaptcha_site_key'] === '') !== empty($integrations['recaptcha_secret_key'])) {
                throw new RuntimeException('reCAPTCHA requires both a site key and a secret key.');
            }

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

            $darkLogo = $branding->saveUpload((array)($_FILES['brand_logo_dark'] ?? []), 'logo-dark');
            if ($darkLogo !== null) {
                $newFiles[] = $darkLogo;
                $site['brand_logo_dark'] = $darkLogo;
            } elseif ($request->input('remove_brand_logo_dark') === '1') {
                unset($site['brand_logo_dark']);
            }

            if ($site['brand_display'] !== 'text' && $branding->resolveUrl((string)($site['brand_logo'] ?? '')) === null) {
                throw new RuntimeException('Upload a valid brand logo before selecting a logo display mode.');
            }

            $this->files->writeJson($this->config->paths()->configPath('editor.json'), $editor);
            $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
            $this->files->writeJson($this->config->paths()->configPath('integrations.json'), $integrations);
        } catch (RuntimeException $exception) {
            foreach ($newFiles as $newFile) {
                $branding->removeOwned($newFile);
            }
            return Response::html($this->layout('Settings', $this->form($site, $editor, $exception->getMessage(), $integrations)), 400);
        }

        foreach (['brand_logo', 'brand_logo_dark', 'favicon'] as $key) {
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

    private function timezoneSelect(string $value): string
    {
        $groups = [];
        foreach ($this->supportedTimezones() as $timezone) {
            $parts = explode('/', $timezone, 2);
            $group = count($parts) === 2 ? $parts[0] : 'Global';
            $groups[$group][] = $timezone;
        }

        $options = '';
        if (!$this->isSupportedTimezone($value)) {
            $options .= '<option value="' . $this->e($value) . '" selected disabled>Unsupported configured timezone — ' . $this->e($value) . '</option>';
        }
        foreach ($groups as $group => $timezones) {
            $options .= '<optgroup label="' . $this->e($group) . '">';
            foreach ($timezones as $timezone) {
                $selected = $timezone === $value ? ' selected' : '';
                $options .= '<option value="' . $this->e($timezone) . '"' . $selected . '>' . $this->e(str_replace('_', ' ', $timezone)) . '</option>';
            }
            $options .= '</optgroup>';
        }

        return '<label>Timezone <select name="timezone" required>' . $options . '</select><span class="bp-field-help">All timezones available in this server&#039;s PHP timezone database.</span></label>';
    }

    private function supportedTimezones(): array
    {
        return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
    }

    private function isSupportedTimezone(string $timezone): bool
    {
        return in_array($timezone, $this->supportedTimezones(), true);
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
        $logoFields .= '<label>Dark-mode logo <input type="file" name="brand_logo_dark" accept=".svg,.png,.jpg,.jpeg,.gif,.webp,image/svg+xml,image/png,image/jpeg,image/gif,image/webp"><span class="bp-field-help">Optional alternate logo used on dark surfaces.</span></label>';
        if ((string)($site['brand_logo_dark'] ?? '') !== '') {
            $logoFields .= '<label><input type="checkbox" name="remove_brand_logo_dark" value="1"> Remove dark-mode logo</label>';
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

    private function integrationFields(array $integrations): string
    {
        $provider = (string)($integrations['mail_provider'] ?? 'disabled');
        $options = '';
        foreach (['disabled' => 'Disabled', 'php_mail' => 'Server mail()', 'mailgun' => 'Mailgun'] as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($provider === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $mailgunSet = !empty($integrations['mailgun_api_key']);
        $recaptchaSet = !empty($integrations['recaptcha_secret_key']);
        return '<div class="bp-form-grid">'
            . '<label>Mail provider <select name="mail_provider">' . $options . '</select></label>'
            . '<label>Default From email <input type="email" name="mail_from" value="' . $this->e((string)($integrations['mail_from'] ?? '')) . '"></label>'
            . '<label>Default To email <input type="email" name="mail_to" value="' . $this->e((string)($integrations['mail_to'] ?? '')) . '"></label>'
            . '<label>Mailgun domain <input type="text" name="mailgun_domain" value="' . $this->e((string)($integrations['mailgun_domain'] ?? '')) . '"></label>'
            . '<label>Mailgun region <select name="mailgun_region"><option value="us"' . (($integrations['mailgun_region'] ?? 'us') !== 'eu' ? ' selected' : '') . '>United States</option><option value="eu"' . (($integrations['mailgun_region'] ?? '') === 'eu' ? ' selected' : '') . '>Europe</option></select></label>'
            . '<label>Mailgun API key <input type="password" name="mailgun_api_key" value="" autocomplete="new-password" placeholder="' . ($mailgunSet ? 'Configured — leave blank to keep' : 'Enter private API key') . '"></label>'
            . ($mailgunSet ? '<label><input type="checkbox" name="remove_mailgun_api_key" value="1"> Remove saved Mailgun key</label>' : '')
            . '<label>reCAPTCHA site key <input type="text" name="recaptcha_site_key" value="' . $this->e((string)($integrations['recaptcha_site_key'] ?? '')) . '"></label>'
            . '<label>reCAPTCHA secret key <input type="password" name="recaptcha_secret_key" value="" autocomplete="new-password" placeholder="' . ($recaptchaSet ? 'Configured — leave blank to keep' : 'Enter secret key') . '"></label>'
            . ($recaptchaSet ? '<label><input type="checkbox" name="remove_recaptcha_secret_key" value="1"> Remove saved reCAPTCHA secret</label>' : '')
            . '<label>Google Analytics measurement ID <input type="text" name="analytics_measurement_id" value="' . $this->e((string)($integrations['analytics_measurement_id'] ?? '')) . '" placeholder="G-XXXXXXXXXX"></label>'
            . '<p class="bp-field-wide bp-field-help">Private keys are encrypted in local data storage and are never rendered back into this form.</p></div>';
    }

    private function appearanceFields(array $site): string
    {
        $mode = (string)($site['appearance_mode'] ?? 'system');
        $options = '';
        foreach (['system' => 'Follow device', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($mode === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $paletteFields = '';
        foreach (['light', 'dark'] as $paletteMode) {
            $paletteFields .= '<details class="bp-field-wide"><summary>' . ucfirst($paletteMode) . ' theme colors (default theme)</summary><div class="bp-form-grid">';
            foreach (\Batoi\Press\Core\Appearance::palette($site, $paletteMode) as $key => $color) {
                $paletteFields .= '<label>' . \Batoi\Press\Core\Appearance::LABELS[$key] . ' <input type="color" name="palette_' . $paletteMode . '_' . $key . '" value="' . $this->e($color) . '"></label>';
            }
            $paletteFields .= '</div></details>';
        }
        return '<div class="bp-form-grid"><label>Color mode <select name="appearance_mode">' . $options . '</select></label>'
            . '<label><input type="checkbox" name="show_theme_toggle" value="1"' . (!empty($site['show_theme_toggle']) ? ' checked' : '') . '> Show visitor light/dark switch</label>'
            . '<label><input type="checkbox" name="posts_load_more" value="1"' . (!empty($site['posts_load_more']) ? ' checked' : '') . '> Enable Load More on post archives (default theme)</label>' . $paletteFields
            . '<label>Primary color <input type="color" name="brand_primary_color" value="' . $this->e((string)($site['brand_primary_color'] ?? '#0E68B0')) . '"></label>'
            . '<label>Accent color <input type="color" name="brand_accent_color" value="' . $this->e((string)($site['brand_accent_color'] ?? '#00B696')) . '"></label>'
            . '<label>Font family <input type="text" name="font_family" value="' . $this->e((string)($site['font_family'] ?? 'proxima-nova')) . '" placeholder="proxima-nova"></label>'
            . '<label>Font stylesheet URL <input type="url" name="font_stylesheet_url" value="' . $this->e((string)($site['font_stylesheet_url'] ?? '')) . '" placeholder="https://fonts.googleapis.com/..."><span class="bp-field-help">Optional HTTPS Google Fonts or custom hosted stylesheet.</span></label>'
            . '<label>Top footer columns <input type="number" name="footer_top_columns" min="1" max="4" value="' . max(1,min(4,(int)($site['footer_top_columns'] ?? 2))) . '"><span class="bp-field-help">Arrange top-level Footer menu groups. Mobile layouts stack.</span></label>'
            . '<label>Bottom footer columns <input type="number" name="footer_bottom_columns" min="1" max="4" value="' . max(1,min(4,(int)($site['footer_bottom_columns'] ?? 2))) . '"></label>'
            . '<label class="bp-field-wide">Bottom footer text <textarea name="footer_bottom_text" rows="2" maxlength="500">' . $this->e((string)($site['footer_bottom_text'] ?? '')) . '</textarea></label>'
            . '<label class="bp-field-wide">Bottom footer icon links <textarea name="footer_icon_links" rows="4" maxlength="4000">' . $this->e((string)($site['footer_icon_links'] ?? '')) . '</textarea><span class="bp-field-help">One link per line: label | HTTPS URL or /local-path | icon symbol. Example: RSS | /feed.xml | ↗. Labels stay visible for accessibility. Up to 12 links.</span></label>'
            . '<label class="bp-field-wide">Footer text <textarea name="footer_text" rows="3" maxlength="500">' . $this->e((string)($site['footer_text'] ?? '')) . '</textarea></label></div>';
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
