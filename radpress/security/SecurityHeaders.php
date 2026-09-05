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

        $customPolicy = array_key_exists('content_security_policy', $settings);
        $policy = (string)($settings['content_security_policy'] ?? self::defaultPolicy($request, $config));
        if (!$customPolicy) {
            $policy = self::withInlineScriptHashes($policy, $response->inlineScriptHashes());
        }
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

    private static function defaultPolicy(Request $request, Config $config): string
    {
        $integrations = $config->integrations();
        $scriptSources = ["'self'", 'https://cdn.jsdelivr.net'];
        $connectSources = ["'self'"];
        $frameSources = ['https://www.youtube.com', 'https://player.vimeo.com'];
        $styleSources = ["'self'", "'unsafe-inline'"];
        $fontSources = ["'self'", 'data:'];
        if (preg_match('/^G-[A-Z0-9]{4,20}$/', strtoupper((string)($integrations['analytics_measurement_id'] ?? ''))) === 1) {
            $scriptSources[] = 'https://www.googletagmanager.com';
            $connectSources[] = 'https://*.google-analytics.com';
        }
        if ((string)($integrations['recaptcha_site_key'] ?? '') !== '') {
            $scriptSources[] = 'https://www.google.com';
            $scriptSources[] = 'https://www.gstatic.com';
            $connectSources[] = 'https://www.google.com';
            $frameSources[] = 'https://www.google.com';
        }
        $fontUrl = (string)($config->site()['font_stylesheet_url'] ?? '');
        $fontHost = filter_var($fontUrl, FILTER_VALIDATE_URL) ? parse_url($fontUrl, PHP_URL_HOST) : null;
        if (is_string($fontHost) && $fontHost !== '') {
            $styleSources[] = 'https://' . $fontHost;
            if ($fontHost === 'fonts.googleapis.com') $fontSources[] = 'https://fonts.gstatic.com';
        }
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: https:",
            'font-src ' . implode(' ', array_unique($fontSources)),
            'style-src ' . implode(' ', array_unique($styleSources)),
            'script-src ' . implode(' ', array_unique($scriptSources)),
            'connect-src ' . implode(' ', array_unique($connectSources)),
            "media-src 'self' https:",
            'frame-src ' . implode(' ', array_unique($frameSources)),
        ];
        if (self::https($request)) {
            $directives[] = 'upgrade-insecure-requests';
        }
        return implode('; ', $directives);
    }

    private static function withInlineScriptHashes(string $policy, array $hashes): string
    {
        if ($hashes === []) return $policy;
        return preg_replace('/\bscript-src\s+([^;]*)/i', 'script-src $1 ' . implode(' ', array_unique($hashes)), $policy, 1) ?? $policy;
    }

    private static function https(Request $request): bool
    {
        $https = strtolower((string)($request->server['HTTPS'] ?? ''));
        $forwarded = strtolower((string)($request->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return ($https !== '' && $https !== 'off') || $forwarded === 'https';
    }
}
