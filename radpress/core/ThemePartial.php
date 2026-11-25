<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

/** Optional presentation files shared by installed themes and bundled fallbacks. */
final class ThemePartial
{
    public const FILES = [
        'email' => 'partials/email.php',
        'block-posts' => 'partials/blocks/posts.php',
        'block-gallery' => 'partials/blocks/gallery.php',
        'block-products' => 'partials/blocks/products.php',
        'block-widget' => 'partials/blocks/widget.php',
    ];

    public static function bundledPath(string $key): string
    {
        if (!isset(self::FILES[$key])) throw new \RuntimeException('Unknown theme partial.');
        return dirname(__DIR__) . '/theme/default/' . self::FILES[$key];
    }

    public static function render(Paths $paths, array $site, string $key, array $data): string
    {
        $fallback = self::bundledPath($key);
        $slug = (new ThemeManager($paths))->activeSlug($site);
        $file = $paths->themePath($slug . '/' . self::FILES[$key]);
        if (!is_file($file)) $file = $fallback;

        return self::renderFile($file, $site, $data);
    }

    public static function renderCollection(Paths $paths, array $site, string $layout, array $data): string
    {
        $manager = new ThemeManager($paths);
        $slug = $manager->activeSlug($site);
        if (!isset($manager->collectionLayouts($slug)[$layout])) $layout = 'cards';
        $file = $paths->themePath($slug . '/partials/collections/' . $layout . '.php');
        if (!is_file($file) || is_link($file) || is_link(dirname($file))) {
            $file = self::bundledCollectionPath();
            if (!isset(CollectionView::LAYOUTS[$layout])) $layout = 'cards';
        }
        return self::renderFile($file, $site, ['layout' => $layout] + $data);
    }

    public static function bundledCollectionPath(): string
    {
        return dirname(__DIR__) . '/theme/default/partials/collection.php';
    }

    private static function renderFile(string $file, array $site, array $data): string
    {
        // Use an isolated scope: templates receive presentation data, not services.
        return (static function (string $file, array $site, array $data): string {
            $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            extract($data, EXTR_SKIP);
            $level = ob_get_level();
            ob_start();
            try {
                require $file;
                return (string)ob_get_clean();
            } finally {
                while (ob_get_level() > $level) ob_end_clean();
            }
        })($file, $site, $data);
    }
}
