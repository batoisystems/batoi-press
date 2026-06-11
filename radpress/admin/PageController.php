<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class PageController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $pages = $this->pages->all();
        $body = AdminLayout::pageHeader(
            'Pages',
            'Create and maintain evergreen site pages with clear publication status.',
            '<a class="bp-button" href="/admin/pages/new">Create Page</a>'
        );
        $body .= $this->toolbar($pages);

        if ($pages === []) {
            $body .= '<section class="bp-empty-state"><h2>No pages yet</h2><p>Create the first page for this site. Pages are stored as HTML content with JSON metadata.</p><a class="bp-button" href="/admin/pages/new">Create Page</a></section>';
            return Response::html($this->layout('Pages', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Title</th><th>Status</th><th>Slug</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($pages as $page) {
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

        $meta = $this->pages->save($request->post, (string)($this->user['username'] ?? 'admin'));
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'page.updated', (string)$meta['slug'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/pages');
    }

    private function form(?array $page): string
    {
        $isEdit = $page !== null;
        $slug = (string)($page['slug'] ?? '');
        $actions = '<a class="bp-button bp-button-secondary" href="/admin/pages">Back to pages</a>';
        if ($isEdit) {
            $actions = '<a class="bp-button bp-button-secondary" href="' . $this->e($this->pageUrl($slug)) . '">View page</a>' . $actions;
        }

        $body = AdminLayout::pageHeader(
            $isEdit ? 'Edit Page' : 'Create Page',
            'Manage page content, publication state, and search metadata in one workflow.',
            $actions
        );
        $body .= '<form method="post" action="/admin/pages/save" class="bp-form bp-admin-editor">';
        $body .= $this->csrf->field();

        $content = '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($page['title'] ?? '')) . $this->input('Slug', 'slug', $slug) . '<label class="bp-field-wide">Body HTML <textarea class="bp-editor-textarea" name="body" rows="18">' . $this->e((string)($page['body'] ?? '')) . '</textarea><span class="bp-field-help">Use clean HTML. Scripts and unsafe markup are sanitized before saving.</span></label></div>';
        $publishing = $this->select((string)($page['status'] ?? 'draft')) . $this->metaList($page);
        $seo = $this->input('SEO Title', 'seo_title', (string)($page['seo_title'] ?? ''), false) . '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($page['seo_description'] ?? '')) . '</textarea><span class="bp-field-help">Short page summary for search snippets and social previews.</span></label>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Content', $content, 'Write the visible page content.') . '</div><aside class="bp-editor-side">' . $this->editorPanel('Publishing', $publishing, 'Control draft or live availability.') . $this->editorPanel('SEO', $seo, 'Optional metadata for discovery.') . '</aside>';
        $body .= '<div class="bp-form-actions"><a class="bp-button bp-button-secondary" href="/admin/pages">Cancel</a><button type="submit">Save Page</button></div></form>';
        return $body;
    }

    private function input(string $label, string $name, string $value, bool $required = true): string
    {
        $requiredAttribute = $required ? ' required' : '';
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $requiredAttribute . '></label>';
    }

    private function select(string $status): string
    {
        $draft = $status === 'draft' ? ' selected' : '';
        $published = $status === 'published' ? ' selected' : '';
        return '<label>Status <select name="status"><option value="draft"' . $draft . '>Draft</option><option value="published"' . $published . '>Published</option></select></label>';
    }

    private function toolbar(array $pages): string
    {
        $published = count(array_filter($pages, static fn (array $page): bool => ($page['status'] ?? '') === 'published'));
        $draft = count(array_filter($pages, static fn (array $page): bool => ($page['status'] ?? '') !== 'published'));
        return '<div class="bp-admin-toolbar"><div class="bp-admin-tabs" aria-label="Page status summary"><span class="bp-admin-tab is-active">All ' . count($pages) . '</span><span class="bp-admin-tab">Published ' . $published . '</span><span class="bp-admin-tab">Draft ' . $draft . '</span></div><label class="bp-admin-search">Search <input type="search" placeholder="Search pages" disabled><span>Search arrives in a later workflow phase.</span></label></div>';
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
