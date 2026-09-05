<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\IdempotencyStore;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PublicationState;
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
        private readonly PageRepository $pages,
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
            $publishedAt = (string)($post['publish_at'] ?? $post['published_at'] ?? $post['updated_at'] ?? '');
            $body .= '<tr><td><strong>' . $this->e($title) . '</strong><small>' . (!empty($post['parent_slug']) ? 'Child post' : 'Post') . '</small></td><td>' . $this->statusBadge((string)($post['status'] ?? 'draft')) . '</td><td>' . $this->e((string)($post['category'] ?? 'General')) . '</td><td>' . $this->formatDate($publishedAt) . '</td><td><code>' . $this->e($this->posts->publicPath($post)) . '</code></td><td><div class="bp-table-actions"><a href="' . $this->e($this->posts->publicPath($post)) . '">View</a><a href="/admin/posts/edit/' . rawurlencode($slug) . '">Edit</a><a href="/admin/posts/new?parent=' . rawurlencode($slug) . '">Add child</a></div></td></tr>';
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
            (new ContentMutationService($this->config, $this->pages, $this->posts, $this->audit, new IdempotencyStore($this->config->paths())))->saveFromAdmin(
                'post',
                $request->post,
                $request->input('expected_revision'),
                (string)($this->user['username'] ?? 'admin'),
                'admin_' . bin2hex(random_bytes(8))
            );
        } catch (RuntimeException $exception) {
            return Response::html($this->layout('Posts', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p>' . AdminLayout::buttonLink('Back to posts', '/admin/posts', 'back', true) . '</p>'), 409);
        }
        return Response::redirect('/admin/posts');
    }

    private function form(?array $post): string
    {
        $isEdit = $post !== null;
        $slug = (string)($post['slug'] ?? '');
        $requestedParent = Slug::normalize((string)($post['parent_slug'] ?? $_GET['parent'] ?? ''));
        if ($requestedParent !== '' && $this->posts->findBySlug($requestedParent) === null) {
            $requestedParent = '';
        }
        $actions = AdminLayout::buttonLink('Back to posts', '/admin/posts', 'back', true);
        if ($isEdit) {
            $actions = AdminLayout::buttonLink('View post', $this->posts->publicPath($post), 'site', true) . AdminLayout::buttonLink('Add child post', '/admin/posts/new?parent=' . rawurlencode($slug), 'plus', true) . $actions;
        }

        $body = AdminLayout::pageHeader(
            $isEdit ? 'Edit Post' : 'Create Post',
            'Manage article content, taxonomy, publication state, and search metadata.',
            $actions
        );
        $body .= '<form method="post" action="/admin/posts/save" class="bp-form bp-admin-editor" novalidate>';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="original_slug" value="' . $this->e($slug) . '">';
        $body .= '<input type="hidden" name="expected_revision" value="' . $this->e($post === null ? '' : ContentRevision::for($post)) . '">';

        $content = '<div class="bp-form-grid">' . $this->input('Title', 'title', (string)($post['title'] ?? ''), true, 'data-bp-slug-source') . $this->input('Slug', 'slug', $slug, true, 'data-bp-slug-target') . '<label class="bp-field-wide">Subtitle <textarea name="subtitle" rows="3" maxlength="300">' . $this->e((string)($post['subtitle'] ?? '')) . '</textarea><span class="bp-field-help">Optional short description shown below the article title.</span></label>' . $this->bodyEditor((string)($post['body'] ?? ''), 'Use clean HTML for formatted article content. Scripts, unsafe URLs, events, and inline styles are sanitized before saving.') . '</div>';
        $publishing = $this->select((string)($post['status'] ?? 'draft')) . $this->publishDateInput((string)($post['publish_at'] ?? $post['published_at'] ?? '')) . $this->dateTimeInput('Unpublish date', 'unpublish_at', (string)($post['unpublish_at'] ?? ''), 'Optional automatic public visibility cutoff.') . $this->input('Reviewer', 'reviewer', (string)($post['reviewer'] ?? ''), false) . '<label>Workflow note <textarea name="workflow_note" rows="3" maxlength="500"></textarea><span class="bp-field-help">Saved to workflow history; never displayed publicly.</span></label>' . $this->parentSelect($requestedParent, $slug) . $this->categoryInput((string)($post['category'] ?? 'General')) . $this->layoutSelect((string)($post['layout'] ?? 'full')) . $this->input('Tags', 'tags', implode(', ', (array)($post['tags'] ?? [])), false) . '<p class="bp-field-help">Separate tags with commas.</p>' . $this->workflowHistory($post) . $this->metaList($post);
        $media = $this->input('Featured image URL', 'featured_image', (string)($post['featured_image'] ?? ''), false) . $this->input('Featured image alt text', 'featured_image_alt', (string)($post['featured_image_alt'] ?? ''), false) . '<p class="bp-field-help">Use a public image URL from <a href="/admin/media?type=images" target="_blank" rel="noopener">Media</a>. Describe meaningful images for screen-reader users; leave alt text blank only for decorative images.</p>';
        $seo = $this->input('SEO Title', 'seo_title', (string)($post['seo_title'] ?? ''), false) . '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($post['seo_description'] ?? '')) . '</textarea><span class="bp-field-help">Short article summary for search snippets and social previews.</span></label>';

        $body .= '<div class="bp-editor-main">' . $this->editorPanel('Content', $content, 'Write the visible article content.') . '</div><aside class="bp-editor-side">' . AifEditorPanel::render($this->config, 'post') . $this->editorPanel('Publishing', $publishing, 'Set status, category, layout, and tags.') . $this->editorPanel('Featured image', $media, 'Choose the primary image used by public post views.') . $this->editorPanel('SEO', $seo, 'Optional metadata for discovery.') . $this->editorPanel('Pre-publish checklist', $this->postChecklist(), 'Review before publishing or changing a live post.') . '</aside>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/posts', 'back', true) . AdminLayout::submitButton('Save Post', 'save') . '</div></form>';
        return $body;
    }

    private function input(string $label, string $name, string $value, bool $required = true, string $attributes = ''): string
    {
        $requiredAttribute = $required ? ' required' : '';
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $requiredAttribute . ($attributes !== '' ? ' ' . $attributes : '') . '></label>';
    }

    private function bodyEditor(string $value, string $help): string
    {
        return ContentEditor::render($this->config, $value, $help, 'bp-post-body');
    }

    private function select(string $status): string
    {
        $html = '<label>Status <select name="status">';
        foreach (PublicationState::STATUSES as $value) {
            $html .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e(PublicationState::label($value)) . '</option>';
        }
        return $html . '</select><span class="bp-field-help">Use In review and Approved for handoff; Scheduled requires a publish date.</span></label>';
    }

    private function publishDateInput(string $value): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        $formatted = $timestamp !== false ? date('Y-m-d\TH:i', $timestamp) : '';
        return '<label>Publish date <input type="datetime-local" name="publish_at" value="' . $this->e($formatted) . '"><span class="bp-field-help">Published content uses this date; Scheduled content becomes public when it is due.</span></label>';
    }

    private function dateTimeInput(string $label, string $name, string $value, string $help): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        $formatted = $timestamp !== false ? date('Y-m-d\TH:i', $timestamp) : '';
        return '<label>' . $this->e($label) . ' <input type="datetime-local" name="' . $this->e($name) . '" value="' . $this->e($formatted) . '"><span class="bp-field-help">' . $this->e($help) . '</span></label>';
    }

    private function categoryInput(string $selected): string
    {
        $categories = [];
        foreach ($this->posts->all() as $post) {
            $category = trim((string)($post['category'] ?? ''));
            if ($category !== '') {
                $categories[$category] = true;
            }
        }
        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        $options = '';
        foreach (array_keys($categories) as $category) {
            $options .= '<option value="' . $this->e($category) . '"></option>';
        }
        return '<label>Category <input type="text" name="category" value="' . $this->e($selected) . '" list="bp-post-categories" required><datalist id="bp-post-categories">' . $options . '</datalist><span class="bp-field-help">Choose an existing category or enter a new name to create it with this post.</span></label>';
    }

    private function layoutSelect(string $selected): string
    {
        $html = '<label>Post layout <select name="layout">';
        foreach (['full' => 'Full width', 'sidebar-right' => 'Right sidebar', 'sidebar-left' => 'Left sidebar'] as $value => $label) {
            $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        return $html . '</select><span class="bp-field-help">Sidebar layouts show configured widgets and recent posts.</span></label>';
    }

    private function parentSelect(string $selected, string $currentSlug): string
    {
        $options = '<option value="">Top level</option>';
        foreach ($this->posts->all() as $post) {
            $slug = (string)($post['slug'] ?? '');
            if ($slug === '' || $slug === $currentSlug) {
                continue;
            }
            $label = (string)($post['title'] ?? $slug) . ' (' . $this->posts->publicPath($post) . ')';
            $options .= '<option value="' . $this->e($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return '<label>Parent post <select name="parent_slug">' . $options . '</select><span class="bp-field-help">Use parent posts to group related series such as Blog, News, and Activities.</span></label>';
    }

    private function toolbar(array $posts, array $filters): string
    {
        $published = count(array_filter($posts, static fn (array $post): bool => PublicationState::isPublic($post)));
        $draft = count(array_filter($posts, static fn (array $post): bool => ($post['status'] ?? 'draft') === 'draft'));
        $status = (string)($filters['status'] ?? '');
        $html = '<div class="bp-admin-toolbar"><div class="bp-admin-tabs" aria-label="Post status summary"><span class="bp-admin-tab is-active">All ' . count($posts) . '</span><span class="bp-admin-tab">Published ' . $published . '</span><span class="bp-admin-tab">Draft ' . $draft . '</span></div></div>';
        $html .= '<form method="get" action="/admin/posts" class="bp-filter-form bp-filter-form-compact"><div class="bp-filter-field bp-filter-field-search"><label for="bp-post-filter-q">Search</label><input id="bp-post-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Title, slug, category, or tag"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-post-filter-status">Status</label><select id="bp-post-filter-status" name="status"><option value="">All statuses</option>';
        foreach (PublicationState::STATUSES as $value) {
            $label = PublicationState::label($value);
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
        $normalized = PublicationState::normalize($status);
        return '<span class="bp-status-badge is-' . str_replace('_', '-', $normalized) . '">' . $this->e(PublicationState::label($normalized)) . '</span>';
    }

    private function workflowHistory(?array $post): string
    {
        $history = array_reverse(array_slice((array)($post['workflow_history'] ?? []), -5));
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
