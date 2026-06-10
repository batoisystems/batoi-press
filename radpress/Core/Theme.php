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

        $baseFile = $this->paths->themePath($theme . '/layouts/base.php');
        ob_start();
        require $baseFile;
        return Response::html((string)ob_get_clean(), $status);
    }
}
