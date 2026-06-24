<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class AifController
{
    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly ?AuditLog $audit = null,
        private readonly array $user = []
    ) {
    }

    public function index(): Response
    {
        $status = (new AifManager($this->config->aif()))->status();
        $enabled = (bool)($status['enabled'] ?? false);
        $available = (bool)($status['available'] ?? false);
        $features = is_array($status['features'] ?? null) ? $status['features'] : [];

        $body = AdminLayout::pageHeader(
            'Batoi AIF',
            'Review AI integration status, trust boundaries, and future assisted publishing capabilities.'
        );
        $body .= $this->readinessPanel($enabled, $available);

        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $body .= AdminLayout::statCard('Enabled', $enabled ? 'Yes' : 'No', $enabled ? 'AIF is configured for this installation.' : 'Disabled by default.');
        $body .= AdminLayout::statCard('Provider', (string)($status['provider'] ?? 'disabled'), $available ? 'Provider reports available.' : 'No provider calls are available.');
        $body .= AdminLayout::statCard('Available', $available ? 'Yes' : 'No', $available ? 'Assist actions may run.' : 'Assist actions return disabled responses.');
        $body .= AdminLayout::statCard('Workspace required', ($status['workspace_required'] ?? false) ? 'Yes' : 'No', 'Future Batoi Platform workspace connection.');
        $body .= '</dl>';

        $body .= '<div class="bp-admin-grid">';
        $body .= AdminLayout::section('Trust boundary', $this->trustBoundary(), 'AIF is native but guarded. The default installation remains private and offline for AI.');
        $body .= AdminLayout::section('Setup requirements', $this->setupRequirements($enabled, $available, (bool)($status['workspace_required'] ?? false)), 'These requirements must be satisfied before assist features should be enabled.');
        $body .= '</div>';

        $body .= '<section class="bp-admin-section"><header><div><h2>Feature flags</h2><p>Configured AIF features and whether they are available to admin workflows.</p></div></header><div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Feature</th><th>Status</th><th>Purpose</th><th>Current behavior</th></tr></thead><tbody>';
        foreach ($features as $feature => $featureEnabled) {
            $body .= '<tr><td><code>' . $this->e((string)$feature) . '</code></td><td>' . $this->statusBadge((bool)$featureEnabled && $enabled) . '</td><td>' . $this->e($this->featurePurpose((string)$feature)) . '</td><td>' . $this->e(((bool)$featureEnabled && $enabled && $available) ? 'Assist action may run when called.' : 'Returns a disabled response; no provider request is made.') . '</td></tr>';
        }
        $body .= '</tbody></table></div></section>';

        $body .= '<section class="bp-admin-section"><header><div><h2>Content assist test actions</h2><p>Use these guarded actions only to verify configured feature behavior. Disabled installations return a clear disabled response and do not call a provider.</p></div></header>';
        if (!$enabled || !$available) {
            $body .= '<p class="bp-notice">Batoi AIF is currently disabled or unavailable. Action buttons are disabled to avoid implying that AI assistance is active.</p>';
        }
        $body .= '<form method="post" action="/admin/aif/assist" class="bp-admin-nav bp-uif-toolbar">' . $this->csrf->field();
        foreach ($this->assistTasks() as $task => $label) {
            $disabled = (!$enabled || !$available || empty($features[$task])) ? ' disabled aria-disabled="true"' : '';
            $body .= AdminLayout::submitButton($label, 'spark', 'name="task" value="' . $this->e($task) . '"' . $disabled);
        }
        $body .= '</form></section>';

        $body .= AdminLayout::section('Configuration file', '<dl class="bp-meta-list"><div><dt>File</dt><dd><code>radpress/config/aif.json</code></dd></div><div><dt>Provider</dt><dd>' . $this->e((string)($status['provider'] ?? 'disabled')) . '</dd></div><div><dt>Feature count</dt><dd>' . count($features) . '</dd></div></dl><p class="bp-field-help">Provider credentials and remote workspace settings should not be stored in theme templates or public files.</p>', 'Review the configuration source used by this installation.');

        return Response::html($this->layout('Batoi AIF', $body));
    }

    public function assist(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            $this->record('aif.assist_failed', 'csrf', $request, 'blocked');
            return Response::html($this->layout('Batoi AIF', '<p class="bp-error">Security token expired.</p><p><a href="/admin/aif">Back to AIF</a></p>'), 400);
        }

        $task = $request->input('task');
        if (!array_key_exists($task, $this->assistTasks())) {
            $this->record('aif.assist_failed', $task, $request, 'failed');
            return Response::html($this->layout('Batoi AIF', '<p class="bp-error">Unknown AIF task.</p><p><a href="/admin/aif">Back to AIF</a></p>'), 400);
        }

        $result = (new AifManager($this->config->aif()))->assist($task, []);
        $class = ($result['ok'] ?? false) ? 'bp-notice' : 'bp-error';
        $message = (string)($result['error'] ?? 'AIF request completed.');
        $this->record(($result['ok'] ?? false) ? 'aif.assist' : 'aif.assist_failed', $task, $request, ($result['ok'] ?? false) ? 'success' : 'failed');

        return Response::html($this->layout('Batoi AIF', '<p class="' . $class . '">' . $this->e($message) . '</p><p><a href="/admin/aif">Back to AIF</a></p>'), ($result['ok'] ?? false) ? 200 : 400);
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function assistTasks(): array
    {
        return [
            'draft_content' => 'Draft Content',
            'seo_assist' => 'SEO Description',
            'summarize' => 'Summarize',
            'tags' => 'Suggest Tags',
        ];
    }

    private function statusBadge(bool $enabled): string
    {
        return $enabled ? '<span class="bp-status-badge is-published">Enabled</span>' : '<span class="bp-status-badge is-draft">Disabled</span>';
    }

    private function readinessPanel(bool $enabled, bool $available): string
    {
        $state = $enabled && $available ? 'Ready' : 'Disabled';
        $message = $enabled && $available
            ? 'AIF is enabled and the configured provider reports available. Keep reviewing audit logs for each assist action.'
            : 'AIF is not active. The default installation makes no AI provider calls and assist actions remain unavailable.';

        return '<section class="bp-command-hero bp-aif-readiness"><div><p class="bp-section-kicker">AIF readiness</p><h1>' . $this->e($state) . '</h1><p>' . $this->e($message) . '</p></div><span class="bp-status-badge ' . ($enabled && $available ? 'is-published' : 'is-draft') . '">' . $this->e($state) . '</span></section>';
    }

    private function trustBoundary(): string
    {
        return '<ul class="bp-check-list"><li>No AI network calls are made while AIF is disabled.</li><li>Assist actions are explicit admin POST actions with CSRF protection and audit logging.</li><li>Provider configuration must declare feature flags before use.</li><li>Generated suggestions should be reviewed by an editor before publishing.</li></ul>';
    }

    private function setupRequirements(bool $enabled, bool $available, bool $workspaceRequired): string
    {
        $items = [
            ['Configuration enabled', $enabled],
            ['Provider available', $available],
            ['Workspace requirement satisfied', !$workspaceRequired],
            ['Audit logging active', true],
        ];

        $html = '<ul class="bp-aif-requirements">';
        foreach ($items as [$label, $ok]) {
            $html .= '<li><span class="bp-status-badge ' . ($ok ? 'is-published' : 'is-draft') . '">' . ($ok ? 'Ready' : 'Pending') . '</span><strong>' . $this->e((string)$label) . '</strong></li>';
        }
        return $html . '</ul>';
    }

    private function featurePurpose(string $feature): string
    {
        return match ($feature) {
            'draft_content' => 'Prepare first-draft page or post copy.',
            'seo_assist' => 'Suggest search metadata for content.',
            'summarize' => 'Summarize selected content for review.',
            'tags' => 'Suggest taxonomy terms for posts.',
            'translate' => 'Prepare translation assistance when localization is supported.',
            'alt_text' => 'Suggest accessible image descriptions.',
            default => 'Configured assist capability.',
        };
    }

    private function record(string $action, string $target, Request $request, string $outcome): void
    {
        $this->audit?->record((string)($this->user['username'] ?? 'admin'), $action, $target, (string)($request->server['REMOTE_ADDR'] ?? ''), $outcome, [
            'method' => $request->method,
            'route' => $request->path,
        ]);
    }
}
