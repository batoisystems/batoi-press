<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;

final class MachineAuthenticator
{
    private AccessTokenRepository $tokens;

    public function __construct(private readonly Config $config)
    {
        $this->tokens = new AccessTokenRepository($config->paths());
    }

    public function authorize(Request $request, array $requiredScopes, bool $validateOrigin = false, array $anyScopes = []): array
    {
        if ($validateOrigin) {
            $this->validateOrigin($request->header('Origin'));
        }

        $ip = (string)($request->server['REMOTE_ADDR'] ?? 'unknown');
        $failureLimiter = new RateLimiter($this->config->paths(), 10, 300);
        $failureKey = 'machine-auth:' . $ip;
        if ($failureLimiter->tooManyAttempts($failureKey)) {
            throw new MachineAccessException('Too many failed authentication attempts.', 429, 'rate_limited', ['Retry-After' => '300']);
        }

        $authorization = $request->header('Authorization');
        if (preg_match('/^Bearer\s+([^\s]+)$/Di', $authorization, $matches) !== 1) {
            $failureLimiter->hit($failureKey);
            throw $this->unauthorized('A bearer access token is required.', $requiredScopes);
        }

        $access = $this->tokens->authenticate($matches[1]);
        if ($access === null) {
            $access = (new OAuthTokenVerifier($this->config->paths(), $this->oauthConfiguration()))->verify($matches[1]);
        }
        if ($access !== null) {
            $access = (new MachineAccessPolicy($this->config->paths()))->resolve($access);
        }
        if ($access === null) {
            $failureLimiter->hit($failureKey);
            throw $this->unauthorized('The bearer access token is invalid, expired, revoked, or intended for another resource.', $requiredScopes);
        }
        $failureLimiter->clear($failureKey);

        $requestLimiter = new RateLimiter($this->config->paths(), 240, 60);
        $requestKey = 'machine-token:' . (string)($access['id'] ?? 'unknown');
        if ($requestLimiter->tooManyAttempts($requestKey)) {
            throw new MachineAccessException('Machine request limit exceeded.', 429, 'rate_limited', ['Retry-After' => '60']);
        }
        $requestLimiter->hit($requestKey);

        $granted = is_array($access['scopes'] ?? null) ? $access['scopes'] : [];
        if (array_diff($requiredScopes, $granted) !== [] || ($anyScopes !== [] && array_intersect($anyScopes, $granted) === [])) {
            throw new MachineAccessException('The access token does not grant the required scope.', 403, 'insufficient_scope', [
                'WWW-Authenticate' => $this->challenge('insufficient_scope', $requiredScopes ?: [$anyScopes[0]]),
            ]);
        }
        if (($access['oauth'] ?? false) !== true) {
            $this->tokens->markUsed((string)$access['id']);
        }
        return $access;
    }

    private function validateOrigin(string $origin): void
    {
        if ($origin === '') {
            return;
        }
        $allowed = [];
        $base = (string)($this->config->site()['base_url'] ?? '');
        $parts = parse_url($base);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $allowed[] = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        }
        foreach ((array)($this->config->security()['machine_allowed_origins'] ?? []) as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $allowed[] = strtolower(rtrim($candidate, '/'));
            }
        }
        if (!in_array(strtolower(rtrim($origin, '/')), array_unique($allowed), true)) {
            throw new MachineAccessException('The request origin is not allowed.', 403, 'origin_not_allowed');
        }
    }

    private function unauthorized(string $message, array $scopes = []): MachineAccessException
    {
        return new MachineAccessException($message, 401, 'unauthorized', [
            'WWW-Authenticate' => $this->challenge('invalid_token', $scopes),
        ]);
    }

    private function challenge(string $error, array $scopes): string
    {
        $parts = ['Bearer realm="Batoi Press"', 'error="' . $error . '"'];
        $oauth = $this->oauthConfiguration();
        if (($oauth['enabled'] ?? false) === true) {
            $base = rtrim((string)($this->config->site()['base_url'] ?? ''), '/');
            if ($base !== '') {
                $parts[] = 'resource_metadata="' . $base . '/.well-known/oauth-protected-resource"';
            }
        }
        if ($scopes !== []) {
            $parts[] = 'scope="' . implode(' ', $scopes) . '"';
        }
        return implode(', ', $parts);
    }

    private function oauthConfiguration(): array
    {
        $oauth = is_array($this->config->security()['oauth'] ?? null) ? $this->config->security()['oauth'] : [];
        if (!isset($oauth['resource']) || trim((string)$oauth['resource']) === '') {
            $oauth['resource'] = rtrim((string)($this->config->site()['base_url'] ?? ''), '/') . '/mcp';
        }
        return $oauth;
    }
}
