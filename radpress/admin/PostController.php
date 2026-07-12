<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Slug;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class PostController
{
    public function __construct(
        private readonly Config $config,
        private readonly PostRepository $posts,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $posts = AdminAccess::filterManageablePosts($this->user, $this->posts->all());
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $filteredPosts = $this->filterPosts($posts, $filters);
        $body = AdminLayout::pageHeader(
            'Posts',
            'Plan, draft, and publish dated articles for the site.',
            AdminLayout::buttonLink('Create Post', '/admin/posts/new', 'plus')
        );
        $body .= $this->toolbar($posts, $filters);
        $body .= AdminLayout::section(
            'Post standards',
            $this->postStandards(),
            'Use posts for dated updates, articles, announcements, and editorial content.'
        );

        if ($posts === []) {
            $body .= '<section class="bp-empty-state"><h2>No posts yet</h2><p>Create the first article. Posts are stored as HTML content with JSON metadata.</p>' . AdminLayout::buttonLink('Create Post', '/admin/posts/new', 'plus') . '</section>';
            return Response::html($this->layout('Posts', $body));
        }

        if ($filteredPosts === []) {
            $body .= '<section class="bp-empty-state"><h2>No posts match these filters</h2><p>Adjust the search or status filters to review other posts.</p>' . AdminLayout::buttonLink('Reset Filters', '/admin/posts', 'back') . '</section>';
            return Response::html($this->layout('Posts', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Title</th><th>Status</th><th>Category</th><th>Published</th><th>Slug</th><th>Actions</th></tr></thead><tbody>';
        foreach ($filteredPosts as $post) {
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
        if ($post !== null && !AdminAccess::canManagePost($this->user, $post)) {
            return $this->forbiddenPost((string)($post['slug'] ?? $slug));
        }
        return Response::html($this->layout($slug ? 'Edit Post' : 'Create Post', $this->form($post)));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Posts', '<p class="bp-error">Security token expired.</p><p><a href="/admin/posts">Back to posts</a></p>'), 400);
        }

        $slug = Slug::normalize($request->input('slug'));
        $originalSlug = Slug::normalize($request->input('original_slug'));
        $existing = $originalSlug !== '' ? $this->posts->findBySlug($originalSlug) : $this->posts->findBySlug($slug);
        if ($existing !== null && !AdminAccess::canManagePost($this->user, $existing)) {
            return $this->forbiddenPost((string)($existing['slug'] ?? $slug));
        }
        if ($originalSlug !== '' && $originalSlug !== $slug && $this->posts->findBySlug($slug) !== null) {
            return Response::html($this->layout('Posts', '<p class="bp-error">A post with this slug already exists.</p><p>' . AdminLayout::buttonLink('Back to posts', '/admin/posts', 'back', true) . '</p>'), 409);
        }

        try {
            $meta = $this->posts->save($request->post, (string)($this->user['username'] ?? 'admin'));
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Posts', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to posts', '/admin/posts', 'back', true) . '</p>'), 409);
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'post.updated', (string)$meta['slug'], (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/posts');
    }

    private function form(?array $post): string
    {
        $isEdit = $post !== null;
        $slug = (string)($post['slug'] ?? '');
        $actions = AdminLayout::buttonLink('Back to posts', '/admin/posts', 'back', true);
        if ($isEdit) {
            $actions = AdminLayout::buttonLink('View post', '/blog/' . rawurlencode($slug), 'site', true) . $actions;
        }

        $body = AdminLayout::pageHeader(
            $isEdit ? 'Edit Post' : 'Create Post',
            'Manage article content, taxonomy, publication state, and search metadata.',
            $actions
        );
        $body .= '<form method="post" action="/admin/posts/save" class="bp-form bp-admin-editor" novalidate>';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="original_slug" value="' . $this->e($slug) . '">';

        $content = '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($post['title'] ?? '')) . $this->input('Slug', 'slug', $slug) . $this->bodyEditor((string)($post['body'] ?? ''), 'Use clean HTML for formatted article content. Scripts, unsafe URLs, events, and inline styles are sanitized before saving.') . '</div>';
        $publishing = $this->select((string)($post['status'] ?? 'draft')) . $this->input('Category', 'category', (string)($post['category'] ?? 'General')) . $this->input('Tags', 'tags', implode(', ', (array)($post['tags'] ?? [])), false) . '<p class="bp-field-help">Separate tags with commas.</p>' . $this->metaList($post);
        $seo = $this->input('SEO Title', 'seo_title', (string)($post['seo_title'] ?? ''), false) . '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($post['seo_description'] ?? '')) . '</textarea><span class="bp-field-help">Short article summary for search snippets and social previews.</span></label>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Content', $content, 'Write the visible article content.') . '</div><aside class="bp-editor-side">' . $this->editorPanel('Publishing', $publishing, 'Set status, category, and tags.') . $this->editorPanel('SEO', $seo, 'Optional metadata for discovery.') . $this->editorPanel('Pre-publish checklist', $this->postChecklist(), 'Review before publishing or changing a live post.') . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/posts', 'back', true) . AdminLayout::submitButton('Save Post', 'save') . '</div></form>';
        return $body;
    }

    private function input(string $label, string $name, string $value, bool $required = true): string
    {
        $requiredAttribute = $required ? ' required' : '';
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $requiredAttribute . '></label>';
    }

    private function bodyEditor(string $value, string $help): string
    {
        return ContentEditor::render($this->config, $value, $help, 'bp-post-body');
    }

    private function select(string $status): string
    {
        $draft = $status === 'draft' ? ' selected' : '';
        $published = $status === 'published' ? ' selected' : '';
        return '<label>Status <select name="status"><option value="draft"' . $draft . '>Draft</option><option value="published"' . $published . '>Published</option></select></label>';
    }

    private function toolbar(array $posts, array $filters): string
    {
        $published = count(array_filter($posts, static fn (array $post): bool => ($post['status'] ?? '') === 'published'));
        $draft = count(array_filter($posts, static fn (array $post): bool => ($post['status'] ?? '') !== 'published'));
        $status = (string)($filters['status'] ?? '');
        $html = '<div class="bp-admin-toolbar"><div class="bp-admin-tabs" aria-label="Post status summary"><span class="bp-admin-tab is-active">All ' . count($posts) . '</span><span class="bp-admin-tab">Published ' . $published . '</span><span class="bp-admin-tab">Draft ' . $draft . '</span></div></div>';
        $html .= '<form method="get" action="/admin/posts" class="bp-filter-form bp-filter-form-compact"><div class="bp-filter-field bp-filter-field-search"><label for="bp-post-filter-q">Search</label><input id="bp-post-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Title, slug, category, or tag"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-post-filter-status">Status</label><select id="bp-post-filter-status" name="status"><option value="">All statuses</option>';
        foreach (['published' => 'Published', 'draft' => 'Draft'] as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html . '</select></div><div class="bp-filter-actions">' . AdminLayout::submitButton('Apply Filters', 'check') . AdminLayout::buttonLink('Reset', '/admin/posts', 'back', true) . '</div></form>';
    }

    private function filterPosts(array $posts, array $filters): array
    {
        $q = strtolower((string)($filters['q'] ?? ''));
        $status = (string)($filters['status'] ?? '');

        return array_values(array_filter($posts, static function (array $post) use ($q, $status): bool {
            $postStatus = (string)($post['status'] ?? 'draft');
            if ($status !== '' && $postStatus !== $status) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            $haystack = strtolower(implode(' ', [
                (string)($post['title'] ?? ''),
                (string)($post['slug'] ?? ''),
                (string)($post['category'] ?? ''),
                implode(' ', array_map('strval', (array)($post['tags'] ?? []))),
                (string)($post['seo_title'] ?? ''),
                (string)($post['seo_description'] ?? ''),
            ]));
            return str_contains($haystack, $q);
        }));
    }

    private function postStandards(): string
    {
        return '<div class="bp-admin-guidance-grid">'
            . $this->guidanceCard('Editorial flow', 'Draft first, review taxonomy and links, then publish after content approval.', 'edit')
            . $this->guidanceCard('Taxonomy', 'Use categories for broad grouping and comma-separated tags for specific topics.', 'menu')
            . $this->guidanceCard('Public archive', 'Published posts appear in the blog archive and feed when available.', 'site')
            . '</div>';
    }

    private function postChecklist(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Title, slug, category, and tags match the article intent.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Body content uses clean headings, links, and accessible media.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>SEO metadata is present for posts expected to receive search traffic.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Published posts are verified from the View post action after saving.</span></li>'
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

    private function forbiddenPost(string $slug): Response
    {
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'post.access_blocked', $slug, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'blocked', [
            'role' => AdminAccess::role($this->user),
        ]);
        $body = AdminLayout::pageHeader(
            'Post Access Restricted',
            'Your role can only manage posts assigned to your account.',
            AdminLayout::buttonLink('Back to posts', '/admin/posts', 'back', true)
        );
        $body .= '<section class="bp-empty-state"><h2>Permission required</h2><p>Access to this post is limited by authorship or role.</p></section>';
        return Response::html($this->layout('Post Access Restricted', $body), 403);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
