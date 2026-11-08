<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;

/** Optional local font storage. Does not decode or execute uploaded font data. */
final class LocalFontManager
{
    public const MAX_BYTES = 5242880;
    public const FAMILY = 'Batoi Site Font';
    private const FORMATS = ['woff2'=>'woff2', 'woff'=>'woff', 'ttf'=>'truetype', 'otf'=>'opentype'];

    public function __construct(private readonly Paths $paths) {}

    public function saveUpload(array $upload): ?string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK) throw new RuntimeException('Unable to read the font upload. Check the server upload limit.');
        return $this->store((string)($upload['tmp_name'] ?? ''), (string)($upload['name'] ?? ''), (int)($upload['size'] ?? 0), true);
    }

    private function store(string $source, string $name, int $declaredBytes, bool $uploaded): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = is_file($source) && !is_link($source) ? filesize($source) : false;
        if (!isset(self::FORMATS[$extension]) || !is_int($bytes) || $bytes < 12 || $bytes > self::MAX_BYTES || $bytes !== $declaredBytes) throw new RuntimeException('Upload a WOFF2, WOFF, TTF or OTF font no larger than 5 MB.');
        if ($uploaded && !is_uploaded_file($source)) throw new RuntimeException('The font must be an HTTP file upload.');
        $data = file_get_contents($source);
        // Compressed font bytes can coincidentally contain text/PHP delimiters.
        // Enforce the binary container below; never interpret binary data as source.
        if (!is_string($data) || strlen($data) !== $bytes) throw new RuntimeException('Unable to validate the font file.');
        $this->validate($data, $extension);
        $relative = 'fonts/site/font-' . bin2hex(random_bytes(12)) . '.' . $extension;
        $target = (new AssetManager($this->paths))->prepareTarget($relative);
        if (!($uploaded ? move_uploaded_file($source, $target) : copy($source, $target))) throw new RuntimeException('Unable to save the local font.');
        chmod($target, 0664);
        return '/assets/' . $relative;
    }

    /** Structural bounds only; browser font sanitizers perform full glyph decoding. */
    private function validate(string $data, string $extension): void
    {
        $size = strlen($data);
        $signature = substr($data, 0, 4);
        $expected = ['woff2'=>'wOF2', 'woff'=>'wOFF', 'ttf'=>"\0\1\0\0", 'otf'=>'OTTO'];
        if ($signature !== $expected[$extension]) throw new RuntimeException('Font content does not match its extension.');
        $u16 = static fn(int $offset): int => unpack('n', substr($data, $offset, 2))[1];
        $u32 = static fn(int $offset): int => unpack('N', substr($data, $offset, 4))[1];
        if (in_array($extension, ['woff', 'woff2'], true)) {
            $header = $extension === 'woff2' ? 48 : 44;
            if ($size < $header || !in_array(substr($data, 4, 4), ["\0\1\0\0", 'OTTO'], true) || $u32(8) !== $size || $u16(14) !== 0) throw new RuntimeException('Font header is invalid or truncated.');
            $count = $u16(12);
            if ($count < 1 || $count > 256 || $u32(16) < 12 + 16 * $count || $u32(16) > 33554432) throw new RuntimeException('Font table count or decoded size exceeds the supported limit.');
            if ($extension === 'woff2') {
                if ($u32(20) < 1 || $u32(20) > $size - $header - 2 * $count) throw new RuntimeException('Compressed font data is invalid or truncated.');
                $meta = 28;
            } else {
                $meta = 24;
            }
            foreach ([[$u32($meta), $u32($meta+4)], [$u32($meta+12), $u32($meta+16)]] as [$offset,$length]) {
                if (($offset === 0) !== ($length === 0) || ($length > 0 && ($offset < $header || $offset > $size || $length > $size - $offset))) throw new RuntimeException('Font metadata is outside the file.');
            }
            if ($u32($meta+8) > 1048576) throw new RuntimeException('Font metadata exceeds the supported limit.');
            if ($extension === 'woff2') return;
            $directoryEnd = 44 + 20 * $count;
            $stride = 20;
            $start = 44;
        } else {
            $count = $u16(4);
            if ($count < 1 || $count > 256) throw new RuntimeException('Font table count is invalid.');
            $directoryEnd = 12 + 16 * $count;
            $stride = 16;
            $start = 12;
        }
        if ($directoryEnd > $size) throw new RuntimeException('Font table directory is truncated.');
        $tags = [];
        $ranges = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = $start + $stride * $i;
            $tag = substr($data, $entry, 4);
            if (isset($tags[$tag]) || !preg_match('/^[\x20-\x7e]{4}$/D', $tag)) throw new RuntimeException('Font table directory contains an invalid or duplicate tag.');
            $tags[$tag] = true;
            $offset = $u32($entry + ($extension === 'woff' ? 4 : 8));
            $length = $u32($entry + ($extension === 'woff' ? 8 : 12));
            if ($offset < $directoryEnd || $offset > $size || $length > $size - $offset) throw new RuntimeException('Font table is outside the file.');
            if ($extension === 'woff' && ($u32($entry+12) < $length || $u32($entry+12) > 33554432)) throw new RuntimeException('Font table decoded size is invalid.');
            if ($length > 0) $ranges[] = [$offset, $offset + $length];
        }
        sort($ranges);
        for ($i = 1; $i < count($ranges); $i++) if ($ranges[$i][0] < $ranges[$i-1][1]) throw new RuntimeException('Font tables overlap.');
        if ($extension !== 'woff') {
            $end = $ranges === [] ? $directoryEnd : $ranges[array_key_last($ranges)][1];
            $padding = substr($data, $end);
            if (strlen($padding) > 3 || trim($padding, "\0") !== '') throw new RuntimeException('Font contains unexpected trailing data.');
        }
    }

    public function resolve(string $url): ?string
    {
        if (!preg_match('#^/assets/fonts/site/font-[a-f0-9]{24}\.(?:woff2?|ttf|otf)$#D', $url)) return null;
        return (new AssetManager($this->paths))->resolveAsset(substr($url, 8));
    }

    /** Used only to discard a newly uploaded file when its settings commit fails. */
    public function discardUpload(string $url): void
    {
        $path = $this->resolve($url);
        if ($path !== null && !unlink($path)) throw new RuntimeException('Unable to remove the uncommitted font upload.');
    }

    public function css(array $site, bool $localized = true): string
    {
        $url = is_string($site['local_font'] ?? null) ? $site['local_font'] : '';
        if ($this->resolve($url) === null) return '';
        $format = self::FORMATS[pathinfo($url, PATHINFO_EXTENSION)];
        $url = $localized && function_exists('bp_url') ? \bp_url($url) : $url;
        $source = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return '@font-face{font-family:"' . self::FAMILY . '";src:url(' . $source . ') format("' . $format . '");font-style:normal;font-weight:normal;font-display:swap}';
    }
}
