<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;

final class SecurityHeaders
{
    public static function apply(Response $response, Request $request, Config $config): Response
    {
        $settings = is_array($config->security()['headers'] ?? null) ? $config->security()['headers'] : [];
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', (string)($settings['referrer_policy'] ?? 'strict-origin-when-cross-origin'))
            ->withHeader('Permissions-Policy', (string)($settings['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=(), payment=()'));

        $policy = (string)($settings['content_security_policy'] ?? self::defaultPolicy($request));
        $mode = strtolower((string)($settings['csp_mode'] ?? 'report-only'));
        if ($policy !== '' && $mode !== 'off') {
            $header = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $response = $response->withHeader($header, preg_replace('/[\r\n]+/', ' ', $policy) ?? $policy);
        }
        if (self::https($request) && ($settings['hsts'] ?? true) === true) {
            $maxAge = max(300, min(63072000, (int)($settings['hsts_max_age'] ?? 31536000)));
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=' . $maxAge);
        }
        return $response;
    }

    private static function defaultPolicy(Request $request): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' https://cdn.jsdelivr.net",
            "connect-src 'self'",
            "media-src 'self' https:",
            "frame-src https://www.youtube.com https://player.vimeo.com",
        ];
        if (self::https($request)) {
            $directives[] = 'upgrade-insecure-requests';
        }
        return implode('; ', $directives);
    }

    private static function https(Request $request): bool
    {
        $https = strtolower((string)($request->server['HTTPS'] ?? ''));
        $forwarded = strtolower((string)($request->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return ($https !== '' && $https !== 'off') || $forwarded === 'https';
    }
}
