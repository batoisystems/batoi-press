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

    public function render(string $layout, array $data = [], int $status = 200, array $options = []): Response
    {
        $manager = new ThemeManager($this->paths);
        $slug = $manager->activeSlug($this->site);
        $layoutFile = $this->paths->themePath($slug . '/layouts/' . $layout . '.php');
        if (!is_file($layoutFile)) {
            $layoutFile = $this->paths->themePath($slug . '/layouts/404.php');
            $status = 404;
        }

        $site = $this->site;
        extract($data, EXTR_SKIP);
        $theme = $manager->context($slug);
        $branding = (new BrandAssetManager($this->paths))->branding($site);
        $localizedAssets = (bool)($options['localized_assets'] ?? true);
        $previousResolver = $GLOBALS['bp_theme_asset_resolver'] ?? null;
        $GLOBALS['bp_theme_asset_resolver'] = static fn(string $path): string => $manager->assetUrl($slug, $path, $localizedAssets);

        try {
            ob_start();
            require $layoutFile;
            $content = (string)ob_get_clean();
            if ($localizedAssets && function_exists('bp_localize_markup_urls')) {
                $content = \bp_localize_markup_urls($content);
            }

            $baseFile = $this->paths->themePath($slug . '/layouts/base.php');
            ob_start();
            require $baseFile;
            $html = (string)ob_get_clean();
        } finally {
            if ($previousResolver === null) {
                unset($GLOBALS['bp_theme_asset_resolver']);
            } else {
                $GLOBALS['bp_theme_asset_resolver'] = $previousResolver;
            }
        }

        $themeHead = $manager->tags($slug, 'head', $localizedAssets);
        $themeBody = $manager->tags($slug, 'body', $localizedAssets);
        $libraries = new AssetLibraryManager($this->paths);
        $head = $themeHead . $libraries->tags('head', $localizedAssets);
        $body = $themeBody . $libraries->tags('body', $localizedAssets);
        $integrationsPath = $this->paths->configPath('integrations.json');
        $integrations = is_file($integrationsPath) ? (new FileStore())->readJson($integrationsPath) : [];
        $measurementId = strtoupper((string)($integrations['analytics_measurement_id'] ?? ''));
        $inlineScripts = [];
        if (preg_match('/^G-[A-Z0-9]{4,20}$/', $measurementId) === 1) {
            $encodedId = rawurlencode($measurementId);
            $jsonId = json_encode($measurementId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $analyticsScript = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config",' . $jsonId . ');';
            $inlineScripts[] = $analyticsScript;
            $head .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $encodedId . '"></script><script>' . $analyticsScript . '</script>';
        }
        $page = is_array($data['page'] ?? null) ? $data['page'] : [];
        $pageCss = (string)($page['custom_css'] ?? '');
        $pageJs = (string)($page['custom_js'] ?? '');
        if ($pageCss !== '' && preg_match('#</style(?=[\s/>])#i', $pageCss) !== 1) {
            $head .= '<style data-bp-page-css>' . $pageCss . '</style>';
        }
        if ($pageJs !== '' && preg_match('#</script(?=[\s/>])#i', $pageJs) !== 1) {
            $body .= '<script data-bp-page-js>' . $pageJs . '</script>';
            $inlineScripts[] = $pageJs;
        }
        if ($head !== '') {
            $html = str_contains($html, '</head>') ? str_replace('</head>', $head . '</head>', $html) : $head . $html;
        }
        if ($body !== '') {
            $html = str_contains($html, '</body>') ? str_replace('</body>', $body . '</body>', $html) : $html . $body;
        }
        $previewBanner = (string)($options['preview_banner'] ?? '');
        if ($previewBanner !== '') {
            $html = preg_replace('/<body\b([^>]*)>/i', '<body$1>' . $previewBanner, $html, 1) ?? $html;
        }
        $response = Response::html($html, $status);
        foreach ($inlineScripts as $script) {
            $response = $response->withInlineScript($script);
        }
        return $response;
    }

    public function pageLayout(string $template): string
    {
        $manager = new ThemeManager($this->paths);
        $slug = $manager->activeSlug($this->site);
        return $manager->resolvePageLayout($slug, $template);
    }
}
