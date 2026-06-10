<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\Cache;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class DashboardController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly Csrf $csrf,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $cache = (new Cache($this->config->paths()))->status();
        $site = $this->config->site();

        $body = '<h1>Batoi Press Admin</h1>';
        $body .= '<p>Signed in as <strong>' . htmlspecialchars((string)($this->user['username'] ?? 'admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>';
        $body .= '<p>Manage content, media, settings, static export, cache, users, and governed updates from this dashboard.</p>';
        $body .= '<dl class="bp-stats">';
        $body .= '<div><dt>Site</dt><dd>' . htmlspecialchars((string)($site['name'] ?? 'Batoi Press')) . '</dd></div>';
        $body .= '<div><dt>Pages</dt><dd>' . count($this->pages->allPublished()) . '</dd></div>';
        $body .= '<div><dt>Posts</dt><dd>' . count($this->posts->allPublished()) . '</dd></div>';
        $body .= '<div><dt>Cache files</dt><dd>' . (int)$cache['files'] . '</dd></div>';
        $body .= '</dl>';
        $body .= '<nav class="bp-admin-nav bp-uif-toolbar"><a href="/admin/pages">Pages</a><a href="/admin/posts">Posts</a><a href="/admin/media">Media</a><a href="/admin/menus">Menus</a><a href="/admin/settings">Settings</a><a href="/admin/users">Users</a><a href="/admin/cache">Cache</a><a href="/admin/export-static">Static Export</a><a href="/admin/aif">Batoi AIF</a><a href="/admin/updates">Updates</a><a href="/">View site</a></nav>';
        $body .= '<form method="post" action="/admin/logout" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Log Out</button></form>';

        return Response::html(AdminLayout::render('Admin', $body));
    }
}
