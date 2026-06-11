<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class MenuController
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
        $menu = $this->menu();
        $items = array_values((array)($menu['items'] ?? []));
        $body = AdminLayout::pageHeader(
            'Menus',
            'Manage the primary site navigation with structured labels and URLs.'
        );
        $body .= '<form method="post" action="/admin/menus/save" class="bp-form bp-admin-editor">';
        $body .= $this->csrf->field();

        $rows = '<div class="bp-menu-rows">';
        $count = max(5, count($items) + 2);
        for ($index = 0; $index < $count; $index++) {
            $item = $items[$index] ?? [];
            $rows .= '<div class="bp-menu-row"><label>Label <input type="text" name="item_label[]" value="' . $this->e((string)($item['label'] ?? '')) . '"></label><label>URL <input type="text" name="item_url[]" value="' . $this->e((string)($item['url'] ?? '')) . '" placeholder="/about"></label></div>';
        }
        $rows .= '</div><p class="bp-field-help">Leave unused rows blank. URLs can be relative paths such as <code>/about</code> or full external URLs.</p>';

        $preview = $items === [] ? '<p class="bp-muted">No menu items configured.</p>' : '<nav class="bp-menu-preview" aria-label="Menu preview">' . implode('', array_map(fn (array $item): string => '<a href="' . $this->e((string)($item['url'] ?? '#')) . '">' . $this->e((string)($item['label'] ?? 'Untitled')) . '</a>', $items)) . '</nav>';
        $legacy = '<details class="bp-details"><summary>Legacy line format</summary><label>Menu Items <textarea name="items" rows="8">' . $this->e($this->legacyLines($items)) . '</textarea><span class="bp-field-help">Backward-compatible format: one <code>Label|/url</code> item per line. Structured rows take priority when filled.</span></label></details>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Main menu', $rows, 'Edit visible navigation items in order.') . $this->editorPanel('Current preview', $preview, 'Preview of the currently saved menu.') . '</div><aside class="bp-editor-side">' . $this->editorPanel('Compatibility', $legacy, 'Keep support for imported line-based menus.') . '</aside>';
        $body .= '<div class="bp-form-actions"><a class="bp-button bp-button-secondary" href="/admin">Cancel</a><button type="submit">Save Menu</button></div></form>';
        return Response::html($this->layout('Menus', $body));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Menus', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $items = $this->itemsFromRows($request->post);
        if ($items === []) {
            $items = $this->itemsFromLines($request->input('items'));
        }

        $this->files->writeJson($this->config->paths()->contentPath('menus/main.json'), ['items' => $items]);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'menu.updated', 'main', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/menus');
    }

    private function menu(): array
    {
        $path = $this->config->paths()->contentPath('menus/main.json');
        return is_file($path) ? $this->files->readJson($path) : ['items' => []];
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function itemsFromRows(array $post): array
    {
        $labels = isset($post['item_label']) && is_array($post['item_label']) ? $post['item_label'] : [];
        $urls = isset($post['item_url']) && is_array($post['item_url']) ? $post['item_url'] : [];
        $items = [];
        foreach ($labels as $index => $label) {
            $label = trim((string)$label);
            $url = trim((string)($urls[$index] ?? ''));
            if ($label !== '' && $url !== '') {
                $items[] = ['label' => $label, 'url' => $url];
            }
        }
        return $items;
    }

    private function itemsFromLines(string $lines): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $items[] = ['label' => $parts[0], 'url' => $parts[1]];
            }
        }
        return $items;
    }

    private function legacyLines(array $items): string
    {
        return implode("\n", array_map(static fn (array $item): string => (string)($item['label'] ?? '') . '|' . (string)($item['url'] ?? ''), $items));
    }

    private function editorPanel(string $title, string $body, string $description): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }
}
