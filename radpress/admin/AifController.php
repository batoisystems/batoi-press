<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
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
        $body .= '<h2>Content Assist</h2><p>These guarded actions are present for future providers and return a disabled response until AIF is configured.</p>';
        $body .= '<form method="post" action="/admin/aif/assist" class="bp-admin-nav bp-uif-toolbar">' . $this->csrf->field();
        foreach ($this->assistTasks() as $task => $label) {
            $body .= '<button type="submit" name="task" value="' . $this->e($task) . '">' . $this->e($label) . '</button>';
        }
        $body .= '</form>';
        $body .= '<p><a href="/admin">Back to admin</a></p>';
        $body .= '<form method="post" action="/admin/logout" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Log Out</button></form>';

        return Response::html($this->layout('Batoi AIF', $body));
    }

    public function assist(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Batoi AIF', '<p class="bp-error">Security token expired.</p><p><a href="/admin/aif">Back to AIF</a></p>'), 400);
        }

        $task = $request->input('task');
        if (!array_key_exists($task, $this->assistTasks())) {
            return Response::html($this->layout('Batoi AIF', '<p class="bp-error">Unknown AIF task.</p><p><a href="/admin/aif">Back to AIF</a></p>'), 400);
        }

        $result = (new AifManager($this->config->aif()))->assist($task, []);
        $class = ($result['ok'] ?? false) ? 'bp-notice' : 'bp-error';
        $message = (string)($result['error'] ?? 'AIF request completed.');

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
}
