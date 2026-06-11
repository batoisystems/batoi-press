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
        $body = AdminLayout::pageHeader(
            'Settings',
            'Control site identity, URLs, localization, and active theme configuration.'
        );
        $body .= '<form method="post" action="/admin/settings/save" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= $this->section('Identity', 'Public site name and supporting text.', '<div class="bp-form-grid">' . $this->input('Site Name', 'name', (string)($site['name'] ?? '')) . $this->input('Tagline', 'tagline', (string)($site['tagline'] ?? '')) . '</div>');
        $body .= $this->section('URLs', 'Canonical public URL used for links, feeds, and update metadata.', $this->input('Base URL', 'base_url', (string)($site['base_url'] ?? '')));
        $body .= $this->section('Localization', 'Locale and timezone used for date formatting and future language-aware features.', '<div class="bp-form-grid">' . $this->input('Locale', 'locale', (string)($site['locale'] ?? 'en')) . $this->input('Timezone', 'timezone', (string)($site['timezone'] ?? 'UTC')) . '</div>');
        $body .= $this->section('Theme', 'Current frontend theme. Theme selection UI will expand in a later phase.', '<dl class="bp-meta-list"><div><dt>Active theme</dt><dd>' . $this->e((string)($site['theme'] ?? 'default')) . '</dd></div></dl>');
        $body .= '<div class="bp-form-actions"><a class="bp-button bp-button-secondary" href="/admin">Cancel</a><button type="submit">Save Settings</button></div></form>';
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
        $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'settings.updated', 'site', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/settings');
    }

    private function input(string $label, string $name, string $value): string
    {
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '" required></label>';
    }

    private function section(string $title, string $description, string $body): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
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
