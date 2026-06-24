<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\Cache;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Paths;
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
        $allPages = $this->pages->all();
        $allPosts = $this->posts->all();

        $actions = AdminLayout::buttonLink('Create Page', '/admin/pages/new', 'plus') . AdminLayout::buttonLink('Create Post', '/admin/posts/new', 'edit', true);
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

        $body .= '<div class="bp-dashboard-columns">';
        $body .= $this->contentMixChart($allPages, $allPosts);
        $body .= $this->operationsPanel($cache, $paths);
        $body .= '</div>';

        $quickActions = '<div class="bp-admin-action-grid">';
        $quickActions .= $this->actionCard('Create Page', 'Add or edit site pages with SEO metadata.', '/admin/pages/new', 'file');
        $quickActions .= $this->actionCard('Create Post', 'Publish news, articles, and dated updates.', '/admin/posts/new', 'edit');
        $quickActions .= $this->actionCard('Upload Media', 'Add images and downloadable assets.', '/admin/media', 'image');
        $quickActions .= $this->actionCard('Check Updates', 'Review stable release availability.', '/admin/updates', 'refresh');
        $quickActions .= '</div>';
        $body .= AdminLayout::section('Quick Actions', $quickActions, 'Common publishing and operations tasks.');

        $workflow = '<div class="bp-workflow-grid">';
        $workflow .= $this->workflowStep('1', 'Draft', 'Create pages or posts and keep them in draft until ready.', 'complete');
        $workflow .= $this->workflowStep('2', 'Review', 'Check SEO metadata, navigation placement, and media links.', 'current');
        $workflow .= $this->workflowStep('3', 'Publish', 'Switch status to published and verify the public route.', 'idle');
        $workflow .= $this->workflowStep('4', 'Maintain', 'Back up, update, export, and monitor audit events.', 'idle');
        $workflow .= '</div>';
        $body .= AdminLayout::section('Publishing Workflow', $workflow, 'A compact operating path for content teams.');

        $guidance = '<div class="bp-admin-guidance-grid">';
        $guidance .= $this->guidanceCard('Daily operation', 'Review recent content, publish approved changes, and verify public routes after saves.', 'check');
        $guidance .= $this->guidanceCard('Maintenance', 'Use cache, export, updates, and audit tools after template edits or release work.', 'settings');
        $guidance .= $this->guidanceCard('Governance', 'Keep users limited to required roles and review audit events for sensitive activity.', 'shield');
        $guidance .= '</div>';
        $body .= AdminLayout::section('Operating Guide', $guidance, 'Recommended admin-console rhythm for publishing and site operations.');

        $recent = '<div class="bp-dashboard-columns">';
        $recent .= $this->contentPanel('Recent Pages', $allPages, '/admin/pages/edit/');
        $recent .= $this->contentPanel('Recent Posts', $allPosts, '/admin/posts/edit/');
        $recent .= '</div>';
        $body .= AdminLayout::section('Recent Content', $recent, 'Latest editable content records.');

        $operations = '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $operations .= AdminLayout::statCard('Cache files', (string)(int)$cache['files'], ((bool)$cache['writable'] ? 'Writable' : 'Not writable'));
        $operations .= AdminLayout::statCard('Config', is_writable($paths->configPath()) ? 'Writable' : 'Locked', 'Configuration directory');
        $operations .= AdminLayout::statCard('Content', is_writable($paths->contentPath()) ? 'Writable' : 'Locked', 'Content directory');
        $operations .= AdminLayout::statCard('Signed in', (string)($this->user['username'] ?? 'admin'), (string)($this->user['role'] ?? 'admin'));
        $operations .= '</dl>';
        $body .= AdminLayout::section('Operational Status', $operations, 'Runtime readiness at a glance.');

        return Response::html(AdminLayout::render('Admin', $body));
    }

    private function actionCard(string $title, string $description, string $href, string $icon): string
    {
        return '<a class="bp-admin-action-card" href="' . $this->e($href) . '"><em>' . AdminLayout::icon($icon) . '</em><strong>' . $this->e($title) . '</strong><span>' . $this->e($description) . '</span></a>';
    }

    private function workflowStep(string $number, string $title, string $description, string $state): string
    {
        return '<div class="bp-workflow-step is-' . $this->e($state) . '"><span>' . $this->e($number) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></div>';
    }

    private function guidanceCard(string $title, string $description, string $icon): string
    {
        return '<article><span>' . AdminLayout::icon($icon) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></article>';
    }

    private function contentPanel(string $title, array $items, string $editPrefix): string
    {
        $html = '<section class="bp-mini-panel"><header><h3>' . $this->e($title) . '</h3></header>';
        if ($items === []) {
            return $html . '<p class="bp-muted">No content yet.</p></section>';
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        $html .= '<ul class="bp-content-mini-list">';
        foreach (array_slice($items, 0, 4) as $item) {
            $slug = (string)($item['slug'] ?? '');
            $html .= '<li><div><strong>' . $this->e((string)($item['title'] ?? 'Untitled')) . '</strong><span>' . $this->e((string)($item['status'] ?? 'draft')) . ' · ' . $this->e($this->formatDate((string)($item['updated_at'] ?? ''))) . '</span></div><a href="' . $this->e($editPrefix . rawurlencode($slug)) . '">Edit</a></li>';
        }
        return $html . '</ul></section>';
    }

    private function formatDate(string $value): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp ? date('M j, Y', $timestamp) : 'Not saved';
    }

    private function contentMixChart(array $pages, array $posts): string
    {
        $publishedPages = $this->countStatus($pages, 'published');
        $draftPages = max(0, count($pages) - $publishedPages);
        $publishedPosts = $this->countStatus($posts, 'published');
        $draftPosts = max(0, count($posts) - $publishedPosts);
        $max = max(1, $publishedPages, $draftPages, $publishedPosts, $draftPosts);
        $bars = [
            ['label' => 'Pages live', 'axis' => 'Pages', 'value' => $publishedPages, 'color' => 'var(--bp-accent)'],
            ['label' => 'Pages draft', 'axis' => 'Draft', 'value' => $draftPages, 'color' => 'var(--bp-amber)'],
            ['label' => 'Posts live', 'axis' => 'Posts', 'value' => $publishedPosts, 'color' => 'var(--bp-success)'],
            ['label' => 'Posts draft', 'axis' => 'Draft', 'value' => $draftPosts, 'color' => 'var(--bp-danger)'],
        ];

        $svg = '<svg class="uif-chart-svg bp-dashboard-chart" viewBox="0 0 420 170" role="img" aria-labelledby="contentMixTitle contentMixDesc"><title id="contentMixTitle">Content publication mix</title><desc id="contentMixDesc">Published and draft content counts for pages and posts.</desc><line class="uif-chart-grid" x1="40" y1="130" x2="390" y2="130"></line>';
        $x = 60;
        foreach ($bars as $bar) {
            $height = (int)round((((int)$bar['value']) / $max) * 92);
            $y = 130 - $height;
            $svg .= '<rect class="uif-chart-mark" x="' . $x . '" y="' . $y . '" width="46" height="' . $height . '" rx="5" style="fill:' . $this->e((string)$bar['color']) . '"></rect>';
            $svg .= '<text x="' . ($x + 23) . '" y="' . max(18, $y - 8) . '" text-anchor="middle">' . $this->e((string)$bar['value']) . '</text>';
            $svg .= '<text x="' . ($x + 23) . '" y="152" text-anchor="middle">' . $this->e((string)$bar['axis']) . '</text>';
            $x += 82;
        }
        $svg .= '</svg>';

        $legend = '<div class="uif-chart-legend bp-chart-legend">';
        foreach ($bars as $bar) {
            $legend .= '<span><i style="background:' . $this->e((string)$bar['color']) . '"></i>' . $this->e((string)$bar['label']) . '</span>';
        }
        $legend .= '</div>';

        return '<section class="bp-admin-section bp-chart-card"><header><div><h2>Content Mix</h2><p>Published and draft inventory by content type.</p></div></header>' . $svg . $legend . '</section>';
    }

    private function operationsPanel(array $cache, Paths $paths): string
    {
        $items = [
            ['label' => 'Cache', 'value' => ((int)$cache['files']) . ' files', 'state' => ((bool)$cache['writable']) ? 'Ready' : 'Check'],
            ['label' => 'Config', 'value' => is_writable($paths->configPath()) ? 'Writable' : 'Locked', 'state' => 'Config'],
            ['label' => 'Content', 'value' => is_writable($paths->contentPath()) ? 'Writable' : 'Locked', 'state' => 'Content'],
        ];

        $html = '<section class="bp-admin-section"><header><div><h2>Runtime Readiness</h2><p>Operational signals that affect publishing and updates.</p></div></header><div class="bp-readiness-list">';
        foreach ($items as $item) {
            $html .= '<div><span>' . AdminLayout::icon('shield') . '</span><div><strong>' . $this->e($item['label']) . '</strong><small>' . $this->e($item['value']) . '</small></div><em>' . $this->e($item['state']) . '</em></div>';
        }
        return $html . '</div></section>';
    }

    private function countStatus(array $items, string $status): int
    {
        return count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') === $status));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
