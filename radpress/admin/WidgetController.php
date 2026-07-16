<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class WidgetController
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
        $widgets = $this->widgets();
        $rows = '<div class="bp-widget-rows" data-bp-reorder-list>';
        for ($index = 0, $count = max(4, count($widgets) + 1); $index < $count; $index++) {
            $widget = $widgets[$index] ?? [];
            $rows .= '<section class="bp-reorder-row"><div class="bp-reorder-actions"><button type="button" data-bp-move="up" aria-label="Move widget up">↑</button><button type="button" data-bp-move="down" aria-label="Move widget down">↓</button></div><label>Title <input type="text" name="widget_title[]" value="' . $this->e((string)($widget['title'] ?? '')) . '"></label><label>Widget HTML <textarea name="widget_body[]" rows="5">' . $this->e((string)($widget['body'] ?? '')) . '</textarea></label></section>';
        }
        $rows .= '</div><p class="bp-field-help">Leave unused widgets blank. Widgets appear in their saved order on sidebar post layouts; recent posts are added automatically after them.</p>';
        $body = AdminLayout::pageHeader('Widgets', 'Manage reusable sidebar content for post layouts.');
        $body .= '<form method="post" action="/admin/widgets/save" class="bp-form bp-admin-editor">' . $this->csrf->field();
        $body .= '<div class="bp-editor-main">' . AdminLayout::section('Sidebar widgets', $rows, 'Sanitized HTML widgets shared by left and right post sidebars.') . '</div>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin', 'back', true) . AdminLayout::submitButton('Save Widgets', 'save') . '</div></form>';
        return Response::html(AdminLayout::render('Widgets', $body));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html(AdminLayout::message('Widgets', 'Security token expired.', true), 400);
        }
        $titles = isset($request->post['widget_title']) && is_array($request->post['widget_title']) ? $request->post['widget_title'] : [];
        $bodies = isset($request->post['widget_body']) && is_array($request->post['widget_body']) ? $request->post['widget_body'] : [];
        $html = new HtmlContent();
        $widgets = [];
        foreach ($titles as $index => $title) {
            $title = trim((string)$title);
            $body = $html->sanitize((string)($bodies[$index] ?? ''));
            if ($title !== '' && $body !== '') {
                $widgets[] = ['title' => $title, 'body' => $body];
            }
        }
        $this->files->writeJson($this->config->paths()->contentPath('widgets/sidebar.json'), ['widgets' => $widgets]);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'widgets.updated', 'sidebar', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/widgets');
    }

    private function widgets(): array
    {
        $path = $this->config->paths()->contentPath('widgets/sidebar.json');
        return is_file($path) ? array_values((array)($this->files->readJson($path)['widgets'] ?? [])) : [];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
