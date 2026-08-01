<?php
declare(strict_types=1);

namespace Batoi\Press\Api;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AccessTokenRepository;

final class OAuthMetadataController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::json(['error' => 'method_not_allowed'], 405, ['Allow' => 'GET']);
        }
        $oauth = is_array($this->config->security()['oauth'] ?? null) ? $this->config->security()['oauth'] : [];
        if (($oauth['enabled'] ?? false) !== true) {
            return Response::json(['error' => 'oauth_not_configured'], 404, $this->headers());
        }
        $base = rtrim((string)($this->config->site()['base_url'] ?? ''), '/');
        $resource = rtrim((string)($oauth['resource'] ?? ($base . '/mcp')), '/');
        $servers = array_values(array_filter(array_map('strval', (array)($oauth['authorization_servers'] ?? [])), static fn (string $value): bool => preg_match('#^https://#i', $value) === 1));
        $issuer = rtrim((string)($oauth['issuer'] ?? ''), '/');
        if ($servers === [] && preg_match('#^https://#i', $issuer) === 1) {
            $servers[] = $issuer;
        }
        if (preg_match('#^https://#i', $resource) !== 1 || $servers === []) {
            return Response::json(['error' => 'oauth_configuration_invalid'], 503, $this->headers());
        }
        $configuredScopes = array_map('strval', (array)($oauth['scopes_supported'] ?? ['site:read', 'content:read']));
        $scopes = array_values(array_intersect(AccessTokenRepository::SCOPES, $configuredScopes));
        return Response::json([
            'resource' => $resource,
            'authorization_servers' => array_values(array_unique($servers)),
            'scopes_supported' => $scopes,
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string)($this->config->site()['name'] ?? 'Batoi Press'),
        ], 200, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff'];
    }
}
