<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Content\PageRepository;
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
        $site = $this->config->site();
        $body .= '<form method="post" action="/admin/menus/save" class="bp-form bp-admin-editor">';
        $body .= $this->csrf->field();

        $rows = '<div class="bp-menu-rows" data-bp-reorder-list>';
        $count = max(5, count($items) + 2);
        for ($index = 0; $index < $count; $index++) {
            $item = $items[$index] ?? [];
            $rows .= '<div class="bp-menu-row bp-reorder-row"><div class="bp-reorder-actions"><button type="button" data-bp-move="up" aria-label="Move menu item up">↑</button><button type="button" data-bp-move="down" aria-label="Move menu item down">↓</button></div><label>Label <input type="text" name="item_label[]" value="' . $this->e((string)($item['label'] ?? '')) . '"></label><label>URL <input type="text" name="item_url[]" value="' . $this->e((string)($item['url'] ?? '')) . '" placeholder="/about"></label><label>Parent URL <input type="text" name="item_parent[]" value="' . $this->e((string)($item['parent'] ?? '')) . '" list="bp-menu-parent-urls" placeholder="Top level"></label></div>';
        }
        $rows .= '</div><datalist id="bp-menu-parent-urls">';
        foreach ($items as $item) {
            $url = trim((string)($item['url'] ?? ''));
            if ($url !== '') {
                $rows .= '<option value="' . $this->e($url) . '">' . $this->e((string)($item['label'] ?? $url)) . '</option>';
            }
        }
        $rows .= '</datalist><p class="bp-field-help">Use Parent URL to nest an item below another item. Parent URLs may refer to another row entered in the same save. Leave unused rows blank.</p>';

        $preview = $items === [] ? '<p class="bp-muted">No menu items configured.</p>' : '<nav class="bp-menu-preview" aria-label="Menu preview">' . implode('', array_map(fn (array $item): string => '<span class="' . (!empty($item['parent']) ? 'is-child' : 'is-top-level') . '"><a href="' . $this->e((string)($item['url'] ?? '#')) . '">' . $this->e((string)($item['label'] ?? 'Untitled')) . '</a>' . (!empty($item['parent']) ? '<small>under ' . $this->e((string)$item['parent']) . '</small>' : '') . '</span>', $items)) . '</nav>';
        $legacy = '<details class="bp-details"><summary>Legacy line format</summary><label>Menu Items <textarea name="items" rows="8">' . $this->e($this->legacyLines($items)) . '</textarea><span class="bp-field-help">Backward-compatible format: <code>Label|/url</code>, with optional <code>|/parent-url</code> for sub-menus. Structured rows take priority when filled.</span></label></details>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Main menu', $rows, 'Edit visible navigation items in order.') . $this->editorPanel('Current preview', $preview, 'Preview of the currently saved menu.') . '</div><aside class="bp-editor-side">' . $this->editorPanel('Homepage', $this->homepageSelect((string)($site['homepage'] ?? 'home')), 'Choose the published page shown at the site root.') . $this->editorPanel('Navigation guide', $this->navigationGuide(), 'Use concise labels and stable destination URLs.') . $this->editorPanel('Compatibility', $legacy, 'Keep support for imported line-based menus.') . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin', 'back', true) . AdminLayout::submitButton('Save Menu', 'save') . '</div></form>';
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
        $homepage = trim($request->input('homepage'));
        $pages = new PageRepository($this->config->paths(), $this->files, new \Batoi\Press\Core\HtmlContent());
        $page = $homepage !== '' ? $pages->findBySlug($homepage) : null;
        if ($page !== null && ($page['status'] ?? '') === 'published') {
            $site = $this->config->site();
            $site['homepage'] = $homepage;
            $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'menu.updated', 'main', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/menus');
    }

    private function homepageSelect(string $selected): string
    {
        $pages = new PageRepository($this->config->paths(), $this->files, new \Batoi\Press\Core\HtmlContent());
        $html = '<label>Homepage <select name="homepage">';
        foreach ($pages->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $html .= '<option value="' . $this->e($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . $this->e((string)($page['title'] ?? $slug)) . '</option>';
        }
        return $html . '</select></label>';
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
        $parents = isset($post['item_parent']) && is_array($post['item_parent']) ? $post['item_parent'] : [];
        $items = [];
        foreach ($labels as $index => $label) {
            $label = trim((string)$label);
            $url = trim((string)($urls[$index] ?? ''));
            if ($label !== '' && $url !== '') {
                $items[] = ['label' => $label, 'url' => $url, 'parent' => trim((string)($parents[$index] ?? ''))];
            }
        }
        return $this->normalizeHierarchy($items);
    }

    private function itemsFromLines(string $lines): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                $items[] = ['label' => $parts[0], 'url' => $parts[1], 'parent' => (string)($parts[2] ?? '')];
            }
        }
        return $this->normalizeHierarchy($items);
    }

    private function legacyLines(array $items): string
    {
        return implode("\n", array_map(static function (array $item): string {
            $line = (string)($item['label'] ?? '') . '|' . (string)($item['url'] ?? '');
            return !empty($item['parent']) ? $line . '|' . (string)$item['parent'] : $line;
        }, $items));
    }

    private function normalizeHierarchy(array $items): array
    {
        $urls = [];
        foreach ($items as $item) {
            $urls[(string)$item['url']] = true;
        }
        foreach ($items as $index => $item) {
            $parent = trim((string)($item['parent'] ?? ''));
            if ($parent === (string)$item['url'] || !isset($urls[$parent])) {
                $parent = '';
            }
            $items[$index]['parent'] = $parent;
        }

        foreach ($items as $index => $item) {
            $seen = [(string)$item['url'] => true];
            $parent = (string)($item['parent'] ?? '');
            while ($parent !== '') {
                if (isset($seen[$parent])) {
                    $items[$index]['parent'] = '';
                    break;
                }
                $seen[$parent] = true;
                $parentItem = null;
                foreach ($items as $candidate) {
                    if ((string)($candidate['url'] ?? '') === $parent) {
                        $parentItem = $candidate;
                        break;
                    }
                }
                $parent = is_array($parentItem) ? (string)($parentItem['parent'] ?? '') : '';
            }
        }

        return $items;
    }

    private function navigationGuide(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Order rows in the same order users should scan the public navigation.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Use relative URLs such as <code>/about</code> for internal pages.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Set Parent URL to create nested sub-menus; circular and missing parent references are removed on save.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Use full URLs only for external destinations.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Save changes, then verify the public header or footer that renders this menu.</span></li>'
            . '</ul>';
    }

    private function editorPanel(string $title, string $body, string $description): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }
}
