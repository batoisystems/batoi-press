<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Closure;
use RuntimeException;
use Throwable;

final class OAuthTokenVerifier
{
    private bool $providerUnavailable = false;

    public function __construct(
        private readonly Paths $paths,
        private readonly array $configuration,
        private readonly FileStore $files = new FileStore(),
        private readonly ?Closure $jwksFetcher = null,
        private readonly ?Closure $introspectionTransport = null
    ) {
    }

    public function verify(string $token): ?array
    {
        $this->providerUnavailable = false;
        if (($this->configuration['enabled'] ?? false) !== true || strlen($token) > 16384 || substr_count($token, '.') !== 2) {
            return null;
        }
        $issuer = (string)($this->configuration['issuer'] ?? '');
        $resource = (string)($this->configuration['resource'] ?? '');
        if ($issuer === '' || $resource === '') {
            return null;
        }

        try {
            // Unverified headers select a key only; no authority is granted before decode.
            $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256'
                || !in_array($header['typ'] ?? null, ['at+jwt', 'application/at+jwt'], true)
                || !is_string($header['kid'] ?? null) || trim($header['kid']) === '' || strlen($header['kid']) > 200
                || array_key_exists('crit', $header)) return null;
            $keys = $this->verificationKeys($this->jwks($header['kid']));
            $claims = (array)JWT::decode($token, $keys);
        } catch (Throwable) {
            return null;
        }
        if (!isset($claims['iss']) || !is_string($claims['iss']) || !hash_equals($issuer, $claims['iss'])) {
            return null;
        }
        $audiences = is_string($claims['aud'] ?? null) ? [$claims['aud']] : ($claims['aud'] ?? null);
        if (!is_array($audiences) || !array_is_list($audiences) || $audiences === []) return null;
        foreach ($audiences as $audience) if (!is_string($audience) || $audience === '') return null;
        if (!in_array($resource, $audiences, true)) {
            return null;
        }

        $scopes = $this->scopes($claims);
        if ($scopes === []) {
            return null;
        }
        if (!is_string($claims['sub'] ?? null) || trim($claims['sub']) === ''
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_int($claims['iat'] ?? null) || $claims['iat'] < 0 || $claims['iat'] > time() || $claims['iat'] >= $claims['exp']
            || (array_key_exists('nbf', $claims) && (!is_int($claims['nbf']) || $claims['nbf'] > time() || $claims['nbf'] >= $claims['exp']))
            || !is_string($claims['jti'] ?? null) || trim($claims['jti']) === '' || strlen($claims['jti']) > 200) {
            return null;
        }
        $subject = $claims['sub'];
        $client = $claims['client_id'] ?? null;
        if (!is_string($client) || trim($client) === '' || strlen($client) > OAuthBindingRepository::MAX_CLIENT_ID_BYTES
            || preg_match('/[\x00-\x1f\x7f]/', $client)
            || (array_key_exists('azp', $claims) && $client !== $claims['azp'])) return null;
        if (array_key_exists('introspection', $this->configuration)) {
            $introspection = new OAuthIntrospectionClient($this->paths, $this->configuration, $this->introspectionTransport);
            if (!$introspection->active($token, $claims)) {
                $this->providerUnavailable = $introspection->unavailable();
                return null;
            }
        }
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

    public function providerUnavailable(): bool { return $this->providerUnavailable; }

