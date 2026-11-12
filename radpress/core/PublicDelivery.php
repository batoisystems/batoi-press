<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class PublicDelivery
{
    public static function isPublicRead(Request $request): bool
    {
        return in_array($request->method, ['GET', 'HEAD'], true)
            && !preg_match('~^/(?:admin|install|api|mcp|forms|contact|commerce|account|cart|checkout|\\.well-known)(?:[/.]|$)~i', $request->path);
    }

    public static function canonicalHost(Request $request, string $base): ?Response
    {
        if (!self::isPublicRead($request) || $request->header('Authorization') !== ''
            || ($site = SocialMetadata::siteBase($base)) === '') return null;

        $host = strtolower((string)parse_url($site, PHP_URL_HOST));
        $alias = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) return null;

        $port = parse_url($site, PHP_URL_PORT);
        $requestHost = strtolower($request->header('Host'));
        if ($requestHost !== $alias . ($port === null ? '' : ':' . $port)) return null;

        $uri = (string)($request->server['REQUEST_URI'] ?? '');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) return null;
        $mount = (string)parse_url($site, PHP_URL_PATH);
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if ($mount !== '' && $path !== $mount && !str_starts_with($path, $mount . '/')) return null;

        return Response::redirect(substr($site, 0, strlen($site) - strlen($mount)) . $uri, 301);
    }

    public static function compress(Request $request, Response $response, array $settings): Response
    {
        if (!self::isPublicRead($request) || $request->query !== [] || $request->header('Range') !== '' || $response->status() !== 200
            || ($settings['public_compression'] ?? true) === false || !function_exists('gzencode')
            || (bool)ini_get('zlib.output_compression')) return $response;

        $headers = array_change_key_case($response->headers());
        $type = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        if (!in_array($type, ['text/html', 'text/plain', 'application/xml', 'application/rss+xml'], true)
            || isset($headers['content-encoding']) || isset($headers['content-range'])
            || isset($headers['content-length']) || isset($headers['etag'])
            || isset($headers['set-cookie']) || preg_match('/\\b(?:private|no-store|no-transform)\\b/i', $headers['cache-control'] ?? '')
            || strlen($response->content()) < 1024
            || ($type === 'text/html' && preg_match('~\bname\s*=\s*["\']?csrf[_-]~i', $response->content()))) return $response;

        // PHP sessions and cookie-setting routes may personalize HTML outside Response headers.
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) return $response;
        }
        if (session_status() === PHP_SESSION_ACTIVE) return $response;

        $response = $response->varyOn('Accept-Encoding', 'Cookie', 'Authorization');
        if ($request->header('Cookie') !== '' || $request->header('Authorization') !== ''
            || isset($request->server['PHP_AUTH_USER']) || isset($request->server['REMOTE_USER'])
            || !self::acceptsGzip($request->header('Accept-Encoding'))) return $response;

        return $response->gzip();
    }

    private static function acceptsGzip(string $value): bool
    {
        $qualities = [];
        foreach (explode(',', strtolower($value)) as $part) {
            if (preg_match('/^\s*(gzip|\*)\s*(?:;\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\s*$/D', $part, $matches)) {
                $qualities[$matches[1]] = isset($matches[2]) ? (float)$matches[2] : 1.0;
            }
        }
        return ($qualities['gzip'] ?? $qualities['*'] ?? 0.0) > 0;
    }
}
