<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\MenuValidationException;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use RuntimeException;

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

    public function edit(array $errors = [], ?array $draft = null, int $status = 200): Response
    {
        $repository = $this->menus();
        $menu = $draft ?? $repository->load();
        $items = array_values(array_filter((array)($menu['items'] ?? []), 'is_array'));
        $site = $this->config->site();

        $body = AdminLayout::pageHeader(
            'Menus',
            'Build clear, responsive navigation with stable hierarchy, dropdowns, and mega menus.',
            '<span class="bp-status-badge is-published">Primary navigation</span>'
        );
        if ($errors !== []) {
            $body .= '<div class="bp-error" role="alert"><strong>Menu not saved.</strong> Review the highlighted items and preserve the intended hierarchy before trying again.</div>';
        }
        $body .= $this->summary($menu, $items);
        $body .= '<form method="post" action="/admin/menus/save" class="bp-form bp-admin-editor bp-menu-editor" data-bp-menu-builder>';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="menu_id" value="' . $this->e((string)($menu['id'] ?? 'menu_main')) . '">';
        $body .= '<input type="hidden" name="menu_revision" value="' . (int)($menu['revision'] ?? 0) . '">';
        $body .= '<input type="hidden" name="menu_location" value="primary">';
        $body .= '<div class="bp-editor-main">';
        $body .= $this->editorPanel('Primary navigation', $this->builder($items, $errors), 'Arrange items in reading order. Select a parent to create a dropdown or nested child.');
        $body .= $this->editorPanel('Structure preview', '<div class="bp-menu-structure-preview" data-bp-menu-preview>' . $this->structurePreview($items) . '</div>', 'Review hierarchy and mega-menu columns before saving.');
        $body .= '</div><aside class="bp-editor-side">';
        $body .= $this->editorPanel('Add content', $this->itemLibrary(), 'Add published destinations without remembering their URLs.');
        $body .= $this->editorPanel('Homepage', $this->homepageSelect((string)($site['homepage'] ?? 'home')), 'Choose the published page shown at the site root.');
        $body .= $this->editorPanel('Navigation guide', $this->navigationGuide(), 'Keep labels concise and hierarchy predictable.');
        $body .= $this->editorPanel('Legacy import', $this->legacyImport($repository, $menu), 'Import line-based menus when migrating older installations.');
        $body .= '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin', 'back', true) . AdminLayout::submitButton('Save Navigation', 'save') . '</div></form>';

        return Response::html($this->layout('Menus', $body), $status);
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Menus', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $repository = $this->menus();
        $items = $this->itemsFromRequest($request->post);
        if ($items === []) {
            $imported = $repository->importLegacyLines($request->input('items'));
            $items = (array)($imported['items'] ?? []);
        }
        $draft = [
            'schema_version' => MenuRepository::SCHEMA_VERSION,
            'id' => $request->input('menu_id', 'menu_main'),
            'name' => 'Primary navigation',
            'location' => $request->input('menu_location', 'primary'),
            'revision' => (int)$request->input('menu_revision', '0'),
            'items' => $items,
        ];

        try {
            $saved = $repository->save(
                $draft,
                (string)($this->user['username'] ?? 'admin'),
                (int)$request->input('menu_revision', '0')
            );
        } catch (MenuValidationException $exception) {
            return $this->edit($exception->errors(), $draft, 422);
        } catch (MenuConflictException $exception) {
            return Response::html($this->layout('Menus', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p><a href="/admin/menus">Reload menu editor</a></p>'), 409);
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Menus', '<p class="bp-error">Unable to save navigation: ' . $this->e($exception->getMessage()) . '</p><p><a href="/admin/menus">Back to Menus</a></p>'), 500);
        }

        $homepage = trim($request->input('homepage'));
        $pages = $this->pages();
        $page = $homepage !== '' ? $pages->findBySlug($homepage) : null;
        if ($page !== null && ($page['status'] ?? '') === 'published') {
            $site = $this->config->site();
            $site['homepage'] = $homepage;
            $this->files->writeJson($this->config->paths()->configPath('site.json'), $site);
        }
        $this->audit->record(
            (string)($this->user['username'] ?? 'admin'),
            'menu.updated',
            (string)($saved['id'] ?? 'menu_main'),
            (string)($request->server['REMOTE_ADDR'] ?? ''),
            'success',
            [
                'location' => (string)($saved['location'] ?? 'primary'),
                'revision' => (int)($saved['revision'] ?? 0),
                'item_count' => count((array)($saved['items'] ?? [])),
            ]
        );

        return Response::redirect('/admin/menus');
    }

    private function summary(array $menu, array $items): string
    {
        $parents = 0;
        $mega = 0;
        $disabled = 0;
        $childCounts = [];
        foreach ($items as $item) {
            if (!empty($item['parent_id'])) {
                $childCounts[(string)$item['parent_id']] = true;
            }
            $mega += ($item['presentation'] ?? '') === 'mega' ? 1 : 0;
            $disabled += !($item['enabled'] ?? true) ? 1 : 0;
        }
        $parents = count($childCounts);

        return '<dl class="bp-admin-stats bp-admin-stats-compact">'
            . AdminLayout::statCard('Items', (string)count($items), 'Visible and disabled navigation records.')
            . AdminLayout::statCard('Parents', (string)$parents, 'Items that currently own child navigation.')
            . AdminLayout::statCard('Mega menus', (string)$mega, 'Top-level multi-column navigation panels.')
            . AdminLayout::statCard('Revision', (string)(int)($menu['revision'] ?? 0), $disabled . ' disabled item' . ($disabled === 1 ? '' : 's') . '.')
            . '</dl>';
    }

    private function builder(array $items, array $errors): string
    {
        $html = '<div class="bp-menu-builder-toolbar"><div><strong>Navigation tree</strong><span>' . count($items) . ' of ' . MenuRepository::MAX_ITEMS . ' items</span></div><button class="bp-button bp-button-secondary" type="button" data-bp-add-menu-item>Add custom item</button></div>';
        $html .= '<div class="bp-menu-builder-list" data-bp-menu-list>';
        foreach ($items as $item) {
            $html .= $this->menuRow($item, $items, $errors[(string)($item['id'] ?? '')] ?? '');
        }
        $html .= '</div>';
        $html .= '<p class="bp-menu-empty' . ($items === [] ? ' is-visible' : '') . '" data-bp-menu-empty>No navigation items yet. Add published content or a custom link.</p>';
        $html .= '<template data-bp-menu-template>' . $this->menuRow([
            'id' => '__ID__',
            'type' => 'link',
            'label' => '',
            'url' => '',
            'parent_id' => null,
            'presentation' => 'link',
            'description' => '',
            'column' => 1,
            'target' => '_self',
            'enabled' => true,
        ], $items, '') . '</template>';
        return $html;
    }

    private function menuRow(array $item, array $items, string $error): string
    {
        $id = (string)($item['id'] ?? '');
        $name = 'menu_items[' . $id . ']';
        $label = (string)($item['label'] ?? '');
        $type = (string)($item['type'] ?? 'link');
        $parentId = (string)($item['parent_id'] ?? '');
        $presentation = (string)($item['presentation'] ?? 'link');
        $enabled = in_array($item['enabled'] ?? true, [true, 1, '1', 'true', 'on'], true);
        $depth = $this->depth($item, $items);

        $html = '<article class="bp-menu-builder-item bp-reorder-row' . ($error !== '' ? ' has-error' : '') . '" data-bp-menu-item data-item-id="' . $this->e($id) . '" style="--bp-menu-depth:' . $depth . '">';
        $html .= '<input type="hidden" name="item_order[]" value="' . $this->e($id) . '">';
        $html .= '<header class="bp-menu-item-summary"><button class="bp-menu-drag" type="button" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button><div><strong data-bp-menu-summary-label>' . $this->e($label !== '' ? $label : 'New menu item') . '</strong><span><span class="bp-menu-type-badge" data-bp-menu-summary-type>' . $this->e(ucfirst($type)) . '</span><code data-bp-menu-summary-url>' . $this->e((string)($item['url'] ?? '')) . '</code></span></div><div class="bp-reorder-actions"><button type="button" data-bp-menu-action="outdent" aria-label="Move item out one level">←</button><button type="button" data-bp-menu-action="indent" aria-label="Move item under previous item">→</button><button type="button" data-bp-move="up" aria-label="Move item up">↑</button><button type="button" data-bp-move="down" aria-label="Move item down">↓</button><button class="is-danger" type="button" data-bp-menu-action="remove" aria-label="Remove menu item">×</button></div></header>';
        if ($error !== '') {
            $html .= '<p class="bp-menu-item-error">' . $this->e($error) . '</p>';
        }
        $html .= '<div class="bp-menu-item-fields">';
        $html .= '<input type="hidden" name="' . $this->e($name) . '[id]" value="' . $this->e($id) . '">';
        $html .= '<label>Label<input data-bp-menu-label type="text" name="' . $this->e($name) . '[label]" value="' . $this->e($label) . '" maxlength="120" required></label>';
        $html .= '<label>Item type<select data-bp-menu-type name="' . $this->e($name) . '[type]">' . $this->options(MenuRepository::TYPES, $type) . '</select></label>';
        $html .= '<label class="bp-menu-url-field">Destination<input data-bp-menu-url type="text" name="' . $this->e($name) . '[url]" value="' . $this->e((string)($item['url'] ?? '')) . '" placeholder="/about"></label>';
        $html .= '<label>Parent<select data-bp-menu-parent name="' . $this->e($name) . '[parent_id]"><option value="">Top level</option>' . $this->parentOptions($items, $id, $parentId) . '</select></label>';
        $html .= '<label>Top-level display<select data-bp-menu-presentation name="' . $this->e($name) . '[presentation]">' . $this->options(MenuRepository::PRESENTATIONS, $presentation) . '</select></label>';
        $html .= '<label>Mega column<select data-bp-menu-column name="' . $this->e($name) . '[column]">' . $this->options(['1', '2', '3', '4'], (string)($item['column'] ?? 1)) . '</select></label>';
        $html .= '<label class="bp-menu-description-field">Description<input data-bp-menu-description type="text" name="' . $this->e($name) . '[description]" value="' . $this->e((string)($item['description'] ?? '')) . '" maxlength="180" placeholder="Optional supporting text"></label>';
        $html .= '<label>Link target<select name="' . $this->e($name) . '[target]">' . $this->options(['_self', '_blank'], (string)($item['target'] ?? '_self')) . '</select></label>';
        $html .= '<label class="bp-menu-enabled"><input type="hidden" name="' . $this->e($name) . '[enabled]" value="0"><input data-bp-menu-enabled type="checkbox" name="' . $this->e($name) . '[enabled]" value="1"' . ($enabled ? ' checked' : '') . '> Enabled in public navigation</label>';
        $html .= '</div></article>';
        return $html;
    }

    private function itemsFromRequest(array $post): array
    {
        $submitted = isset($post['menu_items']) && is_array($post['menu_items']) ? $post['menu_items'] : [];
        $order = isset($post['item_order']) && is_array($post['item_order']) ? $post['item_order'] : array_keys($submitted);
        $items = [];
        foreach ($order as $id) {
            $id = (string)$id;
            if (!isset($submitted[$id]) || !is_array($submitted[$id])) {
                continue;
            }
            $item = $submitted[$id];
            $item['id'] = $id;
            $items[] = $item;
        }
        return $items;
    }

    private function itemLibrary(): string
    {
        $html = '<div class="bp-menu-library"><button type="button" data-bp-add-menu-library data-label="Home" data-url="/" data-type="page"><strong>Home</strong><small>/</small></button><button type="button" data-bp-add-menu-library data-label="Blog" data-url="/blog" data-type="archive"><strong>Blog</strong><small>/blog</small></button>';
        foreach ($this->pages()->allPublished() as $page) {
            $label = (string)($page['title'] ?? $page['slug'] ?? 'Page');
            $url = $this->pages()->publicPath($page);
            $html .= '<button type="button" data-bp-add-menu-library data-label="' . $this->e($label) . '" data-url="' . $this->e($url) . '" data-type="page"><strong>' . $this->e($label) . '</strong><small>' . $this->e($url) . '</small></button>';
        }
        return $html . '</div>';
    }

    private function structurePreview(array $items): string
    {
        if ($items === []) {
            return '<p class="bp-muted">No menu items configured.</p>';
        }
        $children = ['' => []];
        foreach ($items as $item) {
            $parent = (string)($item['parent_id'] ?? '');
            $children[$parent][] = $item;
        }
        $render = function (string $parent = '', int $depth = 0) use (&$render, $children): string {
            if ($depth >= MenuRepository::MAX_DEPTH || empty($children[$parent])) {
                return '';
            }
            $html = '<ol>';
            foreach ($children[$parent] as $item) {
                $id = (string)($item['id'] ?? '');
                $html .= '<li' . (!($item['enabled'] ?? true) ? ' class="is-disabled"' : '') . '><span><strong>' . $this->e((string)($item['label'] ?? 'Untitled')) . '</strong><small>' . $this->e((string)($item['type'] ?? 'link')) . (($item['presentation'] ?? 'link') !== 'link' ? ' · ' . $this->e((string)$item['presentation']) : '') . (($item['presentation'] ?? '') === 'mega' ? ' · column ' . (int)($item['column'] ?? 1) : '') . '</small></span>' . $render($id, $depth + 1) . '</li>';
            }
            return $html . '</ol>';
        };
        return $render();
    }

    private function homepageSelect(string $selected): string
    {
        $html = '<label>Homepage<select name="homepage">';
        foreach ($this->pages()->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $html .= '<option value="' . $this->e($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . $this->e((string)($page['title'] ?? $slug)) . '</option>';
        }
        return $html . '</select></label>';
    }

    private function legacyImport(MenuRepository $repository, array $menu): string
    {
        return '<details class="bp-details"><summary>Import line-based menu</summary><label>Menu lines<textarea name="items" rows="7">' . $this->e($repository->legacyLines($menu)) . '</textarea><span class="bp-field-help"><code>Label|/url</code>, optionally followed by <code>|/parent-url</code>. Structured items take priority unless the tree is empty.</span></label></details>';
    }

    private function navigationGuide(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Keep the primary navigation focused on the highest-value destinations.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Use Dropdown for a compact child list and Mega for grouped, multi-column navigation.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Use headings inside mega menus to label groups without creating empty links.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Limit nesting to four levels so desktop and mobile navigation remains usable.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Disable an item when it should remain configured but temporarily hidden.</span></li>'
            . '</ul>';
    }

    private function parentOptions(array $items, string $currentId, string $selected): string
    {
        $html = '';
        foreach ($items as $candidate) {
            $id = (string)($candidate['id'] ?? '');
            if ($id === '' || $id === $currentId || ($candidate['type'] ?? '') === 'separator') {
                continue;
            }
            $html .= '<option value="' . $this->e($id) . '"' . ($id === $selected ? ' selected' : '') . '>' . $this->e((string)($candidate['label'] ?? 'Untitled')) . '</option>';
        }
        return $html;
    }

    private function depth(array $item, array $items): int
    {
        $parents = [];
        foreach ($items as $candidate) {
            $parents[(string)($candidate['id'] ?? '')] = (string)($candidate['parent_id'] ?? '');
        }
        $depth = 0;
        $cursor = (string)($item['parent_id'] ?? '');
        $seen = [];
        while ($cursor !== '' && isset($parents[$cursor]) && !isset($seen[$cursor]) && $depth < MenuRepository::MAX_DEPTH - 1) {
            $seen[$cursor] = true;
            $depth++;
            $cursor = $parents[$cursor];
        }
        return $depth;
    }

    private function options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $option) {
            $html .= '<option value="' . $this->e((string)$option) . '"' . ((string)$option === $selected ? ' selected' : '') . '>' . $this->e(ucwords(str_replace('_', ' ', (string)$option))) . '</option>';
        }
        return $html;
    }

    private function menus(): MenuRepository
    {
        return new MenuRepository($this->config->paths(), $this->files);
    }

    private function pages(): PageRepository
    {
        return new PageRepository($this->config->paths(), $this->files, new HtmlContent());
    }

    private function editorPanel(string $title, string $body, string $description): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
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
