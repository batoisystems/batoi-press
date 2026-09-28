<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Batoi\Press\Core\{AssetResponse, Request};

function checkAsset(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function emittedAsset($response): string { ob_start(); $response->send(); return (string)ob_get_clean(); }
$file = tempnam(sys_get_temp_dir(), 'press-range-');
try {
    file_put_contents($file, '0123456789');
    $make = static fn(array $headers = [], string $method = 'GET', array $query = [], array $settings = [], bool $theme = false) => AssetResponse::make($file, new Request($method, '/assets/test', $query, [], $headers), $settings, $theme);
    $full = $make();
    checkAsset($full->status() === 200 && emittedAsset($full->withHeader('X-Test', 'yes')) === '0123456789', 'full stream survives middleware');
    checkAsset($full->content() === '', 'stream is not materialized in Response content');
    $etag = $full->headers()['ETag'];
    foreach ([$etag, 'W/' . $etag, '"other", ' . $etag, '*'] as $match) checkAsset($make(['HTTP_IF_NONE_MATCH' => $match])->status() === 304, 'ETag match');
    checkAsset($make(['HTTP_IF_MODIFIED_SINCE' => $full->headers()['Last-Modified']])->status() === 304, 'modified since');
    checkAsset($make(['HTTP_IF_NONE_MATCH'=>'"other"', 'HTTP_IF_MODIFIED_SINCE'=>gmdate('D, d M Y H:i:s', time()+1000) . ' GMT'])->status() === 200, 'ETag takes precedence');
    $head = $make(['HTTP_RANGE'=>'bytes=2-4'], 'HEAD');
    checkAsset($head->status() === 200 && $head->headers()['Content-Length'] === '10' && emittedAsset($head) === '', 'HEAD matches full GET headers, ignores Range');
    foreach (['bytes=2-4'=>'234','bytes=7-'=>'789','bytes=-3'=>'789','bytes=0-100'=>'0123456789','bytes=-100'=>'0123456789'] as $range=>$bytes) {
        $response = $make(['HTTP_RANGE'=>$range]);
        checkAsset($response->status() === 206 && emittedAsset($response) === $bytes && (int)$response->headers()['Content-Length'] === strlen($bytes), 'range ' . $range);
    }
    foreach (['bytes=10-','bytes=8-4','bytes=-0'] as $range) checkAsset($make(['HTTP_RANGE'=>$range])->status() === 416, 'unsatisfiable range');
    foreach (['bytes=0-1,4-5', 'bogus', 'items=0-3'] as $range) checkAsset($make(['HTTP_RANGE'=>$range])->status() === 200, 'unsupported range ignored');
    checkAsset($make(['HTTP_RANGE'=>'bytes=0-1','HTTP_IF_RANGE'=>$etag])->status() === 206, 'If-Range match');
    foreach (['"stale"', 'W/' . $etag, 'invalid'] as $value) checkAsset($make(['HTTP_RANGE'=>'bytes=0-1','HTTP_IF_RANGE'=>$value])->status() === 200, 'If-Range mismatch');
    checkAsset($make([], 'POST')->status() === 405, 'unsupported method');
    checkAsset(!str_contains($make([], 'GET', [], [], true)->headers()['Cache-Control'], 'immutable'), 'unversioned theme is mutable');
    checkAsset(!str_contains($make([], 'GET', ['h'=>'bad'], [], true)->headers()['Cache-Control'], 'immutable'), 'wrong fingerprint mutable');
    $query = ['h'=>substr(hash('sha256', '0123456789'), 0, 16)];
    checkAsset(str_contains($make([], 'GET', $query, [], true)->headers()['Cache-Control'], 'immutable'), 'verified theme fingerprint');
    checkAsset($make(['HTTP_COOKIE'=>'session=secret'], 'GET', $query, [], true)->headers()['Cache-Control'] === 'private, no-store', 'cookie never cached');
    checkAsset($make(['HTTP_AUTHORIZATION'=>'Bearer secret'])->headers()['Cache-Control'] === 'private, no-store', 'authorization never cached');
    checkAsset($make([], 'GET', [], ['asset_cache_max_age'=>600])->headers()['Cache-Control'] === 'public, max-age=600, must-revalidate', 'configurable TTL');
    file_put_contents($file, 'ABCDEFGHIJ');
    checkAsset($make(['HTTP_IF_NONE_MATCH'=>$etag])->status() === 200, 'same-length same-second replacement invalidates ETag');
    file_put_contents($file, '');
    checkAsset($make(['HTTP_RANGE'=>'bytes=0-'])->status() === 416 && emittedAsset($make()) === '', 'empty file');
} finally { unlink($file); }
echo "Asset response checks passed\n";
