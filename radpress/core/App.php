<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;

final class App
{
    public function __construct(private readonly string $root)
    {
    }

    public function handle(Request $request): Response
    {
        $config = Config::load($this->root);
        $maintenance = new MaintenanceMode($config->paths());
        if ($maintenance->active() && !str_starts_with($request->path, '/admin')) {
            return $maintenance->response();
        }

        $files = new FileStore();
        $html = new HtmlContent();
        $theme = new Theme($config->paths(), $config->site());
        $pages = new PageRepository($config->paths(), $files, $html);
        $posts = new PostRepository($config->paths(), $files, $html);

        return (new Router($theme, $pages, $posts, $config))->dispatch($request);
    }
}
