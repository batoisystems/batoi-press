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

    public function authorize(Request $request, array $requiredScopes, bool $validateOrigin = false): array
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
            throw $this->unauthorized('A bearer access token is required.');
        }

        $access = $this->tokens->authenticate($matches[1]);
        if ($access === null) {
            $failureLimiter->hit($failureKey);
            throw $this->unauthorized('The bearer access token is invalid, expired, or revoked.');
        }
        $failureLimiter->clear($failureKey);

        $requestLimiter = new RateLimiter($this->config->paths(), 240, 60);
        $requestKey = 'machine-token:' . (string)($access['id'] ?? 'unknown');
        if ($requestLimiter->tooManyAttempts($requestKey)) {
            throw new MachineAccessException('Machine request limit exceeded.', 429, 'rate_limited', ['Retry-After' => '60']);
        }
        $requestLimiter->hit($requestKey);

        $granted = is_array($access['scopes'] ?? null) ? $access['scopes'] : [];
        if (array_diff($requiredScopes, $granted) !== []) {
            throw new MachineAccessException('The access token does not grant the required scope.', 403, 'insufficient_scope', [
                'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . implode(' ', $requiredScopes) . '"',
            ]);
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

    private function unauthorized(string $message): MachineAccessException
    {
        return new MachineAccessException($message, 401, 'unauthorized', [
            'WWW-Authenticate' => 'Bearer realm="Batoi Press", error="invalid_token"',
        ]);
    }
}
