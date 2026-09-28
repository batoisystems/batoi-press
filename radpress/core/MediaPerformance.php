<?php
declare(strict_types=1);
namespace Batoi\Press\Core;

final class MediaPerformance
{
    public const FIELDS = ['hero_image', 'hero_preload', 'hero_priority', 'lazy_images', 'image_sizes'];
    public function __construct(private readonly Paths $paths) {}

    public function normalize(array $input, array $existing = []): array
    {
        $url = trim((string)($input['hero_image'] ?? $existing['hero_image'] ?? ''));
        if ($url !== '') {
            $image = $this->image($url);
            if ($image === null) throw new \RuntimeException('Hero image must be an existing local JPEG, PNG, WebP or AVIF asset URL.');
            $url = $image['url'];
        }
        return ['hero_image' => $url,
            'hero_preload' => filter_var($input['hero_preload'] ?? $existing['hero_preload'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hero_priority' => ($input['hero_priority'] ?? $existing['hero_priority'] ?? 'auto') === 'high' ? 'high' : 'auto',
            'lazy_images' => filter_var($input['lazy_images'] ?? $existing['lazy_images'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'image_sizes' => substr(trim((string)($input['image_sizes'] ?? $existing['image_sizes'] ?? '100vw')), 0, 500)];
    }

    public function image(string $url): ?array
    {
        $base = function_exists('bp_url') ? rtrim(\bp_url('/'), '/') . '/' : '/';
        if ($base !== '/' && str_starts_with($url, $base)) $url = '/' . substr($url, strlen($base));
        if (!preg_match('#^/(assets|media)/([^?\#]+)\.(jpe?g|png|webp|avif)$#iD', $url, $match)) return null;
        $asset = (new AssetManager($this->paths))->find($match[1], rawurldecode(substr($url, strlen($match[1]) + 2)));
        if ($asset === null) return null;
        $size = @getimagesize($asset['path']);
        if ($size === false) return null;
        return ['url' => $url, 'path' => $asset['path'], 'width' => $size[0], 'height' => $size[1]];
    }

    public function head(array $page, bool $localized = true): string
    {
        if (empty($page['hero_preload']) || ($image = $this->image((string)($page['hero_image'] ?? ''))) === null) return '';
        return '<link rel="preload" as="image" href="' . self::e($localized ? \bp_url($image['url']) : $image['url']) . '" fetchpriority="' . (($page['hero_priority'] ?? '') === 'high' ? 'high' : 'auto') . '"' . $this->responsive($image, $page, $localized) . '>';
    }

    public function markup(string $html, array $page, bool $localized = true): string
    {
        if (!class_exists(\DOMDocument::class)) return $html;
        $index = 0;
        return preg_replace_callback('/<img\b[^>]*>/i', function ($match) use ($page, &$index, $localized) {
            $index++;
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $match[0], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $img = $dom->getElementsByTagName('img')->item(0);
            if (!$img) return $match[0];
            $image = $this->image($img->getAttribute('src'));
            if ($image === null) return $match[0];
            $hero = $image['url'] === ($page['hero_image'] ?? '');
            if (!$img->hasAttribute('width') && !$img->hasAttribute('height')) {
                $img->setAttribute('width', (string)$image['width']);
                $img->setAttribute('height', (string)$image['height']);
            }
            if ($hero) {
                $img->setAttribute('loading', 'eager');
                $img->setAttribute('fetchpriority', ($page['hero_priority'] ?? '') === 'high' ? 'high' : 'auto');
            } elseif (!empty($page['lazy_images']) && $index > 1 && !$img->hasAttribute('loading')) $img->setAttribute('loading', 'lazy');
            $set = (new ImageVariants($this->paths))->srcset($image, $localized);
            if ($set !== '' && !$img->hasAttribute('srcset')) {
                $img->setAttribute('srcset', $set);
                $img->setAttribute('sizes', (string)($page['image_sizes'] ?? '100vw'));
            }
            return $dom->saveHTML($img);
        }, $html) ?? $html;
    }

    private function responsive(array $image, array $page, bool $localized): string
    {
        $set = (new ImageVariants($this->paths))->srcset($image, $localized);
        return $set === '' ? '' : ' imagesrcset="' . self::e($set) . '" imagesizes="' . self::e((string)($page['image_sizes'] ?? '100vw')) . '"';
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
