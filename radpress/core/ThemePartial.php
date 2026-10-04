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
