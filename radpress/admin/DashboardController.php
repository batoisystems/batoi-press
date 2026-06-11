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
        $update = $this->config->update();
        $paths = $this->config->paths();
        $pages = $this->pages->allPublished();
        $posts = $this->posts->allPublished();

        $actions = '<a class="bp-button" href="/admin/pages/new">Create Page</a><a class="bp-button bp-button-secondary" href="/admin/posts/new">Create Post</a>';
        $body = AdminLayout::pageHeader(
            'Dashboard',
            'Manage publishing, site operations, users, updates, and governed Batoi AIF readiness.',
            $actions
        );

        $body .= '<dl class="bp-admin-stats">';
        $body .= AdminLayout::statCard('Site', (string)($site['name'] ?? 'Batoi Press'), (string)($site['base_url'] ?? 'No base URL configured'));
        $body .= AdminLayout::statCard('Pages', (string)count($pages), 'Published pages');
        $body .= AdminLayout::statCard('Posts', (string)count($posts), 'Published posts');
        $body .= AdminLayout::statCard('Version', (string)($update['current_version'] ?? '0.1.0'), (string)($update['channel'] ?? 'stable') . ' channel');
        $body .= '</dl>';

        $quickActions = '<div class="bp-admin-action-grid">';
        $quickActions .= $this->actionCard('Create Page', 'Add or edit site pages with SEO metadata.', '/admin/pages/new');
        $quickActions .= $this->actionCard('Create Post', 'Publish news, articles, and dated updates.', '/admin/posts/new');
        $quickActions .= $this->actionCard('Upload Media', 'Add images and downloadable assets.', '/admin/media');
        $quickActions .= $this->actionCard('Check Updates', 'Review stable release availability.', '/admin/updates');
        $quickActions .= '</div>';
        $body .= AdminLayout::section('Quick Actions', $quickActions, 'Common publishing and operations tasks.');

        $operations = '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $operations .= AdminLayout::statCard('Cache files', (string)(int)$cache['files'], ((bool)$cache['writable'] ? 'Writable' : 'Not writable'));
        $operations .= AdminLayout::statCard('Config', is_writable($paths->configPath()) ? 'Writable' : 'Locked', 'Configuration directory');
        $operations .= AdminLayout::statCard('Content', is_writable($paths->contentPath()) ? 'Writable' : 'Locked', 'Content directory');
        $operations .= AdminLayout::statCard('Signed in', (string)($this->user['username'] ?? 'admin'), (string)($this->user['role'] ?? 'admin'));
        $operations .= '</dl>';
        $body .= AdminLayout::section('Operational Status', $operations, 'Runtime readiness at a glance.');

        $body .= '<form method="post" action="/admin/logout" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Log Out</button></form>';

        return Response::html(AdminLayout::render('Admin', $body));
    }

    private function actionCard(string $title, string $description, string $href): string
    {
        return '<a class="bp-admin-action-card" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><strong>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><span>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></a>';
    }
}
