<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use Throwable;

final class OAuthTokenVerifier
{
    public function __construct(
        private readonly Paths $paths,
        private readonly array $configuration,
        private readonly FileStore $files = new FileStore()
    ) {
    }

    public function verify(string $token): ?array
    {
        if (($this->configuration['enabled'] ?? false) !== true || strlen($token) > 16384 || substr_count($token, '.') !== 2) {
            return null;
        }
        $issuer = (string)($this->configuration['issuer'] ?? '');
        $resource = (string)($this->configuration['resource'] ?? '');
        if ($issuer === '' || $resource === '') {
            return null;
        }

        try {
            $keys = JWK::parseKeySet($this->jwks(), 'RS256');
            $claims = (array)JWT::decode($token, $keys);
        } catch (Throwable) {
            return null;
        }
        if (!isset($claims['iss']) || !is_string($claims['iss']) || !hash_equals($issuer, $claims['iss'])) {
            return null;
        }
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [(string)($claims['aud'] ?? '')];
        if (!in_array($resource, $audiences, true)) {
            return null;
        }

        $scopes = $this->scopes($claims);
        if ($scopes === []) {
            return null;
        }
        if (!is_string($claims['sub'] ?? null) || trim($claims['sub']) === ''
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) {
            return null;
        }
        $subject = $claims['sub'];
        $client = $claims['client_id'] ?? $claims['azp'] ?? null;
        if (!is_string($client) || trim($client) === '' || strlen($client) > 200
            || (isset($claims['client_id'], $claims['azp']) && $claims['client_id'] !== $claims['azp'])) return null;
        return [
            'id' => 'oauth_' . substr(hash('sha256', $issuer . '|' . $subject), 0, 24),
            'name' => 'OAuth connection',
            'scopes' => $scopes,
            'issued_by' => 'oauth',
            'created_at' => isset($claims['iat']) ? date(DATE_ATOM, (int)$claims['iat']) : '',
            'expires_at' => isset($claims['exp']) ? date(DATE_ATOM, (int)$claims['exp']) : null,
            'revoked_at' => null,
            'active' => true,
            'oauth' => true,
            'issuer' => $issuer,
            'subject' => $subject,
            'client_id' => $client,
        ];
    }

    private function jwks(): array
    {
        if (is_array($this->configuration['jwks'] ?? null) && is_array($this->configuration['jwks']['keys'] ?? null)) {
            return $this->configuration['jwks'];
        }
        $uri = (string)($this->configuration['jwks_uri'] ?? '');
        if (!$this->safeHttpsUrl($uri)) {
            throw new RuntimeException('OAuth JWKS URI must use HTTPS.');
        }
        $cachePath = $this->paths->dataPath('integrations/oauth-jwks.json');
        $cached = [];
        if (is_file($cachePath)) {
            $cached = $this->files->readJson($cachePath);
            if (isset($cached['fetched_at'], $cached['jwks']) && time() - (int)$cached['fetched_at'] < 3600 && is_array($cached['jwks'])) {
                return $cached['jwks'];
            }
        }
        try {
            $jwks = $this->fetch($uri);
        } catch (RuntimeException $exception) {
            if (isset($cached['fetched_at'], $cached['jwks']) && time() - (int)$cached['fetched_at'] < 86400 && is_array($cached['jwks'])) {
                return $cached['jwks'];
            }
            throw $exception;
        }
        $this->files->writeJson($cachePath, ['fetched_at' => time(), 'jwks' => $jwks]);
        return $jwks;
    }

    private function fetch(string $uri): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('OAuth JWKS refresh requires the PHP cURL extension.');
        }
        $body = '';
        $handle = curl_init($uri);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize OAuth JWKS request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'BatoiPress-OAuth/2.0',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = $ok === true && $status === 200 ? json_decode($body, true) : null;
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null) || $decoded['keys'] === []) {
            throw new RuntimeException('OAuth JWKS response is invalid.');
        }
        return $decoded;
    }

    private function scopes(array $claims): array
    {
        $values = [];
        if (is_string($claims['scope'] ?? null)) {
            $values = preg_split('/\s+/', trim($claims['scope'])) ?: [];
        } elseif (is_array($claims['scp'] ?? null)) {
            $values = $claims['scp'];
        }
        $allowed = array_fill_keys(AccessTokenRepository::SCOPES, true);
        $scopes = [];
        foreach ($values as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (isset($allowed[$scope])) {
                $scopes[$scope] = true;
            }
        }
        $scopes = array_keys($scopes);
        sort($scopes, SORT_STRING);
        return $scopes;
    }

    private function safeHttpsUrl(string $uri): bool
    {
        if (preg_match('#^https://#i', $uri) !== 1 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $host = strtolower((string)(parse_url($uri, PHP_URL_HOST) ?? ''));
        $issuerHost = strtolower((string)(parse_url((string)($this->configuration['issuer'] ?? ''), PHP_URL_HOST) ?? ''));
        $allowedHosts = array_map('strtolower', array_map('strval', (array)($this->configuration['allowed_jwks_hosts'] ?? [])));
        $allowedHosts[] = $issuerHost;
        return $host !== '' && in_array($host, array_unique(array_filter($allowedHosts)), true);
    }
}
