<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Slug;
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class PageController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
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

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Title</th><th>Status</th><th>Slug</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($filteredPages as $page) {
            $slug = (string)($page['slug'] ?? '');
            $title = (string)($page['title'] ?? 'Untitled');
            $body .= '<tr><td><strong>' . $this->e($title) . '</strong><small>Page</small></td><td>' . $this->statusBadge((string)($page['status'] ?? 'draft')) . '</td><td><code>' . $this->e($slug) . '</code></td><td>' . $this->formatDate((string)($page['updated_at'] ?? '')) . '</td><td><div class="bp-table-actions"><a href="' . $this->e($this->pageUrl($slug)) . '">View</a><a href="/admin/pages/edit/' . rawurlencode($slug) . '">Edit</a></div></td></tr>';
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
            $meta = $this->pages->save($request->post, (string)($this->user['username'] ?? 'admin'));
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Pages', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to pages', '/admin/pages', 'back', true) . '</p>'), 409);
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'page.updated', (string)$meta['slug'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/pages');
    }

    private function form(?array $page): string
    {
        $isEdit = $page !== null;
        $slug = (string)($page['slug'] ?? '');
        $actions = AdminLayout::buttonLink('Back to pages', '/admin/pages', 'back', true);
        if ($isEdit) {
            $actions = AdminLayout::buttonLink('View page', $this->pageUrl($slug), 'site', true) . $actions;
        }

        $body = AdminLayout::pageHeader(
            $isEdit ? 'Edit Page' : 'Create Page',
            'Manage page content, publication state, and search metadata in one workflow.',
            $actions
        );
        $body .= '<form method="post" action="/admin/pages/save" class="bp-form bp-admin-editor" novalidate>';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="original_slug" value="' . $this->e($slug) . '">';

        $content = '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($page['title'] ?? '')) . $this->input('Slug', 'slug', $slug) . $this->bodyEditor((string)($page['body'] ?? ''), 'Use clean HTML. Scripts, unsafe URLs, events, and inline styles are sanitized before saving.') . '</div>';
        $publishing = $this->select((string)($page['status'] ?? 'draft')) . $this->metaList($page);
        $seo = $this->input('SEO Title', 'seo_title', (string)($page['seo_title'] ?? ''), false) . '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($page['seo_description'] ?? '')) . '</textarea><span class="bp-field-help">Short page summary for search snippets and social previews.</span></label>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Content', $content, 'Write the visible page content.') . '</div><aside class="bp-editor-side">' . $this->editorPanel('Publishing', $publishing, 'Control draft or live availability.') . $this->editorPanel('SEO', $seo, 'Optional metadata for discovery.') . $this->editorPanel('Pre-publish checklist', $this->pageChecklist(), 'Review before publishing or changing a live page.') . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/pages', 'back', true) . AdminLayout::submitButton('Save Page', 'save') . '</div></form>';
        return $body;
    }

    private function input(string $label, string $name, string $value, bool $required = true): string
    {
        $requiredAttribute = $required ? ' required' : '';
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $requiredAttribute . '></label>';
    }

    private function bodyEditor(string $value, string $help): string
    {
        return ContentEditor::render($this->config, $value, $help, 'bp-page-body');
    }

    private function select(string $status): string
    {
        $draft = $status === 'draft' ? ' selected' : '';
        $published = $status === 'published' ? ' selected' : '';
        return '<label>Status <select name="status"><option value="draft"' . $draft . '>Draft</option><option value="published"' . $published . '>Published</option></select></label>';
    }

    private function toolbar(array $pages, array $filters): string
    {
        $published = count(array_filter($pages, static fn (array $page): bool => ($page['status'] ?? '') === 'published'));
        $draft = count(array_filter($pages, static fn (array $page): bool => ($page['status'] ?? '') !== 'published'));
        $status = (string)($filters['status'] ?? '');
        $html = '<div class="bp-admin-toolbar"><div class="bp-admin-tabs" aria-label="Page status summary"><span class="bp-admin-tab is-active">All ' . count($pages) . '</span><span class="bp-admin-tab">Published ' . $published . '</span><span class="bp-admin-tab">Draft ' . $draft . '</span></div></div>';
        $html .= '<form method="get" action="/admin/pages" class="bp-filter-form bp-filter-form-compact"><div class="bp-filter-field bp-filter-field-search"><label for="bp-page-filter-q">Search</label><input id="bp-page-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Title, slug, or SEO text"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-page-filter-status">Status</label><select id="bp-page-filter-status" name="status"><option value="">All statuses</option>';
        foreach (['published' => 'Published', 'draft' => 'Draft'] as $value => $label) {
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
        $normalized = $status === 'published' ? 'published' : 'draft';
        return '<span class="bp-status-badge is-' . $normalized . '">' . $this->e(ucfirst($normalized)) . '</span>';
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '<span class="bp-muted">Not saved</span>';
        }

        $timestamp = strtotime($value);
        return $timestamp ? $this->e(date('M j, Y H:i', $timestamp)) : $this->e($value);
    }

    private function pageUrl(string $slug): string
    {
        return $slug === 'home' ? '/' : '/' . rawurlencode($slug);
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
