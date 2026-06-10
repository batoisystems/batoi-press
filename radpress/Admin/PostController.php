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
        $body = '<h1>Posts</h1><p><a href="/admin/posts/new">Create Post</a> · <a href="/admin">Admin</a></p><table class="bp-table"><thead><tr><th>Title</th><th>Status</th><th>Slug</th><th></th></tr></thead><tbody>';
        foreach ($this->posts->all() as $post) {
            $slug = (string)($post['slug'] ?? '');
            $body .= '<tr><td>' . $this->e((string)($post['title'] ?? 'Untitled')) . '</td><td>' . $this->e((string)($post['status'] ?? '')) . '</td><td><code>' . $this->e($slug) . '</code></td><td><a href="/admin/posts/edit/' . rawurlencode($slug) . '">Edit</a></td></tr>';
        }
        $body .= '</tbody></table>';
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
        $body = '<h1>' . ($post ? 'Edit Post' : 'Create Post') . '</h1><form method="post" action="/admin/posts/save" class="bp-form">';
        $body .= $this->csrf->field();
        $body .= $this->input('Title', 'title', (string)($post['title'] ?? ''));
        $body .= $this->input('Slug', 'slug', (string)($post['slug'] ?? ''));
        $body .= $this->select((string)($post['status'] ?? 'draft'));
        $body .= $this->input('Category', 'category', (string)($post['category'] ?? 'General'));
        $body .= $this->input('Tags', 'tags', implode(', ', (array)($post['tags'] ?? [])));
        $body .= $this->input('SEO Title', 'seo_title', (string)($post['seo_title'] ?? ''));
        $body .= '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($post['seo_description'] ?? '')) . '</textarea></label>';
        $body .= '<label>Body HTML <textarea name="body" rows="14">' . $this->e((string)($post['body'] ?? '')) . '</textarea></label>';
        $body .= '<button type="submit">Save Post</button></form><p><a href="/admin/posts">Back to posts</a></p>';
        return $body;
    }

    private function input(string $label, string $name, string $value): string
    {
        return '<label>' . $this->e($label) . ' <input type="text" name="' . $this->e($name) . '" value="' . $this->e($value) . '" required></label>';
    }

    private function select(string $status): string
    {
        $draft = $status === 'draft' ? ' selected' : '';
        $published = $status === 'published' ? ' selected' : '';
        return '<label>Status <select name="status"><option value="draft"' . $draft . '>Draft</option><option value="published"' . $published . '>Published</option></select></label>';
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $this->e($title) . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">' . $body . '</main></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
