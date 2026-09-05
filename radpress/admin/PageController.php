<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\IdempotencyStore;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Slug;
use Batoi\Press\Core\ThemeManager;
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class PageController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $pages = $this->pages->all();
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $filteredPages = $this->filterPages($pages, $filters);
        $body = AdminLayout::pageHeader(
            'Pages',
            'Create and maintain evergreen site pages with clear publication status.',
            AdminLayout::buttonLink('Create Page', '/admin/pages/new', 'plus')
        );
        $body .= $this->toolbar($pages, $filters);
        $body .= AdminLayout::section(
            'Page standards',
            $this->pageStandards(),
            'Use pages for durable site information such as home, about, service, policy, and landing pages.'
        );

        if ($pages === []) {
            $body .= '<section class="bp-empty-state"><h2>No pages yet</h2><p>Create the first page for this site. Pages are stored as HTML content with JSON metadata.</p>' . AdminLayout::buttonLink('Create Page', '/admin/pages/new', 'plus') . '</section>';
            return Response::html($this->layout('Pages', $body));
        }

        if ($filteredPages === []) {
            $body .= '<section class="bp-empty-state"><h2>No pages match these filters</h2><p>Adjust the search or status filters to review other pages.</p>' . AdminLayout::buttonLink('Reset Filters', '/admin/pages', 'back') . '</section>';
            return Response::html($this->layout('Pages', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Title</th><th>Status</th><th>Route</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($filteredPages as $page) {
            $slug = (string)($page['slug'] ?? '');
            $title = (string)($page['title'] ?? 'Untitled');
            $body .= '<tr><td><strong>' . $this->e($title) . '</strong><small>' . (!empty($page['parent_slug']) ? 'Child page' : 'Page') . '</small></td><td>' . $this->statusBadge((string)($page['status'] ?? 'draft')) . '</td><td><code>' . $this->e($this->pageUrl($page)) . '</code></td><td>' . $this->formatDate((string)($page['updated_at'] ?? '')) . '</td><td><div class="bp-table-actions"><a href="' . $this->e($this->pageUrl($page)) . '">View</a><a href="/admin/pages/edit/' . rawurlencode($slug) . '">Edit</a><a href="/admin/pages/new?parent=' . rawurlencode($slug) . '">Add child</a></div></td></tr>';
        }
        $body .= '</tbody></table></div>';
        return Response::html($this->layout('Pages', $body));
    }

    public function edit(?string $slug = null): Response
    {
        $page = $slug ? $this->pages->findBySlug($slug) : null;
        return Response::html($this->layout($slug ? 'Edit Page' : 'Create Page', $this->form($page)));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Pages', '<p class="bp-error">Security token expired.</p><p><a href="/admin/pages">Back to pages</a></p>'), 400);
        }

        $slug = Slug::normalize($request->input('slug'));
        $originalSlug = Slug::normalize($request->input('original_slug'));
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->pages->findBySlug($slug) !== null) {
            return Response::html($this->layout('Pages', '<p class="bp-error">A page with this slug already exists.</p><p>' . AdminLayout::buttonLink('Back to pages', '/admin/pages', 'back', true) . '</p>'), 409);
        }

        try {
            $input = $request->post;
            if (($input['body_edit_mode'] ?? '') === 'text_only') {
                $sourceSlug = $originalSlug !== '' ? $originalSlug : $slug;
                $sourcePage = $this->pages->findBySlug($sourceSlug);
                if ($sourcePage === null) {
                    throw new RuntimeException('The source page for protected-layout editing is no longer available.');
                }
                $sourceBody = (string)($sourcePage['body'] ?? '');
                $sourceHash = (string)($input['body_source_hash'] ?? '');
                if ($sourceHash === '' || !hash_equals(hash('sha256', $sourceBody), $sourceHash)) {
                    throw new RuntimeException('The page body changed after this editor was opened. Reload it before saving text changes.');
                }
                $replacements = $input['body_text'] ?? [];
                if (!is_array($replacements)) {
                    throw new RuntimeException('The protected-layout text fields were not submitted correctly.');
                }
                $input['body'] = (new HtmlContent())->replaceEditableText($sourceBody, $replacements);
                $sourceBlocks = is_array($sourcePage['blocks'] ?? null) ? array_values($sourcePage['blocks']) : [];
                if (count($sourceBlocks) === 1 && is_array($sourceBlocks[0]) && ($sourceBlocks[0]['type'] ?? '') === 'html') {
                    $sourceBlocks[0]['body'] = $input['body'];
                    $input['blocks'] = $sourceBlocks;
                }
            } elseif (isset($input['block_type']) && is_array($input['block_type'])) {
                $input['blocks'] = $this->submittedBlocks($input);
                $input['body'] = implode("\n", array_map(
                    static fn(array $block): string => in_array($block['type'], ['html', 'gallery'], true) ? (string)$block['body'] : '',
                    $input['blocks']
                ));
            }
            (new ContentMutationService($this->config, $this->pages, $this->posts, $this->audit, new IdempotencyStore($this->config->paths())))->saveFromAdmin(
                'page',
                $input,
                $request->input('expected_revision'),
                (string)($this->user['username'] ?? 'admin'),
                'admin_' . bin2hex(random_bytes(8))
            );
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Pages', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to pages', '/admin/pages', 'back', true) . '</p>'), 409);
        }
        return Response::redirect('/admin/pages');
    }

    private function form(?array $page): string
    {
        $isEdit = $page !== null;
        $slug = (string)($page['slug'] ?? '');
        $requestedParent = Slug::normalize((string)($page['parent_slug'] ?? $_GET['parent'] ?? ''));
        if ($requestedParent !== '' && $this->pages->findBySlug($requestedParent) === null) {
            $requestedParent = '';
        }
        $actions = AdminLayout::buttonLink('Back to pages', '/admin/pages', 'back', true);
        if ($isEdit) {
            $actions = AdminLayout::buttonLink('View page', $this->pageUrl($page), 'site', true) . AdminLayout::buttonLink('Add child page', '/admin/pages/new?parent=' . rawurlencode($slug), 'plus', true) . $actions;
        }

        $body = AdminLayout::pageHeader(
            $isEdit ? 'Edit Page' : 'Create Page',
            'Manage page content, publication state, and search metadata in one workflow.',
            $actions
        );
        $body .= '<form method="post" action="/admin/pages/save" class="bp-form bp-admin-editor" novalidate>';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="original_slug" value="' . $this->e($slug) . '">';
        $body .= '<input type="hidden" name="expected_revision" value="' . $this->e($page === null ? '' : ContentRevision::for($page)) . '">';

        $bodyValue = (string)($page['body'] ?? '');
        $htmlContent = new HtmlContent();
        $textSegments = $isEdit ? $htmlContent->editableTextSegments($bodyValue) : [];
        $requestedEditor = strtolower(trim((string)($_GET['editor'] ?? '')));
        $storedBlocks = $page['blocks'] ?? [];
        $supportsProtectedEditing = !is_array($storedBlocks) || $storedBlocks === [] || (count($storedBlocks) === 1 && ($storedBlocks[0]['type'] ?? '') === 'html');
        $textOnly = $isEdit
            && $supportsProtectedEditing
            && $textSegments !== []
            && ($requestedEditor === 'text' || ($requestedEditor === '' && $htmlContent->hasComplexStructure($bodyValue)));
        $sourceOnly = $requestedEditor === 'html';
        $editor = $textOnly
            ? ContentEditor::renderTextOnly($bodyValue, $textSegments, 'bp-page-body')
            : $this->blocksEditor($page, $bodyValue, $sourceOnly);
        $modeSwitch = $isEdit && $supportsProtectedEditing && $textSegments !== [] ? $this->editorModeSwitch($slug, $textOnly) : '';
        $pageAssets = '<details class="bp-field-wide bp-editor-advanced"><summary>Page CSS and JavaScript</summary><div class="bp-form-grid">'
            . '<label class="bp-field-wide">Custom CSS <textarea name="custom_css" rows="8" spellcheck="false" placeholder=".page-class { color: #111827; }">' . $this->e((string)($page['custom_css'] ?? '')) . '</textarea><span class="bp-field-help">Loaded only on this page. Enter CSS rules without a &lt;style&gt; wrapper.</span></label>'
            . '<label class="bp-field-wide">Custom JavaScript <textarea name="custom_js" rows="8" spellcheck="false" placeholder="document.addEventListener(\'DOMContentLoaded\', () => { });">' . $this->e((string)($page['custom_js'] ?? '')) . '</textarea><span class="bp-field-help">Loaded only on this page. Enter JavaScript without a &lt;script&gt; wrapper.</span></label>'
            . '</div></details>';
        $content = '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($page['title'] ?? ''), true, 'data-bp-slug-source') . $this->input('Slug', 'slug', $slug, true, 'data-bp-slug-target') . $modeSwitch . $editor . $pageAssets . '</div>';
        $publishing = $this->select((string)($page['status'] ?? 'draft')) . $this->workflowFields($page) . $this->parentSelect($requestedParent, $slug) . $this->templateSelect((string)($page['template'] ?? 'page')) . $this->latestPostsFields($page) . $this->workflowHistory($page) . $this->metaList($page);
        $seo = $this->input('SEO Title', 'seo_title', (string)($page['seo_title'] ?? ''), false) . '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($page['seo_description'] ?? '')) . '</textarea><span class="bp-field-help">Short page summary for search snippets and social previews.</span></label>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Content', $content, 'Write the visible page content.') . '</div><aside class="bp-editor-side">' . AifEditorPanel::render($this->config, 'page') . $this->editorPanel('Publishing', $publishing, 'Control draft or live availability.') . $this->editorPanel('SEO', $seo, 'Optional metadata for discovery.') . $this->editorPanel('Pre-publish checklist', $this->pageChecklist(), 'Review before publishing or changing a live page.') . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/pages', 'back', true) . AdminLayout::submitButton('Save Page', 'save') . '</div></form>';
        return $body;
    }

    private function input(string $label, string $name, string $value, bool $required = true, string $attributes = ''): string
    {
        $requiredAttribute = $required ? ' required' : '';
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $requiredAttribute . ($attributes !== '' ? ' ' . $attributes : '') . '></label>';
    }

    private function bodyEditor(string $value, string $help, bool $sourceOnly = false): string
    {
        return ContentEditor::render($this->config, $value, $help, 'bp-page-body', $sourceOnly);
    }

    private function blocksEditor(?array $page, string $fallbackBody, bool $sourceOnly): string
    {
        $blocks = is_array($page['blocks'] ?? null) && $page['blocks'] !== []
            ? array_values($page['blocks'])
            : [['type' => 'html', 'title' => '', 'body' => $fallbackBody, 'category' => '', 'limit' => 6, 'widget' => '']];
        $rows = '';
        foreach ($blocks as $index => $block) {
            if (is_array($block)) $rows .= $this->blockRow($block, $index, $sourceOnly || count($blocks) > 1);
        }
        $template = $this->blockRow(['type' => 'html', 'title' => '', 'body' => '', 'category' => '', 'limit' => 6, 'widget' => ''], 999, true);
        return '<div class="bp-field-wide bp-page-blocks" data-bp-page-blocks><div class="bp-block-toolbar"><div><strong>Page content blocks</strong><p>Reorder HTML, blog posts, galleries, products, and configured widgets.</p></div><label>Add block <select data-bp-add-block-type><option value="html">HTML content</option><option value="posts">Blog posts</option><option value="gallery">Image gallery</option><option value="products">Products</option><option value="widget">Widget</option></select></label><button type="button" class="bp-button bp-button-secondary" data-bp-add-block>Add block</button></div><div class="bp-widget-rows" data-bp-reorder-list data-bp-block-list>' . $rows . '</div><template data-bp-block-template>' . $template . '</template></div>';
    }

    private function blockRow(array $block, int $index, bool $sourceOnly): string
    {
        $type = in_array(($block['type'] ?? ''), ['html', 'posts', 'gallery', 'products', 'widget'], true) ? (string)$block['type'] : 'html';
        $options = '';
        foreach (['html' => 'HTML content', 'posts' => 'Blog posts', 'gallery' => 'Image gallery', 'products' => 'Products', 'widget' => 'Widget'] as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $editor = ContentEditor::render($this->config, (string)($block['body'] ?? ''), 'HTML and gallery markup is sanitized when saved.', 'bp-page-block-' . $index, $sourceOnly, 'block_body[]');
        return '<section class="bp-reorder-row bp-block-row" data-bp-block-row><div class="bp-reorder-actions"><button type="button" data-bp-move="up" aria-label="Move block up">↑</button><button type="button" data-bp-move="down" aria-label="Move block down">↓</button><button type="button" data-bp-remove-block aria-label="Remove block">×</button></div>'
            . '<label>Block type <select name="block_type[]" data-bp-block-type>' . $options . '</select></label>'
            . '<label>Heading <input type="text" name="block_title[]" maxlength="160" value="' . $this->e((string)($block['title'] ?? '')) . '"></label>'
            . '<div class="bp-field-wide" data-bp-block-field="html gallery">' . $editor . '</div>'
            . '<label data-bp-block-field="posts products">Category filter <input type="text" name="block_category[]" maxlength="100" value="' . $this->e((string)($block['category'] ?? '')) . '"><span class="bp-field-help">Leave blank to include every category.</span></label>'
            . '<label data-bp-block-field="posts products">Items <input type="number" name="block_limit[]" min="1" max="24" value="' . max(1, min(24, (int)($block['limit'] ?? 6))) . '"></label>'
            . '<label data-bp-block-field="widget">Widget title <input type="text" name="block_widget[]" maxlength="160" value="' . $this->e((string)($block['widget'] ?? '')) . '"><span class="bp-field-help">Matches a widget configured in Widgets.</span></label>'
            . '</section>';
    }

    private function submittedBlocks(array $input): array
    {
        $types = (array)($input['block_type'] ?? []);
        if (count($types) > 30) {
            throw new RuntimeException('A page supports at most 30 content blocks.');
        }
        $titles = (array)($input['block_title'] ?? []);
        $bodies = (array)($input['block_body'] ?? []);
        $categories = (array)($input['block_category'] ?? []);
        $limits = (array)($input['block_limit'] ?? []);
        $widgets = (array)($input['block_widget'] ?? []);
        $blocks = [];
        foreach (array_slice($types, 0, 30) as $index => $type) {
            $blocks[] = ['type' => (string)$type, 'title' => (string)($titles[$index] ?? ''), 'body' => (string)($bodies[$index] ?? ''), 'category' => (string)($categories[$index] ?? ''), 'limit' => (int)($limits[$index] ?? 6), 'widget' => (string)($widgets[$index] ?? '')];
        }
        return $blocks;
    }

    private function editorModeSwitch(string $slug, bool $textOnly): string
    {
        $base = '/admin/pages/edit/' . rawurlencode($slug);
        return '<div class="bp-field-wide bp-editor-mode-switch"><div><strong>Editing mode</strong><p>Use Text only for safe copy updates on custom layouts. Use HTML editor only when the page structure must change.</p></div><nav aria-label="Page body editing mode">'
            . '<a href="' . $base . '?editor=text"' . ($textOnly ? ' class="is-active" aria-current="page"' : '') . '>Text only</a>'
            . '<a href="' . $base . '?editor=html"' . (!$textOnly ? ' class="is-active" aria-current="page"' : '') . '>HTML source</a>'
            . '</nav></div>';
    }

    private function select(string $status): string
    {
        $html = '<label>Status <select name="status">';
        foreach (PublicationState::STATUSES as $value) {
            $html .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e(PublicationState::label($value)) . '</option>';
        }
        return $html . '</select><span class="bp-field-help">Use In review and Approved for handoff; Scheduled requires a publish date.</span></label>';
    }

    private function workflowFields(?array $page): string
    {
        return $this->dateTimeInput('Publish date', 'publish_at', (string)($page['publish_at'] ?? ''), 'Scheduled content becomes public at this time.')
            . $this->dateTimeInput('Unpublish date', 'unpublish_at', (string)($page['unpublish_at'] ?? ''), 'Optional automatic public visibility cutoff.')
            . $this->input('Reviewer', 'reviewer', (string)($page['reviewer'] ?? ''), false)
            . '<label>Workflow note <textarea name="workflow_note" rows="3" maxlength="500"></textarea><span class="bp-field-help">Saved to workflow history; never displayed publicly.</span></label>';
    }

    private function dateTimeInput(string $label, string $name, string $value, string $help): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        $formatted = $timestamp !== false ? date('Y-m-d\TH:i', $timestamp) : '';
        return '<label>' . $this->e($label) . ' <input type="datetime-local" name="' . $this->e($name) . '" value="' . $this->e($formatted) . '"><span class="bp-field-help">' . $this->e($help) . '</span></label>';
    }

    private function templateSelect(string $selected): string
    {
        $manager = new ThemeManager($this->config->paths());
        $theme = $manager->activeSlug($this->config->site());
        $templates = $manager->pageTemplates($theme);
        $selected = isset($templates[$selected]) ? $selected : 'page';
        $options = '';
        foreach ($templates as $key => $template) {
            $options .= '<option value="' . $this->e((string)$key) . '"' . ($selected === $key ? ' selected' : '') . '>' . $this->e((string)($template['label'] ?? $key)) . '</option>';
        }
        return '<label>Page template <select name="template">' . $options . '</select><span class="bp-field-help">Controls the public layout. Content remains portable between templates.</span></label>';
    }

    private function parentSelect(string $selected, string $currentSlug): string
    {
        $options = '<option value="">Top level</option>';
        foreach ($this->pages->all() as $page) {
            $slug = (string)($page['slug'] ?? '');
            if ($slug === '' || $slug === $currentSlug) {
                continue;
            }
            $label = (string)($page['title'] ?? $slug) . ' (' . $this->pages->publicPath($page) . ')';
            $options .= '<option value="' . $this->e($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return '<label>Parent page <select name="parent_slug">' . $options . '</select><span class="bp-field-help">Child pages use a nested public route under their parent.</span></label>';
    }

    private function latestPostsFields(?array $page): string
    {
        $enabled = (bool)($page['show_latest_posts'] ?? false);
        $limit = max(1, min(12, (int)($page['latest_posts_limit'] ?? 3)));
        return '<label>Latest posts section <select name="show_latest_posts">'
            . '<option value="0"' . (!$enabled ? ' selected' : '') . '>Hidden</option>'
            . '<option value="1"' . ($enabled ? ' selected' : '') . '>Visible</option>'
            . '</select><span class="bp-field-help">Show the newest published posts below this page. Enable this on the selected homepage for a dynamic homepage feed.</span></label>'
            . '<label>Latest posts count <input type="number" name="latest_posts_limit" min="1" max="12" value="' . $limit . '"><span class="bp-field-help">Between 1 and 12 posts.</span></label>';
    }

    private function toolbar(array $pages, array $filters): string
    {
        $published = count(array_filter($pages, static fn (array $page): bool => PublicationState::isPublic($page)));
        $draft = count(array_filter($pages, static fn (array $page): bool => ($page['status'] ?? 'draft') === 'draft'));
        $status = (string)($filters['status'] ?? '');
        $html = '<div class="bp-admin-toolbar"><div class="bp-admin-tabs" aria-label="Page status summary"><span class="bp-admin-tab is-active">All ' . count($pages) . '</span><span class="bp-admin-tab">Published ' . $published . '</span><span class="bp-admin-tab">Draft ' . $draft . '</span></div></div>';
        $html .= '<form method="get" action="/admin/pages" class="bp-filter-form bp-filter-form-compact"><div class="bp-filter-field bp-filter-field-search"><label for="bp-page-filter-q">Search</label><input id="bp-page-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Title, slug, or SEO text"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-page-filter-status">Status</label><select id="bp-page-filter-status" name="status"><option value="">All statuses</option>';
        foreach (PublicationState::STATUSES as $value) {
            $label = PublicationState::label($value);
            $html .= '<option value="' . $this->e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html . '</select></div><div class="bp-filter-actions">' . AdminLayout::submitButton('Apply Filters', 'check') . AdminLayout::buttonLink('Reset', '/admin/pages', 'back', true) . '</div></form>';
    }

    private function filterPages(array $pages, array $filters): array
    {
        $q = strtolower((string)($filters['q'] ?? ''));
        $status = (string)($filters['status'] ?? '');

        return array_values(array_filter($pages, static function (array $page) use ($q, $status): bool {
            $pageStatus = (string)($page['status'] ?? 'draft');
            if ($status !== '' && $pageStatus !== $status) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            $haystack = strtolower(implode(' ', [
                (string)($page['title'] ?? ''),
                (string)($page['slug'] ?? ''),
                (string)($page['seo_title'] ?? ''),
                (string)($page['seo_description'] ?? ''),
            ]));
            return str_contains($haystack, $q);
        }));
    }

    private function pageStandards(): string
    {
        return '<div class="bp-admin-guidance-grid">'
            . $this->guidanceCard('Stable URLs', 'Keep slugs short and durable. Changing a live slug changes the public address.', 'site')
            . $this->guidanceCard('Clean HTML', 'Use the editor for semantic content. Unsafe markup is sanitized before saving.', 'code')
            . $this->guidanceCard('Search metadata', 'Set SEO titles and descriptions for pages intended for public discovery.', 'file')
            . '</div>';
    }

    private function pageChecklist(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Title and slug match the intended public route.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Body content uses clean headings, links, and accessible media.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>SEO metadata is present when the page should be indexed.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Published pages are verified from the View page action after saving.</span></li>'
            . '</ul>';
    }

    private function guidanceCard(string $title, string $description, string $icon): string
    {
        return '<article><span>' . AdminLayout::icon($icon) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></article>';
    }

    private function statusBadge(string $status): string
    {
        $normalized = PublicationState::normalize($status);
        return '<span class="bp-status-badge is-' . str_replace('_', '-', $normalized) . '">' . $this->e(PublicationState::label($normalized)) . '</span>';
    }

    private function workflowHistory(?array $page): string
    {
        $history = array_reverse(array_slice((array)($page['workflow_history'] ?? []), -5));
        if ($history === []) {
            return '';
        }
        $html = '<details class="bp-workflow-history"><summary>Recent workflow history</summary><ol>';
        foreach ($history as $event) {
            if (!is_array($event)) continue;
            $html .= '<li><strong>' . $this->e(PublicationState::label((string)($event['to'] ?? 'draft'))) . '</strong> · ' . $this->e((string)($event['actor'] ?? 'unknown')) . '<small>' . $this->formatDate((string)($event['at'] ?? '')) . (!empty($event['note']) ? ' — ' . $this->e((string)$event['note']) : '') . '</small></li>';
        }
        return $html . '</ol></details>';
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '<span class="bp-muted">Not saved</span>';
        }

        $timestamp = strtotime($value);
        return $timestamp ? $this->e(date('M j, Y H:i', $timestamp)) : $this->e($value);
    }

    private function pageUrl(array $page): string
    {
        $slug = (string)($page['slug'] ?? '');
        return $slug !== '' && $slug === Slug::normalize((string)($this->config->site()['homepage'] ?? 'home'))
            ? '/'
            : $this->pages->publicPath($page);
    }

    private function editorPanel(string $title, string $body, string $description): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }

    private function metaList(?array $page): string
    {
        if ($page === null) {
            return '<dl class="bp-meta-list"><div><dt>Mode</dt><dd>New page</dd></div></dl>';
        }

        return '<dl class="bp-meta-list"><div><dt>Author</dt><dd>' . $this->e((string)($page['author'] ?? 'admin')) . '</dd></div><div><dt>Created</dt><dd>' . $this->formatDate((string)($page['created_at'] ?? '')) . '</dd></div><div><dt>Updated</dt><dd>' . $this->formatDate((string)($page['updated_at'] ?? '')) . '</dd></div></dl>';
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
