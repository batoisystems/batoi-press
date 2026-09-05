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
    private const TYPES = [
        'html' => 'Custom HTML',
        'recent_posts' => 'Recent Posts',
        'tag_cloud' => 'Tag Cloud',
        'image_gallery' => 'Image Gallery',
        'activity_calendar' => 'Activity Calendar',
        'subscribe' => 'Subscribe',
    ];

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
            $type = isset(self::TYPES[(string)($widget['type'] ?? '')]) ? (string)$widget['type'] : 'html';
            $requestedTarget = (string)($widget['target'] ?? 'all_sidebars');
            $target = in_array($requestedTarget, ['all_sidebars', 'left_sidebar', 'right_sidebar'], true) ? $requestedTarget : 'all_sidebars';
            $rows .= '<section class="bp-reorder-row"><div class="bp-reorder-actions"><button type="button" data-bp-move="up" aria-label="Move widget up">↑</button><button type="button" data-bp-move="down" aria-label="Move widget down">↓</button></div>';
            $rows .= '<label>Widget type <select name="widget_type[]">';
            foreach (self::TYPES as $value => $label) {
                $rows .= '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
            }
            $rows .= '</select></label><label>Target section <select name="widget_target[]"><option value="all_sidebars"' . ($target === 'all_sidebars' ? ' selected' : '') . '>All post sidebars</option><option value="left_sidebar"' . ($target === 'left_sidebar' ? ' selected' : '') . '>Left sidebar</option><option value="right_sidebar"' . ($target === 'right_sidebar' ? ' selected' : '') . '>Right sidebar</option></select></label>';
            $rows .= '<label>Title <input type="text" name="widget_title[]" value="' . $this->e((string)($widget['title'] ?? ($type === 'recent_posts' ? 'Recent posts' : ''))) . '"></label><label>Widget content <textarea name="widget_body[]" rows="5">' . $this->e((string)($widget['body'] ?? '')) . '</textarea><span class="bp-field-help">Used by Custom HTML, Image Gallery, and Subscribe widgets. Enter sanitized HTML.</span></label>';
            $rows .= '</section>';
        }
        $rows .= '</div><p class="bp-field-help">Built-in widgets include Recent Posts, Tag Cloud, Image Gallery, Activity Calendar, and Subscribe. Leave unused custom widgets blank; saved widgets appear in this order in their target section.</p>';
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
        $types = isset($request->post['widget_type']) && is_array($request->post['widget_type']) ? $request->post['widget_type'] : [];
        $targets = isset($request->post['widget_target']) && is_array($request->post['widget_target']) ? $request->post['widget_target'] : [];
        $titles = isset($request->post['widget_title']) && is_array($request->post['widget_title']) ? $request->post['widget_title'] : [];
        $bodies = isset($request->post['widget_body']) && is_array($request->post['widget_body']) ? $request->post['widget_body'] : [];
        $html = new HtmlContent();
        $widgets = [];
        foreach ($titles as $index => $title) {
            $type = isset(self::TYPES[(string)($types[$index] ?? '')]) ? (string)$types[$index] : 'html';
            $target = in_array(($targets[$index] ?? ''), ['all_sidebars', 'left_sidebar', 'right_sidebar'], true) ? (string)$targets[$index] : 'all_sidebars';
            if ($type === 'recent_posts') {
                if (!array_filter($widgets, static fn (array $widget): bool => ($widget['type'] ?? '') === 'recent_posts')) {
                    $widgets[] = ['type' => 'recent_posts', 'title' => trim((string)$title) ?: 'Recent posts', 'target' => $target];
                }
                continue;
            }
            $title = trim((string)$title);
            $body = $html->sanitize((string)($bodies[$index] ?? ''));
            $needsBody = in_array($type, ['html', 'image_gallery', 'subscribe'], true);
            if ($title !== '' && (!$needsBody || $body !== '')) {
                $widgets[] = ['type' => $type, 'title' => $title, 'body' => $body, 'target' => $target];
            }
        }
        if (!array_filter($widgets, static fn (array $widget): bool => ($widget['type'] ?? '') === 'recent_posts')) {
            array_unshift($widgets, ['type' => 'recent_posts', 'title' => 'Recent posts', 'target' => 'all_sidebars']);
        }
        $this->files->writeJson($this->config->paths()->contentPath('widgets/sidebar.json'), ['widgets' => $widgets]);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'widgets.updated', 'sidebar', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/widgets');
    }

    private function widgets(): array
    {
        $path = $this->config->paths()->contentPath('widgets/sidebar.json');
        $widgets = is_file($path) ? array_values((array)($this->files->readJson($path)['widgets'] ?? [])) : [];
        foreach ($widgets as $widget) {
            if (($widget['type'] ?? '') === 'recent_posts') {
                return $widgets;
            }
        }
        array_unshift($widgets, ['type' => 'recent_posts', 'title' => 'Recent posts', 'target' => 'all_sidebars']);
        return $widgets;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
