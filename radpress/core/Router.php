<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Admin\DashboardController;
use Batoi\Press\Admin\AdminLayout;
use Batoi\Press\Admin\AifController;
use Batoi\Press\Admin\AuditController;
use Batoi\Press\Admin\AuthController;
use Batoi\Press\Admin\CacheController;
use Batoi\Press\Admin\ExportController;
use Batoi\Press\Admin\MediaController;
use Batoi\Press\Admin\MenuController;
use Batoi\Press\Admin\PageController;
use Batoi\Press\Admin\PostController;
use Batoi\Press\Admin\SettingsController;
use Batoi\Press\Admin\ThemeTemplateController;
use Batoi\Press\Admin\UpdateController;
use Batoi\Press\Admin\UserController;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\StaticExporter;
use Batoi\Press\Security\Auth;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\Session;

final class Router
{
    public function __construct(
        private readonly Theme $theme,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly Config $config
    ) {
    }

    public function dispatch(Request $request): Response
    {
        if ($request->path === '/sitemap.xml') {
            return Response::xml($this->sitemap());
        }

        if ($request->path === '/feed.xml') {
            return Response::xml($this->feed());
        }

        if (str_starts_with($request->path, '/media/')) {
            return $this->media(rawurldecode(substr($request->path, 7)));
        }

        if ($request->path === '/blog') {
            return $this->theme->render('blog', ['posts' => $this->posts->allPublished(), 'title' => 'Blog']);
        }

        if (str_starts_with($request->path, '/blog/')) {
            $post = $this->posts->findBySlug(substr($request->path, 6));
            return $post ? $this->theme->render('post', ['post' => $post, 'title' => (string)$post['title']]) : $this->notFound();
        }

        if (str_starts_with($request->path, '/admin')) {
            return $this->admin($request);
        }

        $slug = $request->path === '/' ? 'home' : trim($request->path, '/');
        $page = $this->pages->findBySlug($slug);

        return $page ? $this->theme->render('page', ['page' => $page, 'title' => (string)$page['title']]) : $this->notFound();
    }

    private function admin(Request $request): Response
    {
        $session = new Session(
            (string)($this->config->security()['session_name'] ?? 'batoi_press_session'),
            $this->config->paths()->dataPath('sessions')
        );
        $csrf = new Csrf($session);
        AdminLayout::setCsrf($csrf);
        $files = new FileStore();
        $audit = new AuditLog($this->config->paths(), $files);
        $auth = new Auth($this->config->paths(), $session, $files);
        $rateLimiter = new RateLimiter($this->config->paths());
        $authController = new AuthController($this->config, $auth, $csrf, $rateLimiter, $audit);

        if ($request->path === '/admin/login') {
            return $authController->login($request);
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

        if ($request->path === '/admin/pages') {
            return (new PageController($this->config, $this->pages, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/pages/new') {
            return (new PageController($this->config, $this->pages, $csrf, $audit, $user))->edit();
        }

        if (str_starts_with($request->path, '/admin/pages/edit/')) {
            return (new PageController($this->config, $this->pages, $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 18)));
        }

        if ($request->path === '/admin/pages/save' && $request->method === 'POST') {
            return (new PageController($this->config, $this->pages, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/posts') {
            return (new PostController($this->config, $this->posts, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/posts/new') {
            return (new PostController($this->config, $this->posts, $csrf, $audit, $user))->edit();
        }

        if (str_starts_with($request->path, '/admin/posts/edit/')) {
            return (new PostController($this->config, $this->posts, $csrf, $audit, $user))->edit(rawurldecode(substr($request->path, 18)));
        }

        if ($request->path === '/admin/posts/save' && $request->method === 'POST') {
            return (new PostController($this->config, $this->posts, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/media') {
            return (new MediaController($this->config, $csrf, $audit, $user))->index();
        }

        if ($request->path === '/admin/media/upload' && $request->method === 'POST') {
            return (new MediaController($this->config, $csrf, $audit, $user))->upload();
        }

        if ($request->path === '/admin/menus') {
            return (new MenuController($this->config, $files, $csrf, $audit, $user))->edit();
        }

        if ($request->path === '/admin/menus/save' && $request->method === 'POST') {
            return (new MenuController($this->config, $files, $csrf, $audit, $user))->save($request);
        }

        if ($request->path === '/admin/settings') {
            return (new SettingsController($this->config, $files, $csrf, $audit, $user))->edit();
        }

        if ($request->path === '/admin/settings/save' && $request->method === 'POST') {
            return (new SettingsController($this->config, $files, $csrf, $audit, $user))->save($request);
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

        if (str_starts_with($request->path, '/admin/themes/preview/')) {
            return (new ThemeTemplateController($this->config, $files, $csrf, $audit, $user))->preview(rawurldecode(substr($request->path, 22)));
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
            return (new ExportController(new StaticExporter($this->config->paths(), $this->pages, $this->posts, $this->config->site()), $csrf, $audit, $user))->index();
        }

        if (str_starts_with($request->path, '/admin/export-static/download/')) {
            $name = rawurldecode(substr($request->path, strlen('/admin/export-static/download/')));
            return (new ExportController(new StaticExporter($this->config->paths(), $this->pages, $this->posts, $this->config->site()), $csrf, $audit, $user))->download($name);
        }

        if ($request->path === '/admin/export-static/run' && $request->method === 'POST') {
            return (new ExportController(new StaticExporter($this->config->paths(), $this->pages, $this->posts, $this->config->site()), $csrf, $audit, $user))->run($request->input('csrf_token'));
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

        return Response::body((string)file_get_contents($file), $this->mediaType($file));
    }

    private function mediaType(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt', 'md' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }

    private function sitemap(): string
    {
        $baseUrl = rtrim((string)($this->config->site()['base_url'] ?? ''), '/');
        $urls = [];
        foreach ($this->pages->allPublished() as $page) {
            $slug = (string)($page['slug'] ?? '');
            $urls[] = $baseUrl . ($slug === 'home' ? '/' : '/' . $slug);
        }
        $urls[] = $baseUrl . '/blog';
        foreach ($this->posts->allPublished() as $post) {
            $urls[] = $baseUrl . '/blog/' . (string)($post['slug'] ?? '');
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
            $xml .= '<link>' . htmlspecialchars($baseUrl . '/blog/' . (string)($post['slug'] ?? ''), ENT_XML1) . '</link>';
            $xml .= '<pubDate>' . date(DATE_RSS, strtotime((string)($post['published_at'] ?? 'now'))) . '</pubDate>';
            $xml .= '</item>';
        }
        return $xml . '</channel></rss>';
    }
}
