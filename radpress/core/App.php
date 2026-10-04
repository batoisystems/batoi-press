<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Security\SecurityHeaders;

final class App
{
    public function __construct(private readonly string $root)
    {
    }

    public function handle(Request $request): Response
    {
        unset($GLOBALS['bp_plugin_context']);
        // Keep response middleware from the same code generation during an update request.
        class_exists(SecurityHeaders::class);
        class_exists(Response::class);
        $config = Config::load($this->root);
        if (!str_starts_with($request->path, '/admin')) {
            try { $GLOBALS['bp_plugin_context'] = (new PluginManager($config->paths()))->boot(); }
            catch (\RuntimeException) { return SecurityHeaders::apply(Response::html('An optional extension is unavailable. Please try again later.', 503), $request, $config); }
        }
        $maintenance = new MaintenanceMode($config->paths());
        if ($maintenance->active() && !str_starts_with($request->path, '/admin')) {
            return SecurityHeaders::apply($maintenance->response(), $request, $config);
        }

        $files = new FileStore();
        $html = new HtmlContent();
        $theme = new Theme($config->paths(), $config->site());
        $pages = new PageRepository($config->paths(), $files, $html);
        $posts = new PostRepository($config->paths(), $files, $html);
        $products = new ProductRepository($config->paths(), $files, $html);

        $response = (new Router($theme, $pages, $posts, $config, $products))->dispatch($request);
        if (str_starts_with($request->path, '/admin')) $response = $response->withHeader('Cache-Control', 'private, no-store');
        return SecurityHeaders::apply($response, $request, $config)->withHeader('X-Request-ID', (string)($response->headers()['X-Request-ID'] ?? $request->requestId));
    }
}
