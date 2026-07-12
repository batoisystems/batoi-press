<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;

final class BrandAssetManager
{
    private const LOGO_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'svg', 'webp'];
    private const FAVICON_EXTENSIONS = ['ico', 'jpg', 'jpeg', 'png', 'svg', 'webp'];

    public function __construct(private readonly Paths $paths)
    {
    }

    public function branding(array $site): array
    {
        $name = trim((string)($site['name'] ?? 'Batoi Press')) ?: 'Batoi Press';
        $display = in_array((string)($site['brand_display'] ?? 'text'), ['text', 'logo', 'logo_with_text'], true)
            ? (string)($site['brand_display'] ?? 'text')
            : 'text';
        $logo = $this->resolveUrl((string)($site['brand_logo'] ?? ''));
        if ($logo === null) {
            $display = 'text';
        }

        $favicon = $this->resolveUrl((string)($site['favicon'] ?? ''));
        if ($favicon === null) {
            $fallback = '/assets/img/batoi-press/press-color-tile-32.png';
            $favicon = is_file($this->paths->publicPath(ltrim($fallback, '/'))) ? $fallback : null;
        }

        return [
            'display' => $display,
            'site_name' => $name,
            'logo_url' => $logo,
            'logo_alt' => trim((string)($site['brand_logo_alt'] ?? '')) ?: $name,
            'favicon_url' => $favicon,
        ];
    }

    public function saveUpload(array $upload, string $kind): ?string
    {
        if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unable to read the ' . $kind . ' upload.');
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = (string)($upload['name'] ?? '');
        $bytes = (int)($upload['size'] ?? 0);
        return $this->storeValidatedFile($tmp, $name, $bytes, $kind, true);
    }

    private function storeValidatedFile(string $tmp, string $name, int $bytes, string $kind, bool $uploaded): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $kind === 'favicon' ? self::FAVICON_EXTENSIONS : self::LOGO_EXTENSIONS;
        $limit = $kind === 'favicon' ? 1048576 : 2097152;
        if ($bytes < 1 || $bytes > $limit || !in_array($extension, $allowed, true) || !is_file($tmp)) {
            throw new RuntimeException(ucfirst($kind) . ' must be a supported non-empty image within the upload limit.');
        }

        $error = $extension === 'svg' ? $this->validateSvg($tmp) : $this->validateRaster($tmp, $extension, $kind);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $relative = 'images/site/' . $kind . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target = (new AssetManager($this->paths))->prepareTarget($relative);
        $saved = $uploaded ? move_uploaded_file($tmp, $target) : copy($tmp, $target);
        if (!$saved) {
            throw new RuntimeException('Unable to save the ' . $kind . ' upload.');
        }
        chmod($target, 0664);
        return '/assets/' . $relative;
    }

    public function removeOwned(string $url): bool
    {
        if (!preg_match('#^/assets/images/site/[a-z0-9][a-z0-9.-]+$#i', $url)) {
            return false;
        }
        $file = (new AssetManager($this->paths))->resolveAsset(substr($url, 8));
        return $file !== null && unlink($file);
    }

    public function validateSvg(string $path): ?string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '' || strlen($contents) > 2097152) {
            return 'SVG image is empty or too large.';
        }
        $lower = strtolower($contents);
        if (!str_contains($lower, '<svg')) {
            return 'SVG image must contain an SVG root.';
        }
        foreach (['<!doctype', '<!entity', '<script', 'javascript:', '<foreignobject', 'data:', '<image'] as $blocked) {
            if (str_contains($lower, $blocked)) {
                return 'SVG image contains unsupported active or external content.';
            }
        }
        if (preg_match('/(?:href|src)\s*=\s*["\']\s*(?:https?:|\/\/)/i', $contents) === 1
            || preg_match('/url\(\s*["\']?\s*(?:https?:|\/\/)/i', $contents) === 1) {
            return 'SVG image cannot reference external resources.';
        }
        if (preg_match('/\son[a-z]+\s*=/i', $contents) === 1) {
            return 'SVG image cannot contain event handler attributes.';
        }
        return null;
    }

    public function resolveUrl(string $url): ?string
    {
        if ($url === '' || !str_starts_with($url, '/')) {
            return null;
        }
        if (str_starts_with($url, '/assets/images/site/')) {
            return (new AssetManager($this->paths))->resolveAsset(substr($url, 8)) !== null ? $url : null;
        }
        $public = $this->paths->publicPath(ltrim($url, '/'));
        return is_file($public) ? $url : null;
    }

    private function validateRaster(string $path, string $extension, string $kind): ?string
    {
        if ($extension === 'ico') {
            return $this->isValidIco($path) || $this->validRasterMime($path, ['image/png', 'image/jpeg', 'image/webp'])
                ? null
                : 'ICO favicon must contain a valid icon or browser-compatible image.';
        }
        $mimes = [
            'gif' => 'image/gif',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $info = @getimagesize($path);
        if (!is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1 || ($info[0] ?? 0) > 4096 || ($info[1] ?? 0) > 4096) {
            return ucfirst($kind) . ' must be a valid image no larger than 4096 by 4096 pixels.';
        }
        return (string)($info['mime'] ?? '') === ($mimes[$extension] ?? '') ? null : ucfirst($kind) . ' image type does not match its extension.';
    }

    private function validRasterMime(string $path, array $mimes): bool
    {
        $info = @getimagesize($path);
        return is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0 && in_array((string)($info['mime'] ?? ''), $mimes, true);
    }

    private function isValidIco(string $path): bool
    {
        $size = filesize($path);
        if (!is_int($size) || $size < 22) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $header = fread($handle, 6);
        $directory = is_string($header) && strlen($header) === 6 ? unpack('vreserved/vtype/vcount', $header) : [];
        $count = (int)($directory['count'] ?? 0);
        $end = 6 + ($count * 16);
        if (($directory['reserved'] ?? -1) !== 0 || ($directory['type'] ?? 0) !== 1 || $count < 1 || $count > 256 || $end > $size) {
            fclose($handle);
            return false;
        }
        for ($index = 0; $index < $count; $index++) {
            $entry = fread($handle, 16);
            $image = is_string($entry) && strlen($entry) === 16 ? unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vbytes/Voffset', $entry) : [];
            $bytes = (int)($image['bytes'] ?? 0);
            $offset = (int)($image['offset'] ?? 0);
            if (($image['reserved'] ?? 1) !== 0 || $bytes < 1 || $offset < $end || $offset > $size || $bytes > ($size - $offset)) {
                fclose($handle);
                return false;
            }
        }
        fclose($handle);
        return true;
    }
}
