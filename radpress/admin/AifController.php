<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Aif\AifContext;
use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;

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
            'Govern native content intelligence, provider trust boundaries, and assisted-authoring capabilities.'
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

        $body .= '<section class="bp-admin-section"><header><div><h2>Editor integration</h2><p>Enabled capabilities appear inside page and post editors, where suggestions can be reviewed and deliberately applied.</p></div></header>';
        if (!$enabled || !$available) {
            $body .= '<p class="bp-notice">Batoi AIF is currently disabled or unavailable. Action buttons are disabled to avoid implying that AI assistance is active.</p>';
        }
        $body .= '<div class="bp-admin-nav">' . AdminLayout::buttonLink('Open Page Editor', '/admin/pages', 'edit', true) . AdminLayout::buttonLink('Open Post Editor', '/admin/posts', 'edit', true) . '</div></section>';

        $body .= AdminLayout::section('Configuration file', '<dl class="bp-meta-list"><div><dt>File</dt><dd><code>radpress/config/aif.json</code></dd></div><div><dt>Provider</dt><dd>' . $this->e((string)($status['provider'] ?? 'disabled')) . '</dd></div><div><dt>Feature count</dt><dd>' . count($features) . '</dd></div></dl><p class="bp-field-help">Provider credentials and remote workspace settings should not be stored in theme templates or public files.</p>', 'Review the configuration source used by this installation.');

        return Response::html($this->layout('Batoi AIF', $body));
    }

    public function assist(Request $request): Response
    {
        $json = $request->input('format') === 'json' || str_contains(strtolower($request->header('Accept')), 'application/json');
        $requestId = $this->requestId($request);
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            $this->record('aif.assist_failed', 'csrf', $request, 'blocked');
            return $this->assistResponse(['ok' => false, 'error' => 'Security token expired.', 'request_id' => $requestId], 400, $json);
        }

        $limiter = new RateLimiter($this->config->paths(), 30, 3600);
        $limitKey = 'aif-assist:' . (string)($this->user['username'] ?? 'admin') . ':' . (string)($request->server['REMOTE_ADDR'] ?? '');
        if ($limiter->tooManyAttempts($limitKey)) {
            $this->record('aif.assist_failed', 'rate-limit', $request, 'blocked');
            return $this->assistResponse(['ok' => false, 'error' => 'Batoi AIF request limit reached. Try again later.', 'request_id' => $requestId], 429, $json, ['Retry-After' => '3600']);
        }

        $task = $request->input('task');
        if (!array_key_exists($task, $this->assistTasks())) {
            $this->record('aif.assist_failed', $task, $request, 'failed');
            return $this->assistResponse(['ok' => false, 'error' => 'Unknown AIF task.', 'request_id' => $requestId], 400, $json);
        }

        $context = [];
        foreach (['content_type', 'title', 'body', 'seo_title', 'seo_description', 'subtitle', 'category', 'tags', 'featured_image', 'featured_image_alt'] as $field) {
            $context[$field] = $request->input($field);
        }
        $prepared = AifContext::prepare($context);
        $limiter->hit($limitKey);
        $result = (new AifManager($this->config->aif()))->assist($task, $context);
        $result['request_id'] = $requestId;
        $this->record(($result['ok'] ?? false) ? 'aif.assist' : 'aif.assist_failed', $task, $request, ($result['ok'] ?? false) ? 'success' : 'failed', [
            'provider' => (string)($result['provider'] ?? $this->config->aif()['provider'] ?? 'disabled'),
            'context' => AifContext::safeMetadata($prepared),
            'request_id' => $requestId,
            'network_used' => ($result['network_used'] ?? false) === true,
        ]);

        return $this->assistResponse($result, ($result['ok'] ?? false) ? 200 : 409, $json);
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
            'content_health' => 'Check Content',
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
            'content_health' => 'Check metadata, structure, length, and image accessibility.',
            default => 'Configured assist capability.',
        };
    }

    private function assistResponse(array $result, int $status, bool $json, array $headers = []): Response
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'] + $headers;
        if ($json) {
            return Response::json($result, $status, $headers);
        }
        $class = ($result['ok'] ?? false) ? 'bp-notice' : 'bp-error';
        $message = (string)($result['message'] ?? $result['error'] ?? 'AIF request completed.');
        $details = '';
        if (($result['ok'] ?? false) && isset($result['suggestions'])) {
            $encoded = json_encode($result['suggestions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $details = '<pre><code>' . $this->e(is_string($encoded) ? $encoded : '') . '</code></pre>';
        }
        return Response::html($this->layout('Batoi AIF', '<p class="' . $class . '">' . $this->e($message) . '</p>' . $details . '<p><a href="/admin/aif">Back to AIF</a></p>'), $status)->withHeader('Cache-Control', 'private, no-store');
    }

    private function requestId(Request $request): string
    {
        $provided = $request->header('X-Request-Id');
        return preg_match('/^[A-Za-z0-9._-]{8,128}$/D', $provided) === 1 ? $provided : 'aif_' . bin2hex(random_bytes(10));
    }

    private function record(string $action, string $target, Request $request, string $outcome, array $details = []): void
    {
        $this->audit?->record((string)($this->user['username'] ?? 'admin'), $action, $target, (string)($request->server['REMOTE_ADDR'] ?? ''), $outcome, [
            'method' => $request->method,
            'route' => $request->path,
        ] + $details);
    }
}
