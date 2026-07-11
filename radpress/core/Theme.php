<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Theme
{
    public function __construct(
        private readonly Paths $paths,
        private readonly array $site
    ) {
    }

    public function render(string $layout, array $data = [], int $status = 200): Response
    {
        $theme = (string)($this->site['theme'] ?? 'default');
        $layoutFile = $this->paths->themePath($theme . '/layouts/' . $layout . '.php');
        if (!is_file($layoutFile)) {
            $layoutFile = $this->paths->themePath($theme . '/layouts/404.php');
            $status = 404;
        }

        $site = $this->site;
        extract($data, EXTR_SKIP);

        ob_start();
        require $layoutFile;
        $content = (string)ob_get_clean();
        if (function_exists('bp_localize_markup_urls')) {
            $content = \bp_localize_markup_urls($content);
        }

        $baseFile = $this->paths->themePath($theme . '/layouts/base.php');
        ob_start();
        require $baseFile;
        $html = (string)ob_get_clean();
        $libraries = new AssetLibraryManager($this->paths);
        $head = $libraries->tags('head');
        $body = $libraries->tags('body');
        if ($head !== '') {
            $html = str_contains($html, '</head>') ? str_replace('</head>', $head . '</head>', $html) : $head . $html;
        }
        if ($body !== '') {
            $html = str_contains($html, '</body>') ? str_replace('</body>', $body . '</body>', $html) : $html . $body;
        }
        return Response::html($html, $status);
    }
}
