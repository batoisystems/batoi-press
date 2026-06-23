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

        $body = AdminLayout::pageHeader(
            'Batoi AIF',
            'Review AI integration status, trust boundaries, and future assisted publishing capabilities.'
        );
        $body .= '<dl class="bp-admin-stats">';
        $body .= AdminLayout::statCard('Enabled', $enabled ? 'Yes' : 'No', $enabled ? 'AIF is configured for this installation.' : 'Disabled by default.');
        $body .= AdminLayout::statCard('Provider', (string)($status['provider'] ?? 'disabled'), $available ? 'Provider reports available.' : 'No provider calls are available.');
        $body .= AdminLayout::statCard('Available', $available ? 'Yes' : 'No', $available ? 'Assist actions may run.' : 'Assist actions return disabled responses.');
        $body .= AdminLayout::statCard('Workspace required', ($status['workspace_required'] ?? false) ? 'Yes' : 'No', 'Future Batoi Platform workspace connection.');
        $body .= '</dl>';

        $trust = '<ul class="bp-check-list"><li>No AI network calls are made while AIF is disabled.</li><li>Content assist actions are explicit admin POST actions with CSRF protection.</li><li>Future provider configuration must declare feature flags before use.</li></ul>';
        $body .= AdminLayout::section('Trust boundary', $trust, 'AIF is native but guarded. The default installation remains private and offline for AI.');

        $body .= '<section class="bp-admin-section"><header><div><h2>Feature flags</h2><p>Configured AIF features and whether they are available.</p></div></header><div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Feature</th><th>Status</th></tr></thead><tbody>';
        foreach (($status['features'] ?? []) as $feature => $enabled) {
            $body .= '<tr><td><code>' . $this->e((string)$feature) . '</code></td><td>' . $this->statusBadge((bool)$enabled) . '</td></tr>';
        }
        $body .= '</tbody></table></div></section>';

        $body .= '<section class="bp-admin-section"><header><div><h2>Content assist</h2><p>Guarded actions for future providers. Disabled installations return a clear disabled response.</p></div></header><form method="post" action="/admin/aif/assist" class="bp-admin-nav bp-uif-toolbar">' . $this->csrf->field();
        foreach ($this->assistTasks() as $task => $label) {
            $body .= AdminLayout::submitButton($label, 'spark', 'name="task" value="' . $this->e($task) . '"');
        }
        $body .= '</form></section>';

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

    private function record(string $action, string $target, Request $request, string $outcome): void
    {
        $this->audit?->record((string)($this->user['username'] ?? 'admin'), $action, $target, (string)($request->server['REMOTE_ADDR'] ?? ''), $outcome, [
            'method' => $request->method,
            'route' => $request->path,
        ]);
    }
}
