<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class PostController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $posts = $this->posts->all();
        $body = AdminLayout::pageHeader(
            'Posts',
            'Plan, draft, and publish dated articles for the site.',
            '<a class="bp-button" href="/admin/posts/new">Create Post</a>'
        );
        $body .= $this->toolbar($posts);

        if ($posts === []) {
            $body .= '<section class="bp-empty-state"><h2>No posts yet</h2><p>Create the first article. Posts are stored as HTML content with JSON metadata.</p><a class="bp-button" href="/admin/posts/new">Create Post</a></section>';
            return Response::html($this->layout('Posts', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Title</th><th>Status</th><th>Category</th><th>Published</th><th>Slug</th><th>Actions</th></tr></thead><tbody>';
        foreach ($posts as $post) {
            $slug = (string)($post['slug'] ?? '');
            $title = (string)($post['title'] ?? 'Untitled');
            $publishedAt = (string)($post['published_at'] ?: ($post['updated_at'] ?? ''));
            $body .= '<tr><td><strong>' . $this->e($title) . '</strong><small>Post</small></td><td>' . $this->statusBadge((string)($post['status'] ?? 'draft')) . '</td><td>' . $this->e((string)($post['category'] ?? 'General')) . '</td><td>' . $this->formatDate($publishedAt) . '</td><td><code>' . $this->e($slug) . '</code></td><td><div class="bp-table-actions"><a href="/blog/' . rawurlencode($slug) . '">View</a><a href="/admin/posts/edit/' . rawurlencode($slug) . '">Edit</a></div></td></tr>';
        }
        $body .= '</tbody></table></div>';
        return Response::html($this->layout('Posts', $body));
    }

    public function edit(?string $slug = null): Response
    {
        $post = $slug ? $this->posts->findBySlug($slug) : null;
        return Response::html($this->layout($slug ? 'Edit Post' : 'Create Post', $this->form($post)));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Posts', '<p class="bp-error">Security token expired.</p><p><a href="/admin/posts">Back to posts</a></p>'), 400);
        }

        $meta = $this->posts->save($request->post, (string)($this->user['username'] ?? 'admin'));
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'post.updated', (string)$meta['slug'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/posts');
    }

    private function form(?array $post): string
    {
        $isEdit = $post !== null;
        $slug = (string)($post['slug'] ?? '');
        $actions = '<a class="bp-button bp-button-secondary" href="/admin/posts">Back to posts</a>';
        if ($isEdit) {
            $actions = '<a class="bp-button bp-button-secondary" href="/blog/' . rawurlencode($slug) . '">View post</a>' . $actions;
        }

        $body = AdminLayout::pageHeader(
            $isEdit ? 'Edit Post' : 'Create Post',
            'Manage article content, taxonomy, publication state, and search metadata.',
            $actions
        );
        $body .= '<form method="post" action="/admin/posts/save" class="bp-form bp-admin-editor">';
        $body .= $this->csrf->field();

        $content = '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($post['title'] ?? '')) . $this->input('Slug', 'slug', $slug) . '<label class="bp-field-wide">Body HTML <textarea class="bp-editor-textarea" name="body" rows="18">' . $this->e((string)($post['body'] ?? '')) . '</textarea><span class="bp-field-help">Use clean HTML for formatted article content. Unsafe markup is sanitized on save.</span></label></div>';
        $publishing = $this->select((string)($post['status'] ?? 'draft')) . $this->input('Category', 'category', (string)($post['category'] ?? 'General')) . $this->input('Tags', 'tags', implode(', ', (array)($post['tags'] ?? [])), false) . '<p class="bp-field-help">Separate tags with commas.</p>' . $this->metaList($post);
        $seo = $this->input('SEO Title', 'seo_title', (string)($post['seo_title'] ?? ''), false) . '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($post['seo_description'] ?? '')) . '</textarea><span class="bp-field-help">Short article summary for search snippets and social previews.</span></label>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Content', $content, 'Write the visible article content.') . '</div><aside class="bp-editor-side">' . $this->editorPanel('Publishing', $publishing, 'Set status, category, and tags.') . $this->editorPanel('SEO', $seo, 'Optional metadata for discovery.') . '</aside>';
        $body .= '<div class="bp-form-actions"><a class="bp-button bp-button-secondary" href="/admin/posts">Cancel</a><button type="submit">Save Post</button></div></form>';
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

    private function toolbar(array $posts): string
    {
        $published = count(array_filter($posts, static fn (array $post): bool => ($post['status'] ?? '') === 'published'));
        $draft = count(array_filter($posts, static fn (array $post): bool => ($post['status'] ?? '') !== 'published'));
        return '<div class="bp-admin-toolbar"><div class="bp-admin-tabs" aria-label="Post status summary"><span class="bp-admin-tab is-active">All ' . count($posts) . '</span><span class="bp-admin-tab">Published ' . $published . '</span><span class="bp-admin-tab">Draft ' . $draft . '</span></div><label class="bp-admin-search">Search <input type="search" placeholder="Search posts" disabled><span>Search arrives in a later workflow phase.</span></label></div>';
    }

    private function statusBadge(string $status): string
    {
        $normalized = $status === 'published' ? 'published' : 'draft';
        return '<span class="bp-status-badge is-' . $normalized . '">' . $this->e(ucfirst($normalized)) . '</span>';
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '<span class="bp-muted">Not published</span>';
        }

        $timestamp = strtotime($value);
        return $timestamp ? $this->e(date('M j, Y H:i', $timestamp)) : $this->e($value);
    }

    private function editorPanel(string $title, string $body, string $description): string
    {
        return '<section class="bp-editor-panel"><header><h2>' . $this->e($title) . '</h2><p>' . $this->e($description) . '</p></header>' . $body . '</section>';
    }

    private function metaList(?array $post): string
    {
        if ($post === null) {
            return '<dl class="bp-meta-list"><div><dt>Mode</dt><dd>New post</dd></div></dl>';
        }

        return '<dl class="bp-meta-list"><div><dt>Author</dt><dd>' . $this->e((string)($post['author'] ?? 'admin')) . '</dd></div><div><dt>Created</dt><dd>' . $this->formatDate((string)($post['created_at'] ?? '')) . '</dd></div><div><dt>Updated</dt><dd>' . $this->formatDate((string)($post['updated_at'] ?? '')) . '</dd></div></dl>';
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
