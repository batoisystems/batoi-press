<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class AifController
{
    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf
    ) {
    }

    public function index(): Response
    {
        $status = (new AifManager($this->config->aif()))->status();
        $body = '<h1>Batoi AIF</h1>';
        $body .= '<p>Batoi AIF is optional and disabled by default. This installation will not make AI network calls unless a future provider is explicitly configured.</p>';
        $body .= '<dl class="bp-stats">';
        $body .= '<div><dt>Enabled</dt><dd>' . (($status['enabled'] ?? false) ? 'Yes' : 'No') . '</dd></div>';
        $body .= '<div><dt>Provider</dt><dd>' . $this->e((string)($status['provider'] ?? 'disabled')) . '</dd></div>';
        $body .= '<div><dt>Available</dt><dd>' . (($status['available'] ?? false) ? 'Yes' : 'No') . '</dd></div>';
        $body .= '<div><dt>Workspace Required</dt><dd>' . (($status['workspace_required'] ?? false) ? 'Yes' : 'No') . '</dd></div>';
        $body .= '</dl>';
        $body .= '<h2>Feature Flags</h2><table class="bp-table"><thead><tr><th>Feature</th><th>Status</th></tr></thead><tbody>';
        foreach (($status['features'] ?? []) as $feature => $enabled) {
            $body .= '<tr><td><code>' . $this->e((string)$feature) . '</code></td><td>' . ($enabled ? 'Enabled' : 'Disabled') . '</td></tr>';
        }
        $body .= '</tbody></table>';
        $body .= '<p><a href="/admin">Back to admin</a></p>';
        $body .= '<form method="post" action="/admin/logout" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Log Out</button></form>';

        return Response::html($this->layout('Batoi AIF', $body));
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $this->e($title) . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"><script src="/assets/uif/uif.js" defer></script></head><body><main class="bp-admin bp-uif-surface">' . $body . '</main></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
