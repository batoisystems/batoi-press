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
        $body = '<h1>Settings</h1><form method="post" action="/admin/settings/save" class="bp-form">';
        $body .= $this->csrf->field();
        $body .= $this->input('Site Name', 'name', (string)($site['name'] ?? ''));
        $body .= $this->input('Tagline', 'tagline', (string)($site['tagline'] ?? ''));
        $body .= $this->input('Base URL', 'base_url', (string)($site['base_url'] ?? ''));
        $body .= $this->input('Locale', 'locale', (string)($site['locale'] ?? 'en'));
        $body .= $this->input('Timezone', 'timezone', (string)($site['timezone'] ?? 'UTC'));
        $body .= '<button type="submit">Save Settings</button></form><p><a href="/admin">Back to admin</a></p>';
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

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
