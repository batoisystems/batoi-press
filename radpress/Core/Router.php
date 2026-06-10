<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Admin\DashboardController;
use Batoi\Press\Admin\UpdateController;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;

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

        if ($request->path === '/blog') {
            return $this->theme->render('blog', ['posts' => $this->posts->allPublished(), 'title' => 'Blog']);
        }

        if (str_starts_with($request->path, '/blog/')) {
            $post = $this->posts->findBySlug(substr($request->path, 6));
            return $post ? $this->theme->render('post', ['post' => $post, 'title' => (string)$post['title']]) : $this->notFound();
        }

        if ($request->path === '/admin') {
            return (new DashboardController($this->config, $this->pages, $this->posts))->index();
        }

        if ($request->path === '/admin/updates') {
            return (new UpdateController($this->config))->index();
        }

        $slug = $request->path === '/' ? 'home' : trim($request->path, '/');
        $page = $this->pages->findBySlug($slug);

        return $page ? $this->theme->render('page', ['page' => $page, 'title' => (string)$page['title']]) : $this->notFound();
    }

    private function notFound(): Response
    {
        return $this->theme->render('404', ['title' => 'Page Not Found'], 404);
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

