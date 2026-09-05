<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Admin\DashboardController;
use Batoi\Press\Admin\AdminLayout;
use Batoi\Press\Admin\AifController;
use Batoi\Press\Admin\AuditController;
use Batoi\Press\Admin\AuthController;
use Batoi\Press\Admin\CacheController;
use Batoi\Press\Admin\ConnectionController;
use Batoi\Press\Admin\ExportController;
use Batoi\Press\Admin\MediaController;
use Batoi\Press\Admin\ImportController;
use Batoi\Press\Admin\MenuController;
use Batoi\Press\Admin\PageController;
use Batoi\Press\Admin\PostController;
use Batoi\Press\Admin\ProductController;
use Batoi\Press\Admin\SecurityController;
use Batoi\Press\Admin\SettingsController;
use Batoi\Press\Admin\ThemeTemplateController;
use Batoi\Press\Admin\UpdateController;
use Batoi\Press\Admin\UserController;
use Batoi\Press\Admin\WidgetController;
use Batoi\Press\Application\ContactController;
use Batoi\Press\Api\ApiController;
use Batoi\Press\Api\OAuthMetadataController;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\StaticExporter;
use Batoi\Press\Mcp\McpController;
use Batoi\Press\Security\Auth;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\Session;

final class Router
{
    public function __construct(
        private readonly Theme $theme,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly Config $config,
        private readonly ?ProductRepository $productRepository = null
    ) {
    }

    public function dispatch(Request $request): Response
    {
        if ($request->path === '/.well-known/oauth-protected-resource' || $request->path === '/.well-known/oauth-protected-resource/mcp') {
            return (new OAuthMetadataController($this->config))->handle($request);
        }

        if ($request->path === '/api/v2' || str_starts_with($request->path, '/api/v2/')) {
            return (new ApiController($this->config, $this->pages, $this->posts))->handle($request);
        }

        if ($request->path === '/mcp') {
            return (new McpController($this->config, $this->pages, $this->posts))->handle($request);
        }

        if ($request->path === '/sitemap.xml') {
            return Response::xml($this->sitemap());
        }

        if ($request->path === '/feed.xml') {
            return Response::xml($this->feed());
        }

        if ($request->path === '/contact/submit' && $request->method === 'POST') {
            return (new ContactController($this->config))->submit($request);
        }

        if (str_starts_with($request->path, '/media/')) {
            return $this->media(rawurldecode(substr($request->path, 7)));
        }

        if (str_starts_with($request->path, '/assets/')) {
            return $this->asset(rawurldecode(substr($request->path, 8)));
        }

        if (str_starts_with($request->path, '/theme-assets/')) {
            return $this->themeAsset(rawurldecode(substr($request->path, 14)));
        }

        if ($request->path === '/blog') {
            $publishedPosts = $this->posts->allPublished();
            $perPage = max(1, min(48, (int)($this->config->site()['posts_per_page'] ?? 12)));
            $pageNumber = max(1, (int)($request->query['page'] ?? 1));
            $pageCount = max(1, (int)ceil(count($publishedPosts) / $perPage));
            if ($pageNumber > $pageCount) {
                return $this->notFound();
            }
            return $this->theme->render('blog', [
                'posts' => array_slice($publishedPosts, ($pageNumber - 1) * $perPage, $perPage),
                'postUrls' => $this->postUrls($publishedPosts),
                'pageNumber' => $pageNumber,
                'pageCount' => $pageCount,
                'title' => 'Blog',
            ]);
        }

        if ($request->path === '/shop') {
            return $this->theme->render('shop', ['products' => $this->products()->published(), 'title' => 'Shop']);
        }

        if (str_starts_with($request->path, '/product/')) {
            $product = $this->products()->findBySlug(Slug::normalize(rawurldecode(substr($request->path, 9))));
            return $product !== null && ($product['status'] ?? '') === 'published'
                ? $this->theme->render('product', ['product' => $product, 'title' => (string)$product['title']])
                : $this->notFound();
        }

        if (str_starts_with($request->path, '/blog/')) {
            $post = $this->posts->findByPath(substr($request->path, 6));
            if ($post === null || !PublicationState::isPublic($post)) {
                return $this->notFound();
            }
            $adjacent = $this->posts->adjacentPublished((string)($post['slug'] ?? ''));
            return $this->theme->render('post', [
                'post' => $post,
                'title' => (string)$post['title'],
                'widgets' => $this->sidebarWidgets(),
                'recentPosts' => $this->posts->allPublished(),
                'postUrls' => $this->postUrls($this->posts->allPublished()),
                'previousPost' => $adjacent['previous'],
                'nextPost' => $adjacent['next'],
            ]);
        }

        if (str_starts_with($request->path, '/admin')) {
            return $this->admin($request);
        }

        $page = $request->path === '/'
            ? $this->pages->findBySlug(Slug::normalize((string)($this->config->site()['homepage'] ?? 'home')))
            : $this->pages->findByPath($request->path);

        return $page !== null && PublicationState::isPublic($page)
            ? $this->theme->render($this->theme->pageLayout((string)($page['template'] ?? 'page')), $this->pageData($page))
            : $this->notFound();
    }

