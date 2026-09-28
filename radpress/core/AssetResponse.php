<?php
declare(strict_types=1);
namespace Batoi\Press\Core;

/** Public files only: callers must resolve and authorize the path first. */
final class AssetResponse
{
    public static function policy(array $settings, bool $fingerprinted = false): string
    {
        if ($fingerprinted) return 'public, max-age=31536000, immutable';
        return 'public, max-age=' . max(0, min(86400, (int)($settings['asset_cache_max_age'] ?? 0))) . ', must-revalidate';
    }

    public static function make(string $path, Request $request, array $settings = [], bool $themeAsset = false): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) return new Response('', 405, ['Allow' => 'GET, HEAD', 'Cache-Control' => 'no-store']);
        $stream = @fopen($path, 'rb');
        if ($stream === false) return new Response('', 404, ['Cache-Control' => 'no-store']);
        $stat = fstat($stream);
        if (!is_array($stat)) { fclose($stream); return new Response('', 500, ['Cache-Control' => 'no-store']); }
        // Hash the same open representation in bounded memory; timestamps alone miss same-second edits.
        // xxHash is a fast representation validator, never a security/signature check.
        // Theme URL fingerprints retain their existing SHA-256 contract.
        $algorithm = !$themeAsset && in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $context = hash_init($algorithm);
        if (hash_update_stream($context, $stream) !== (int)$stat['size']) {
            fclose($stream);
            return new Response('', 500, ['Cache-Control' => 'no-store']);
        }
        $hash = hash_final($context);
        rewind($stream);
        $size = (int)$stat['size'];
        $mtime = (int)$stat['mtime'];
        $etag = '"' . $hash . '"';
        $fingerprint = is_string($request->query['h'] ?? null) ? $request->query['h'] : '';
        $immutable = $themeAsset && strlen($fingerprint) === 16 && hash_equals(substr($hash, 0, 16), $fingerprint);
        $private = $request->header('Cookie') !== '' || $request->header('Authorization') !== ''
            || isset($request->server['PHP_AUTH_USER']) || isset($request->server['REMOTE_USER']);
        $headers = ['Content-Type' => AssetManager::mimeType($path), 'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'Cache-Control' => $private ? 'private, no-store' : self::policy($settings, $immutable),
            'Vary' => 'Cookie, Authorization', 'Accept-Ranges' => 'bytes', 'X-Content-Type-Options' => 'nosniff'];
        $noneMatch = $request->header('If-None-Match');
        $notModified = $noneMatch !== '' ? ($noneMatch === '*' || in_array($etag, array_map(static fn($value) => preg_replace('/^W\//', '', trim($value)), explode(',', $noneMatch)), true))
            : ($request->header('If-Modified-Since') !== '' && ($date = strtotime($request->header('If-Modified-Since'))) !== false && $mtime <= $date);
        if ($notModified) { fclose($stream); return new Response('', 304, $headers); }
        $offset = 0;
        $length = $size;
        $status = 200;
        $range = $request->method === 'GET' ? $request->header('Range') : '';
        $ifRange = $request->header('If-Range');
        if ($ifRange !== '' && $ifRange !== $etag && !(($date = strtotime($ifRange)) !== false && $mtime <= $date)) $range = '';
        // Unsupported multi-ranges or malformed syntax are ignored, per HTTP semantics.
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) && ($match[1] !== '' || $match[2] !== '')) {
            $start = $match[1] === '' ? max(0, $size - (int)$match[2]) : (int)$match[1];
            $end = $match[1] === '' || $match[2] === '' ? $size - 1 : min($size - 1, (int)$match[2]);
            if ($size === 0 || $start >= $size || $start > $end) {
                fclose($stream);
                return new Response('', 416, $headers + ['Content-Range' => 'bytes */' . $size, 'Content-Length' => '0']);
            }
            $status = 206;
            $offset = $start;
            $length = $end - $start + 1;
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $headers['Content-Length'] = (string)$length;
        if ($request->method === 'HEAD') { fclose($stream); return new Response('', $status, $headers); }
        return new Response('', $status, $headers, [], new FileBody($stream, $offset, $length));
    }
}
