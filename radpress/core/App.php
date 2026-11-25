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
        $site = $config->site();
        $redirect = PublicDelivery::canonicalHost($request, (string)($site['base_url'] ?? ''));
        if ($redirect !== null) return SecurityHeaders::apply($redirect, $request, $config)->withHeader('X-Request-ID', $request->requestId);
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
        $theme = new Theme($config->paths(), $site, PHP_SAPI !== 'cli' && $request->method === 'GET'
            && PublicDelivery::isPublicRead($request));
        $pages = new PageRepository($config->paths(), $files, $html);
        $posts = new PostRepository($config->paths(), $files, $html);
        $products = new ProductRepository($config->paths(), $files, $html);

        $manualMaintenance = !empty($site['maintenance_enabled']) && !str_starts_with($request->path, '/admin');
        $assetRequest = preg_match('#^/(?:assets|theme-assets|media)/#', $request->path) === 1;
        $adminPreview = false;
        if ($manualMaintenance && !$assetRequest) {
            $settings = (array)($config->security()['session'] ?? []);
            $sessionName = (string)($config->security()['session_name'] ?? 'batoi_press_session');
            // Do not create sessions for anonymous visitors or trust cookie presence as authentication.
            if (isset($_COOKIE[$sessionName])) {
                $session = new \Batoi\Press\Security\Session($sessionName, $config->paths()->dataPath('sessions'), max(60, (int)($settings['idle_seconds'] ?? 1800)), max(300, (int)($settings['absolute_seconds'] ?? 43200)));
                $user = (new \Batoi\Press\Security\Auth($config->paths(), $session, $files))->user();
                $adminPreview = in_array($user['role'] ?? '', ['owner', 'admin'], true);
            }
            if (!$adminPreview) {
                $page = $pages->findBySlug((string)($site['maintenance_page'] ?? ''));
                if ($page !== null && is_array($page['blocks'] ?? null) && $page['blocks'] !== []) $page['body'] = (new PageBlockRenderer($config->paths(), $posts, $products, $site))->render($page['blocks']);
                $response = $page !== null && ($page['status'] ?? '') !== 'trashed'
                    ? $theme->render('page', ['page'=>$page, 'title'=>(string)$page['title']], 503)
                    : $maintenance->response();
                $response = $response->withHeader('Retry-After', '120')->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
                return SecurityHeaders::apply($response, $request, $config)->withHeader('X-Request-ID', $request->requestId);
            }
        }

        $response = (new Router($theme, $pages, $posts, $config, $products))->dispatch($request);
        if ($adminPreview) $response = $response->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
        if (str_starts_with($request->path, '/admin')) $response = $response->withHeader('Cache-Control', 'private, no-store');
        $response = PublicDelivery::compress($request, $response, $site);
        return SecurityHeaders::apply($response, $request, $config)->withHeader('X-Request-ID', (string)($response->headers()['X-Request-ID'] ?? $request->requestId));
    }
}
