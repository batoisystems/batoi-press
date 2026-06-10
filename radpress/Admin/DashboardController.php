<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\Cache;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;

final class DashboardController
{
    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts
    ) {
    }

    public function index(): Response
    {
        $cache = (new Cache($this->config->paths()))->status();
        $site = $this->config->site();

        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Admin | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">';
        $body .= '<h1>Batoi Press Admin</h1>';
        $body .= '<p>This Phase 1 dashboard is read-only. Full authentication and content management come in the next implementation phase.</p>';
        $body .= '<dl class="bp-stats">';
        $body .= '<div><dt>Site</dt><dd>' . htmlspecialchars((string)($site['name'] ?? 'Batoi Press')) . '</dd></div>';
        $body .= '<div><dt>Pages</dt><dd>' . count($this->pages->allPublished()) . '</dd></div>';
        $body .= '<div><dt>Posts</dt><dd>' . count($this->posts->allPublished()) . '</dd></div>';
        $body .= '<div><dt>Cache files</dt><dd>' . (int)$cache['files'] . '</dd></div>';
        $body .= '</dl>';
        $body .= '<p><a href="/admin/updates">Check update status</a> · <a href="/">View site</a></p>';
        $body .= '</main></body></html>';

        return Response::html($body);
    }
}