    private function jwks(string $kid): array
    {
        if (is_array($this->configuration['jwks'] ?? null) && is_array($this->configuration['jwks']['keys'] ?? null)) {
            return $this->configuration['jwks'];
        }
        $uri = (string)($this->configuration['jwks_uri'] ?? '');
        if (!$this->safeHttpsUrl($uri)) {
            throw new RuntimeException('OAuth JWKS URI must use HTTPS.');
        }
        $cachePath = $this->paths->dataPath('integrations/oauth-jwks.json');
        $source = hash('sha256', json_encode([$this->configuration['issuer'], $uri], JSON_THROW_ON_ERROR));
        $cached = $this->cachedKeys($cachePath, $source);
        if ($this->freshKey($cached, $kid)) return $cached['jwks'];
        if (!is_dir(dirname($cachePath)) && !mkdir(dirname($cachePath), 0700, true) && !is_dir(dirname($cachePath))) {
            throw new RuntimeException('OAuth key cache is unavailable.');
        }
        $lock = fopen($cachePath . '.refresh.lock', 'c');
        if ($lock === false) throw new RuntimeException('OAuth key refresh is unavailable.');
        try {
            // Concurrent unknown-key requests fail closed rather than queue network work.
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('OAuth key refresh is busy.');
            $cached = $this->cachedKeys($cachePath, $source);
            if ($this->freshKey($cached, $kid)) return $cached['jwks'];
            if (isset($cached['refresh_attempt_at']) && time() - $cached['refresh_attempt_at'] < 30) {
                throw new RuntimeException('OAuth key refresh is rate limited.');
            }
            $cached['source'] = $source;
            $cached['refresh_attempt_at'] = time();
            $this->files->writeJson($cachePath, $cached);
            $jwks = $this->jwksFetcher !== null ? ($this->jwksFetcher)($uri) : $this->fetch($uri);
            if (!is_array($jwks) || !is_array($jwks['keys'] ?? null) || $jwks['keys'] === []) {
                throw new RuntimeException('OAuth JWKS response is invalid.');
            }
            // Parse before replacing known keys; expired caches are never used on failure.
            $this->verificationKeys($jwks);
            $this->files->writeJson($cachePath, ['source' => $source, 'fetched_at' => time(), 'refresh_attempt_at' => time(), 'jwks' => $jwks]);
            return $jwks;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cachedKeys(string $path, string $source): array
    {
        try {
            $cached = is_file($path) ? $this->files->readJson($path) : [];
        } catch (RuntimeException) {
            // A corrupt cache is never a trust source; recover through a fresh fetch.
            return [];
        }
        if (($cached['source'] ?? null) !== $source) return [];
        foreach (['fetched_at', 'refresh_attempt_at'] as $field) {
            if (isset($cached[$field]) && (!is_int($cached[$field]) || $cached[$field] < 0 || $cached[$field] > time())) return [];
        }
        return $cached;
    }

    private function verificationKeys(array $jwks): array
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 100) {
            throw new RuntimeException('OAuth JWKS must contain a bounded key list.');
        }
        $seen = [];
        $usable = [];
        foreach ($keys as $key) {
            if (!is_array($key) || !is_string($key['kid'] ?? null) || trim($key['kid']) === '' || strlen($key['kid']) > 200) {
                throw new RuntimeException('OAuth JWKS key identity is invalid.');
            }
            if (isset($seen[$key['kid']])) throw new RuntimeException('OAuth JWKS key identity is ambiguous.');
            $seen[$key['kid']] = true;
            if (($key['kty'] ?? null) !== 'RSA'
                || (array_key_exists('alg', $key) && $key['alg'] !== 'RS256')
                || (array_key_exists('use', $key) && $key['use'] !== 'sig')) continue;
            if (array_key_exists('key_ops', $key)) {
                if (!is_array($key['key_ops']) || !array_is_list($key['key_ops']) || !in_array('verify', $key['key_ops'], true)) continue;
                foreach ($key['key_ops'] as $operation) {
                    if (!is_string($operation)) throw new RuntimeException('OAuth JWKS key operations are invalid.');
                }
            }
            $usable[] = $key;
        }
        return JWK::parseKeySet(['keys' => $usable], 'RS256');
    }

    private function freshKey(array $cached, string $kid): bool
    {
        if (!isset($cached['fetched_at']) || time() - $cached['fetched_at'] >= 3600
            || !is_array($cached['jwks']['keys'] ?? null)) return false;
        foreach ($cached['jwks']['keys'] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) return true;
        }
        return false;
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
        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'BatoiPress-OAuth/2.0',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 262144) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!$configured) throw new RuntimeException('Unable to configure OAuth JWKS request.');
            $ok = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
        $decoded = $ok === true && $status === 200 ? json_decode($body, true) : null;
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null) || $decoded['keys'] === []) {
            throw new RuntimeException('OAuth JWKS response is invalid.');
        }
        return $decoded;
    }

    private function scopes(array $claims): array
    {
        $values = [];
        if (array_key_exists('scope', $claims)) {
            if (!is_string($claims['scope']) || !preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+(?: [\x21\x23-\x5B\x5D-\x7E]+)*$/D', $claims['scope'])) return [];
            $values = explode(' ', $claims['scope']);
        }
        if (array_key_exists('scp', $claims)) {
            if (!is_array($claims['scp']) || !array_is_list($claims['scp'])) return [];
            foreach ($claims['scp'] as $scope) {
                if (!is_string($scope) || !preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $scope)) return [];
            }
            if (array_key_exists('scope', $claims)) {
                $left = array_unique($values); $right = array_unique($claims['scp']);
                sort($left, SORT_STRING); sort($right, SORT_STRING);
                if ($left !== $right) return [];
            } else $values = $claims['scp'];
        }
        $allowed = array_fill_keys(AccessTokenRepository::SCOPES, true);
        $scopes = [];
        foreach ($values as $scope) {
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
        if (parse_url($uri, PHP_URL_USER) !== null || parse_url($uri, PHP_URL_PASS) !== null
            || parse_url($uri, PHP_URL_FRAGMENT) !== null) return false;
        $host = strtolower((string)(parse_url($uri, PHP_URL_HOST) ?? ''));
        $issuerHost = strtolower((string)(parse_url((string)($this->configuration['issuer'] ?? ''), PHP_URL_HOST) ?? ''));
        $allowedHosts = array_map('strtolower', array_map('strval', (array)($this->configuration['allowed_jwks_hosts'] ?? [])));
        $allowedHosts[] = $issuerHost;
        return $host !== '' && in_array($host, array_unique(array_filter($allowedHosts)), true);
    }
}
