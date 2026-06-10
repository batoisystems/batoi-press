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
        $body = '<h1>Pages</h1><p><a href="/admin/pages/new">Create Page</a> · <a href="/admin">Admin</a></p><table class="bp-table"><thead><tr><th>Title</th><th>Status</th><th>Slug</th><th></th></tr></thead><tbody>';
        foreach ($this->pages->all() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $body .= '<tr><td>' . $this->e((string)($page['title'] ?? 'Untitled')) . '</td><td>' . $this->e((string)($page['status'] ?? '')) . '</td><td><code>' . $this->e($slug) . '</code></td><td><a href="/admin/pages/edit/' . rawurlencode($slug) . '">Edit</a></td></tr>';
        }
        $body .= '</tbody></table>';
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
        $body = '<h1>' . ($page ? 'Edit Page' : 'Create Page') . '</h1><form method="post" action="/admin/pages/save" class="bp-form">';
        $body .= $this->csrf->field();
        $body .= $this->input('Title', 'title', (string)($page['title'] ?? ''));
        $body .= $this->input('Slug', 'slug', (string)($page['slug'] ?? ''));
        $body .= $this->select((string)($page['status'] ?? 'draft'));
        $body .= $this->input('SEO Title', 'seo_title', (string)($page['seo_title'] ?? ''));
        $body .= '<label>SEO Description <textarea name="seo_description">' . $this->e((string)($page['seo_description'] ?? '')) . '</textarea></label>';
        $body .= '<label>Body HTML <textarea name="body" rows="14">' . $this->e((string)($page['body'] ?? '')) . '</textarea></label>';
        $body .= '<button type="submit">Save Page</button></form><p><a href="/admin/pages">Back to pages</a></p>';
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