    private function pageData(array $page): array
    {
        $limit = max(1, min(12, (int)($page['latest_posts_limit'] ?? 3)));
        $integrations = $this->config->integrations();
        if (is_array($page['blocks'] ?? null) && $page['blocks'] !== []) {
            $page['body'] = (new PageBlockRenderer($this->config->paths(), $this->posts, $this->products()))->render($page['blocks']);
        }
        return [
            'page' => $page,
            'title' => (string)($page['title'] ?? ''),
            'postUrls' => $this->postUrls($this->posts->allPublished()),
            'latestPosts' => !empty($page['show_latest_posts'])
                ? array_slice($this->posts->allPublished(), 0, $limit)
                : [],
            'contact' => [
                'status' => (string)($_GET['contact'] ?? ''),
                'recaptcha_site_key' => (string)($integrations['recaptcha_site_key'] ?? ''),
            ],
        ];
    }

    private function admin(Request $request): Response
    {
        $sessionSettings = is_array($this->config->security()['session'] ?? null) ? $this->config->security()['session'] : [];
        $session = new Session(
            (string)($this->config->security()['session_name'] ?? 'batoi_press_session'),
            $this->config->paths()->dataPath('sessions'),
            max(60, (int)($sessionSettings['idle_seconds'] ?? 1800)),
            max(300, (int)($sessionSettings['absolute_seconds'] ?? 43200))
        );
        $csrf = new Csrf($session);
        AdminLayout::setCsrf($csrf);
        AdminLayout::setUser([]);
        $files = new FileStore();
        $audit = new AuditLog($this->config->paths(), $files);
        $auth = new Auth($this->config->paths(), $session, $files);
        $rateLimiter = new RateLimiter($this->config->paths());
        $authController = new AuthController($this->config, $auth, $csrf, $rateLimiter, $audit);

        if ($request->path === '/admin/login') {
            return $authController->login($request);
        }

        if ($request->path === '/admin/login/mfa') {
            return $authController->mfa($request);
        }

        if ($request->path === '/admin/forgot-password') {
            return $authController->forgotPassword();
        }

        if ($request->path === '/admin/logout') {
            return $authController->logout($request);
        }

        $user = $auth->user();
        if ($user === null) {
            return Response::redirect('/admin/login');
        }
        $audit->recordRequest($user, $request);
        AdminLayout::setUser($user);

        if (!AdminAccess::canAccess($user, $request->path, $request->method)) {
            $audit->record((string)($user['username'] ?? 'admin'), 'admin.access_blocked', $request->method . ' ' . $request->path, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'blocked', [
                'role' => AdminAccess::role($user),
            ]);
            return $this->forbidden($request->path);
        }

        if ($request->path === '/admin') {
            return (new DashboardController($this->config, $this->pages, $this->posts, $csrf, $user))->index();
        }

        if ($request->path === '/admin/security') {
            return (new SecurityController($this->config, $csrf, $session, $audit, $user))->index();
        }

        if ($request->path === '/admin/security/mfa/start' && $request->method === 'POST') {
            return (new SecurityController($this->config, $csrf, $session, $audit, $user))->start($request);
        }

        if ($request->path === '/admin/security/mfa/confirm' && $request->method === 'POST') {
            return (new SecurityController($this->config, $csrf, $session, $audit, $user))->confirm($request);
        }

        if ($request->path === '/admin/security/mfa/disable' && $request->method === 'POST') {
            return (new SecurityController($this->config, $csrf, $session, $audit, $user))->disable($request);
        }
        if ($request->path === '/admin/security/sessions/revoke' && $request->method === 'POST') {
            return (new SecurityController($this->config, $csrf, $session, $audit, $user))->revokeSession($request);
        }

        if ($request->path === '/admin/pages') {
            return (new PageController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/pages/new') {
            return (new PageController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->edit();
        }

        if (str_starts_with($request->path, '/admin/pages/edit/')) {
            return (new PageController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 18)));
        }

        if ($request->path === '/admin/pages/save' && $request->method === 'POST') {
            return (new PageController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/posts') {
            return (new PostController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/posts/new') {
            return (new PostController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->edit();
        }

        if (str_starts_with($request->path, '/admin/posts/edit/')) {
            return (new PostController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 18)));
        }

        if ($request->path === '/admin/posts/save' && $request->method === 'POST') {
            return (new PostController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/products') {
            return (new ProductController($this->config, $this->products(), $csrf, $audit, $user))->index();
        }
        if ($request->path === '/admin/products/new') {
            return (new ProductController($this->config, $this->products(), $csrf, $audit, $user))->edit();
        }
        if (str_starts_with($request->path, '/admin/products/edit/')) {
            return (new ProductController($this->config, $this->products(), $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 21)));
        }
        if ($request->path === '/admin/products/save' && $request->method === 'POST') {
            return (new ProductController($this->config, $this->products(), $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/media') {
            return (new MediaController($this->config, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/media/upload' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->upload();
        }

        if ($request->path === '/admin/media/edit' && $request->method === 'GET') {
            return (new MediaController($this->config, $csrf, $audit, $user))->edit($request);
        }

        if ($request->path === '/admin/media/update-text' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->updateText($request);
        }

        if ($request->path === '/admin/media/replace' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->replace($request);
        }

        if ($request->path === '/admin/media/delete' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->delete($request);
        }

        if ($request->path === '/admin/media/libraries/upload' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->installLibrary($request);
        }

        if ($request->path === '/admin/media/libraries/toggle' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->toggleLibrary($request);
        }

        if ($request->path === '/admin/media/libraries/delete' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->deleteLibrary($request);
        }

        if ($request->path === '/admin/menus') {
            return (new MenuController($this->config, $files, $csrf, $audit, $user))->edit();
        }

        if ($request->path === '/admin/menus/save' && $request->method === 'POST') {
            return (new MenuController($this->config, $files, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/widgets') {
            return (new WidgetController($this->config, $files, $csrf, $audit, $user))->edit();
        }

        if ($request->path === '/admin/widgets/save' && $request->method === 'POST') {
            return (new WidgetController($this->config, $files, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/settings') {
            return (new SettingsController($this->config, $files, $csrf, $audit, $user))->edit();
        }

        if ($request->path === '/admin/settings/save' && $request->method === 'POST') {
            return (new SettingsController($this->config, $files, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/import') {
            $body = AdminLayout::pageHeader('XML Import', 'Import Batoi Press site content without replacing existing slugs.', AdminLayout::buttonLink('Back to Settings', '/admin/settings', 'back', true));
            $body .= AdminLayout::section('Upload site content', '<form method="post" action="/admin/import/xml" enctype="multipart/form-data" class="bp-form">' . $csrf->field() . '<label>XML file <input type="file" name="site_xml" accept=".xml,application/xml,text/xml" required><span class="bp-field-help">Maximum 10 MiB. Root element: &lt;batoi-press&gt;.</span></label>' . AdminLayout::submitButton('Import XML', 'upload') . '</form>', 'Pages and posts with existing slugs are skipped.');
            return Response::html(AdminLayout::render('XML Import', $body));
        }
        if ($request->path === '/admin/import/xml' && $request->method === 'POST') {
            return (new ImportController($this->config, $this->pages, $this->posts, $csrf, $audit, $user))->xml();
        }

        if ($request->path === '/admin/themes') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->themes();
        }

        if ($request->path === '/admin/themes/activate' && $request->method === 'POST') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->activate($request);
        }

        if ($request->path === '/admin/themes/upload' && $request->method === 'POST') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->upload();
        }

        if ($request->path === '/admin/themes/duplicate' && $request->method === 'POST') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->duplicate($request);
        }

        if (str_starts_with($request->path, '/admin/themes/preview/')) {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->preview(rawurldecode(substr($request->path, 22)), $request->input('layout'));
        }

        if ($request->path === '/admin/theme-templates') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->index($request->input('theme'));
        }

        if (str_starts_with($request->path, '/admin/theme-templates/edit/')) {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 28)));
        }

        if ($request->path === '/admin/theme-templates/save' && $request->method === 'POST') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/theme-templates/restore' && $request->method === 'POST') {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->restore($request);
        }

        if ($request->path === '/admin/users') {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/connections') {
            return (new ConnectionController($this->config, new AccessTokenRepository($this->config->paths(), $files), $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/connections/issue' && $request->method === 'POST') {
            return (new ConnectionController($this->config, new AccessTokenRepository($this->config->paths(), $files), $csrf, $audit, $user))->issue($request);
        }

        if ($request->path === '/admin/connections/revoke' && $request->method === 'POST') {
            return (new ConnectionController($this->config, new AccessTokenRepository($this->config->paths(), $files), $csrf, $audit, $user))->revoke($request);
        }

        if ($request->path === '/admin/users/new') {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->create();
        }

        if (str_starts_with($request->path, '/admin/users/edit/')) {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 18)));
        }

        if (str_starts_with($request->path, '/admin/users/reset/')) {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->reset(rawurldecode(substr($request->path, 19)));
        }

        if ($request->path === '/admin/users/save' && $request->method === 'POST') {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/users/update' && $request->method === 'POST') {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->update($request);
        }

        if ($request->path === '/admin/users/reset-password' && $request->method === 'POST') {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->resetPassword($request);
        }

        if ($request->path === '/admin/users/toggle' && $request->method === 'POST') {
            return (new UserController($this->config, $files, $csrf, $audit, $user))->toggle($request);
        }

        if ($request->path === '/admin/audit/export') {
            return (new AuditController($this->config, $csrf, $user))->export($request);
        }

        if ($request->path === '/admin/audit/cleanup' && $request->method === 'POST') {
            return (new AuditController($this->config, $csrf, $user))->cleanup($request);
        }

        if ($request->path === '/admin/audit') {
            return (new AuditController($this->config, $csrf, $user))->index($request);
        }

        if ($request->path === '/admin/cache') {
            return (new CacheController(new Cache($this->config->paths()), $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/cache/clear' && $request->method === 'POST') {
            return (new CacheController(new Cache($this->config->paths()), $csrf, $audit, $user))->clear($request->input('csrf_token'));
        }

        if ($request->path === '/admin/export-static') {
            return (new ExportController(new StaticExporter($this->config->paths(), $this->pages, $this->posts, $this->config->site(), $this->products()), $csrf, $audit, $user))->index();
        }

        if (str_starts_with($request->path, '/admin/export-static/download/')) {
            $name = rawurldecode(substr($request->path, strlen('/admin/export-static/download/')));
            return (new ExportController(new StaticExporter($this->config->paths(), $this->pages, $this->posts, $this->config->site(), $this->products()), $csrf, $audit, $user))->download($name);
        }

        if ($request->path === '/admin/export-static/run' && $request->method === 'POST') {
            return (new ExportController(new StaticExporter($this->config->paths(), $this->pages, $this->posts, $this->config->site(), $this->products()), $csrf, $audit, $user))->run($request->input('csrf_token'));
        }

        if ($request->path === '/admin/aif') {
            return (new AifController($this->config, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/aif/assist' && $request->method === 'POST') {
            return (new AifController($this->config, $csrf, $audit, $user))->assist($request);
        }

        if ($request->path === '/admin/updates') {
            return (new UpdateController($this->config, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/updates/check' && $request->method === 'POST') {
            return (new UpdateController($this->config, $csrf, $audit, $user))->check($request->input('csrf_token'));
        }

        if ($request->path === '/admin/updates/backup' && $request->method === 'POST') {
            return (new UpdateController($this->config, $csrf, $audit, $user))->backup($request->input('csrf_token'));
        }

        if ($request->path === '/admin/updates/stage' && $request->method === 'POST') {
            return (new UpdateController($this->config, $csrf, $audit, $user))->stage($request->input('csrf_token'), $_FILES, $request->input('sha256'));
        }

        if ($request->path === '/admin/updates/apply' && $request->method === 'POST') {
            return (new UpdateController($this->config, $csrf, $audit, $user))->apply($request->input('csrf_token'), $request->input('stage'));
        }

        if ($request->path === '/admin/updates/rollback' && $request->method === 'POST') {
            return (new UpdateController($this->config, $csrf, $audit, $user))->rollback($request->input('csrf_token'), $request->input('backup'));
        }

        return $this->notFound();
    }

    private function forbidden(string $path): Response
    {
        $body = AdminLayout::pageHeader(
            'Access Restricted',
            'Your role does not include access to this admin area.',
            AdminLayout::buttonLink('Back to Dashboard', '/admin', 'back', true)
        );
        $body .= '<section class="bp-empty-state"><h2>Permission required</h2><p>Access to <code>' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code> is limited by the current account role.</p></section>';
        return Response::html(AdminLayout::render('Access Restricted', $body), 403);
    }

    private function sidebarWidgets(): array
    {
        $path = $this->config->paths()->contentPath('widgets/sidebar.json');
        $widgets = is_file($path) ? ((new FileStore())->readJson($path)['widgets'] ?? []) : [];
        $widgets = is_array($widgets) ? array_values($widgets) : [];
        foreach ($widgets as $widget) {
            if (($widget['type'] ?? '') === 'recent_posts') {
                return $widgets;
            }
        }
        array_unshift($widgets, ['type' => 'recent_posts', 'title' => 'Recent posts']);
        return $widgets;
    }

    private function postUrls(array $posts): array
    {
        $urls = [];
        foreach ($posts as $post) {
            $slug = (string)($post['slug'] ?? '');
            if ($slug !== '') {
                $urls[$slug] = $this->posts->publicPath($post);
            }
        }
        return $urls;
    }

    private function products(): ProductRepository
    {
        return $this->productRepository ?? new ProductRepository($this->config->paths(), new FileStore(), new HtmlContent());
    }

    private function notFound(): Response
    {
        return $this->theme->render('404', ['title' => 'Page Not Found'], 404);
    }

    private function media(string $name): Response
    {
        $name = basename($name);
        if ($name === '' || str_contains($name, '..')) {
            return $this->notFound();
        }

        $file = $this->config->paths()->contentPath('media/' . $name);
        if (!is_file($file)) {
            return $this->notFound();
        }

        return $this->assetResponse($file, false);
    }

    private function asset(string $relative): Response
    {
        $file = (new AssetManager($this->config->paths()))->resolveAsset($relative);
        if ($file === null) {
            return $this->notFound();
        }

        return $this->assetResponse($file, false);
    }

    private function themeAsset(string $target): Response
    {
        $parts = explode('/', ltrim(str_replace('\\', '/', $target), '/'), 2);
        if (count($parts) !== 2) {
            return $this->notFound();
        }
        $manager = new ThemeManager($this->config->paths());
        $file = $manager->resolveAsset($parts[0], $parts[1]);
        return $file !== null ? $this->assetResponse($file, true) : $this->notFound();
    }

    private function assetResponse(string $file, bool $immutable): Response
    {
        $body = file_get_contents($file);
        if ($body === false) {
            return $this->notFound();
        }

        return new Response($body, 200, [
            'Content-Type' => AssetManager::mimeType($file),
            'Content-Length' => (string)strlen($body),
            'Cache-Control' => $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sitemap(): string
    {
        $baseUrl = rtrim((string)($this->config->site()['base_url'] ?? ''), '/');
        $urls = [];
        foreach ($this->pages->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $urls[] = $baseUrl . ($slug === Slug::normalize((string)($site['homepage'] ?? 'home')) ? '/' : $this->pages->publicPath($page));
        }
        $urls[] = $baseUrl . '/blog';
        foreach ($this->posts->allPublished() as $post) {
            $urls[] = $baseUrl . $this->posts->publicPath($post);
        }
        $publishedProducts = $this->products()->published();
        if ($publishedProducts !== []) {
            $urls[] = $baseUrl . '/shop';
        }
        foreach ($publishedProducts as $product) {
            $urls[] = $baseUrl . '/product/' . rawurlencode((string)($product['slug'] ?? ''));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc></url>' . "\n";
        }
        return $xml . '</urlset>';
    }

    private function feed(): string
    {
        $site = $this->config->site();
        $baseUrl = rtrim((string)($site['base_url'] ?? ''), '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0"><channel>';
        $xml .= '<title>' . htmlspecialchars((string)($site['name'] ?? 'Batoi Press'), ENT_XML1) . '</title>';
        $xml .= '<link>' . htmlspecialchars($baseUrl . '/blog', ENT_XML1) . '</link>';
        foreach ($this->posts->allPublished() as $post) {
            $xml .= '<item>';
            $xml .= '<title>' . htmlspecialchars((string)($post['title'] ?? ''), ENT_XML1) . '</title>';
            $xml .= '<link>' . htmlspecialchars($baseUrl . $this->posts->publicPath($post), ENT_XML1) . '</link>';
            $xml .= '<pubDate>' . date(DATE_RSS, strtotime((string)($post['published_at'] ?? 'now'))) . '</pubDate>';
            $xml .= '</item>';
        }
        return $xml . '</channel></rss>';
    }
}
