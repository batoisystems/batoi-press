<?php
declare(strict_types=1);
namespace Batoi\Press\Core;

/** Optional GD derivatives; originals are never replaced. */
final class ImageVariants
{
    public function __construct(private readonly Paths $paths) {}

    public function generate(string $url, bool $webpOnly = false): array
    {
        $image = (new MediaPerformance($this->paths))->image($url);
        if ($image === null || !function_exists('imagecreatefromstring')) return [];
        // Bound decoder allocations, including the source and resized surfaces.
        $originalBytes = filesize($image['path']);
        if ($originalBytes === false || $image['width'] * $image['height'] > 12000000 || $originalBytes > 16777216) return [];
        $memory = ini_get('memory_limit');
        $limit = $memory === '-1' ? PHP_INT_MAX : (int)$memory * (str_ends_with(strtolower($memory), 'g') ? 1073741824 : (str_ends_with(strtolower($memory), 'm') ? 1048576 : (str_ends_with(strtolower($memory), 'k') ? 1024 : 1)));
        if (memory_get_usage(true) + $image['width'] * $image['height'] * 12 + 16777216 > $limit) return [];
        $encoded = (string)file_get_contents($image['path']);
        // Do not replace animated PNG/WebP playback with a still derivative.
        if (str_contains($encoded, 'acTL') || str_contains($encoded, 'ANIM')) return [];
        $source = @imagecreatefromstring($encoded);
        unset($encoded);
        if ($source === false) return [];
        $hash = hash_file('sha256', $image['path']);
        $variants = [];
        try {
            $encoders = $webpOnly ? ['webp' => 'imagewebp'] : ['webp' => 'imagewebp', 'avif' => 'imageavif'];
            foreach ($encoders as $format => $encoder) {
                if (!function_exists($encoder)) continue;
                foreach (array_unique([min(480, $image['width']), min(960, $image['width']), min(1600, $image['width'])]) as $width) {
                    $height = max(1, (int)round($image['height'] * $width / $image['width']));
                    $target = imagecreatetruecolor($width, $height);
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $image['width'], $image['height']);
                    $relative = 'images/derived/' . $hash . '-' . $width . '.' . $format;
                    $path = (new AssetManager($this->paths))->prepareTarget($relative);
                    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
                    try {
                        if (@$encoder($target, $temporary, 80) && @getimagesize($temporary) !== false
                            && filesize($temporary) < $originalBytes && rename($temporary, $path)) {
                            $variants[] = ['url' => '/assets/' . $relative, 'width' => $width, 'format' => $format];
                        }
                    } catch (\Throwable $exception) { /* Unsupported encoder: retain the original. */ }
                    finally { imagedestroy($target); if (is_file($temporary)) unlink($temporary); }
                }
            }
        } finally { imagedestroy($source); }
        (new FileStore())->writeJson($this->manifest($image['url']), ['sha256' => $hash, 'variants' => $variants]);
        return $variants;
    }

    public function scheduleWebp(array $image): bool
    {
        $url = $image['url'];
        if (!function_exists('imagewebp') || !preg_match('/\.(?:jpe?g|png)$/iD', $url)
            || $image['width'] < 480 || $image['width'] * $image['height'] > 4000000
            || filesize($image['path']) > 4194304 || !is_writable($this->paths->dataPath('cache'))) return false;
        $hash = hash_file('sha256', $image['path']);
        if ($hash === false || $this->attempted($url, $hash)) return false;

        register_shutdown_function(function () use ($url, $hash): void {
            $lockPath = $this->paths->dataPath('locks/image-variants.lock');
            $directory = dirname($lockPath);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
            if (is_link($lockPath) || is_link($directory)) return;
            $lock = @fopen($lockPath, 'c');
            if ($lock === false) return;
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB) || $this->attempted($url, $hash)) return;
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                $generated = $this->generate($url, true);
                if ($generated === []) {
                    (new FileStore())->writeJson($this->manifest($url), ['sha256' => $hash, 'variants' => []]);
                }
            } catch (\Throwable $error) {
                // Original images continue to serve when optional storage or encoding is unavailable.
            } finally {
                fclose($lock);
            }
        });
        return true;
    }

    public function srcset(array $image, bool $localized = true): string
    {
        $file = $this->manifest($image['url']);
        $hash = hash_file('sha256', $image['path']);
        if (is_file($file)) {
            $data = (new FileStore())->readJson($file);
            if (($data['sha256'] ?? '') !== $hash) return '';
        } else {
            // Cache maintenance must not remove responsive behavior for existing copies.
            $data = ['variants' => []];
            foreach (array_unique([min(480, $image['width']), min(960, $image['width']), min(1600, $image['width'])]) as $width) {
                $data['variants'][] = ['url' => '/assets/images/derived/' . $hash . '-' . $width . '.webp', 'width' => $width, 'format' => 'webp'];
            }
        }
        $entries = [];
        // WebP is the responsive default; AVIF remains available explicitly in Media.
        foreach (($data['variants'] ?? []) as $variant) {
            if (($variant['format'] ?? '') !== 'webp') continue;
            $url = (string)($variant['url'] ?? '');
            if ((new MediaPerformance($this->paths))->image($url) !== null) $entries[] = ($localized ? \bp_url($url) : $url) . ' ' . (int)$variant['width'] . 'w';
        }
        return implode(', ', $entries);
    }

    private function attempted(string $url, string $hash): bool
    {
        $path = $this->manifest($url);
        if (!is_file($path)) return false;
        $data = json_decode((string)@file_get_contents($path), true);
        return ($data['sha256'] ?? '') === $hash;
    }

    private function manifest(string $url): string { return $this->paths->dataPath('cache/media-variants-' . hash('sha256', $url) . '.json'); }
}
