<?php
declare(strict_types=1);

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Security\OAuthTokenVerifier;
use Firebase\JWT\JWT;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/press-oauth-hardening-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
});
$paths = new Paths($root, ['data' => 'data']);
$files = new FileStore();
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) throw new RuntimeException('RSA generation failed');
openssl_pkey_export($key, $privateKey);
$details = openssl_pkey_get_details($key);
$b64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$jwk = ['kty' => 'RSA', 'kid' => 'first', 'alg' => 'RS256', 'use' => 'sig',
    'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
$secondKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($secondKey === false) throw new RuntimeException('Rotation RSA generation failed');
openssl_pkey_export($secondKey, $secondPrivateKey);
$secondDetails = openssl_pkey_get_details($secondKey);
$secondJwk = array_replace($jwk, ['kid' => 'second', 'n' => $b64($secondDetails['rsa']['n']), 'e' => $b64($secondDetails['rsa']['e'])]);
$jwks = ['keys' => [$jwk]];
$configuration = ['enabled' => true, 'issuer' => 'https://identity.example.test', 'resource' => 'https://press.example.test/mcp', 'jwks' => $jwks];
$claims = ['iss' => $configuration['issuer'], 'aud' => $configuration['resource'], 'sub' => 'owner',
    'client_id' => 'client', 'iat' => time(), 'exp' => time() + 300, 'jti' => 'one', 'scope' => 'site:read'];
$encode = static fn (array $payload, array $headers = ['typ' => 'at+jwt'], string $kid = 'first'): string => JWT::encode($payload, $kid === 'second' ? $secondPrivateKey : $privateKey, 'RS256', $kid, $headers);
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$verifier = new OAuthTokenVerifier($paths, $configuration);
$check($verifier->verify($encode($claims)) !== null, 'access JWT accepted');
$check($verifier->verify($encode($claims, ['typ' => 'application/at+jwt'])) !== null, 'full media type accepted');
foreach (['JWT', 'id+jwt', '', null] as $type) {
    $check($verifier->verify($encode($claims, ['typ' => $type])) === null, 'ID/wrong type rejected');
}
$check($verifier->verify($encode($claims, ['typ' => 'at+jwt', 'crit' => ['unknown']])) === null, 'unsupported critical header rejected');
$check($verifier->verify($encode($claims, ['typ' => 'at+jwt', 'crit' => null])) === null, 'malformed critical header rejected');
$check($verifier->verify($encode($claims, ['typ' => 'at+jwt'], '')) === null, 'empty key ID rejected');
$check($verifier->verify(JWT::encode($claims, str_repeat('untrusted-fixture', 4), 'HS256', 'first', ['typ' => 'at+jwt'])) === null, 'algorithm confusion rejected');
$tampered = $encode($claims);
$segments = explode('.', $tampered);
$segments[1] = $b64(json_encode(array_replace($claims, ['sub' => 'attacker'])));
$check($verifier->verify(implode('.', $segments)) === null, 'unsigned payload substitution rejected');
foreach ([['scope' => 'SITE:READ'], ['scope' => ['site:read']], ['scope' => "site:read\tcontent:read"],
    ['scp' => [['site:read']]], ['scp' => ['other' => 'site:read']], ['scp' => ['content:read']],
    ['aud' => [$configuration['resource'], 1]], ['iat' => '1'], ['iat' => time() + 100],
    ['iat' => time() + 100, 'nbf' => time() - 5], ['exp' => (string)(time() + 300)],
    ['nbf' => null], ['nbf' => '1'], ['jti' => ''], ['azp' => 'another']] as $change) {
    // A null optional claim is malformed too, not an absent claim.
    $check($verifier->verify($encode(array_replace($claims, $change))) === null, 'malformed claims rejected: ' . json_encode($change));
}
$check($verifier->verify($encode(array_replace($claims, ['scp' => ['site:read']]))) !== null, 'consistent scope representations accepted');
$scpOnly = $claims; unset($scpOnly['scope']); $scpOnly['scp'] = ['site:read'];
$check($verifier->verify($encode($scpOnly)) !== null, 'strict list scopes supported');
$clientPrefix = 'https://client.example.test/';
$longClient = $clientPrefix . str_repeat('a', \Batoi\Press\Security\OAuthBindingRepository::MAX_CLIENT_ID_BYTES - strlen($clientPrefix));
$check($verifier->verify($encode(array_replace($claims, ['client_id' => $longClient]))) !== null, 'bounded URL-valued client ID accepted');
$check($verifier->verify($encode(array_replace($claims, ['client_id' => $longClient . 'a']))) === null, 'oversized client ID rejected');
$check($verifier->verify($encode(array_replace($claims, ['client_id' => "client\nidentifier"]))) === null, 'control-bearing client ID rejected');
$mixedKeys = new OAuthTokenVerifier($paths, array_replace($configuration, ['jwks' => ['keys' => [$jwk, array_replace($secondJwk, ['use' => 'enc'])]]]));
$check($mixedKeys->verify($encode($claims)) !== null, 'eligible key works in mixed signing/encryption JWKS');
foreach ([['keys' => [$jwk, $jwk]], ['keys' => ['first' => $jwk]],
    ['keys' => [array_replace($jwk, ['use' => 'enc'])]], ['keys' => [array_replace($jwk, ['key_ops' => ['encrypt']])]],
    ['keys' => [array_replace($jwk, ['key_ops' => ['verify', 1]])]], ['keys' => [array_replace($jwk, ['kid' => null])]],
    ['keys' => [array_replace($jwk, ['alg' => 'RS512'])]]] as $invalidJwks) {
    $invalidVerifier = new OAuthTokenVerifier($paths, array_replace($configuration, ['jwks' => $invalidJwks]));
    $check($invalidVerifier->verify($encode($claims)) === null, 'ambiguous/ineligible signing keys rejected');
}
$eligible = new OAuthTokenVerifier($paths, array_replace($configuration, ['jwks' => ['keys' => [array_replace($jwk, ['key_ops' => ['verify']])]]]));
$check($eligible->verify($encode($claims)) !== null, 'explicit verification key accepted');

unset($configuration['jwks']);
$configuration['jwks_uri'] = 'https://identity.example.test/keys';
$cachePath = $paths->dataPath('integrations/oauth-jwks.json');
$source = hash('sha256', json_encode([$configuration['issuer'], $configuration['jwks_uri']], JSON_THROW_ON_ERROR));
$calls = 0;
$remote = new OAuthTokenVerifier($paths, $configuration, $files, static function (string $uri) use (&$calls, $jwks): array { ++$calls; return $jwks; });
$check($remote->verify($encode($claims)) !== null && $calls === 1, 'first key fetched');
$check($remote->verify($encode($claims)) !== null && $calls === 1, 'known key cached');
$check($remote->verify($encode($claims, ['typ' => 'JWT'], 'random')) === null && $calls === 1, 'wrong token type never refreshes keys');
$check($remote->verify($encode($claims, ['typ' => 'at+jwt'], 'random')) === null && $calls === 1, 'unknown-key refresh rate bounded');
$cached = $files->readJson($cachePath); $cached['refresh_attempt_at'] = time() - 31; $files->writeJson($cachePath, $cached);
$rotated = ['keys' => [$secondJwk]];
$rotation = new OAuthTokenVerifier($paths, $configuration, $files, static function (string $uri) use (&$calls, $rotated): array { ++$calls; return $rotated; });
$check($rotation->verify($encode($claims, ['typ' => 'at+jwt'], 'second')) !== null && $calls === 2, 'unknown rotated key triggers refresh');
$check($rotation->verify($encode($claims)) === null && $calls === 2, 'removed key rejected');
$cached = $files->readJson($cachePath); $cached['fetched_at'] = time() - 3601; $cached['refresh_attempt_at'] = time() - 31; $files->writeJson($cachePath, $cached);
$offline = new OAuthTokenVerifier($paths, $configuration, $files, static function (): array { throw new RuntimeException('offline'); });
$check($offline->verify($encode($claims, ['typ' => 'at+jwt'], 'second')) === null, 'expired keys never survive failed refresh');
$files->writeJson($cachePath, ['source' => 'another-provider', 'fetched_at' => time(), 'jwks' => $jwks]);
$check($offline->verify($encode($claims)) === null, 'other trust source cache rejected');
$files->writeJson($cachePath, ['fetched_at' => time(), 'jwks' => $jwks]);
$check($offline->verify($encode($claims)) === null, 'legacy unbound cache rejected');
$changedUri = array_replace($configuration, ['jwks_uri' => 'https://identity.example.test/other-keys']);
$files->writeJson($cachePath, ['source' => $source, 'fetched_at' => time(), 'jwks' => $jwks]);
$changedVerifier = new OAuthTokenVerifier($paths, $changedUri, $files, static function (): array { throw new RuntimeException('offline'); });
$check($changedVerifier->verify($encode($claims)) === null, 'changed JWKS URI cannot reuse cache');
$changedIssuer = array_replace($configuration, ['issuer' => 'https://another-identity.example.test', 'allowed_jwks_hosts' => ['identity.example.test']]);
$files->writeJson($cachePath, ['source' => $source, 'fetched_at' => time(), 'jwks' => $jwks]);
$issuerVerifier = new OAuthTokenVerifier($paths, $changedIssuer, $files, static function (): array { throw new RuntimeException('offline'); });
$check($issuerVerifier->verify($encode(array_replace($claims, ['iss' => $changedIssuer['issuer']]))) === null, 'changed issuer cannot reuse same-URI cache');
$files->writeJson($cachePath, ['source' => $source, 'fetched_at' => time() + 100, 'jwks' => $jwks]);
$check($offline->verify($encode($claims)) === null, 'future cache timestamp rejected');
$files->writeJson($cachePath, ['source' => $source, 'fetched_at' => time(), 'refresh_attempt_at' => time() - 31, 'jwks' => $jwks]);
$lock = fopen($cachePath . '.refresh.lock', 'c'); flock($lock, LOCK_EX);
$check($rotation->verify($encode($claims, ['typ' => 'at+jwt'], 'second')) === null && $calls === 2, 'concurrent refresh fails closed');
flock($lock, LOCK_UN); fclose($lock);
$files->write($cachePath, '{corrupt');
$check($remote->verify($encode($claims)) !== null && $calls === 3, 'corrupt cache recovers through fresh trusted fetch');
foreach (['http://identity.example.test/keys', 'https://other.example.test/keys', 'https://user:password@identity.example.test/keys', 'https://identity.example.test/keys#fragment'] as $uri) {
    $unsafe = new OAuthTokenVerifier($paths, array_replace($configuration, ['jwks_uri' => $uri]), $files,
        static function () use (&$calls, $jwks): array { ++$calls; return $jwks; });
    $check($unsafe->verify($encode($claims)) === null && $calls === 3, 'unsafe URI rejected before fetching');
}
foreach ([[], ['keys' => []], ['keys' => [['kid' => 'broken', 'kty' => 'RSA']]], ['keys' => [$jwk, $jwk]]] as $badResponse) {
    $files->writeJson($cachePath, ['source' => $source, 'fetched_at' => time(), 'refresh_attempt_at' => time() - 31, 'jwks' => $jwks]);
    $invalidRefresh = new OAuthTokenVerifier($paths, $configuration, $files, static fn (): array => $badResponse);
    $check($invalidRefresh->verify($encode($claims, ['typ' => 'at+jwt'], 'second')) === null, 'bad refresh rejected');
    $check(($files->readJson($cachePath)['jwks'] ?? null) === $jwks, 'bad refresh cannot replace valid cache');
    $check($invalidRefresh->verify($encode($claims)) !== null, 'fresh known key survives failed unknown-key refresh');
}
$introspectionCalls = 0;
$introspectionResponse = ['active' => true, 'token_type' => 'Bearer'] + $claims;
$secret = 'synthetic:secret + percent%';
$profile = ['required' => true, 'endpoint' => 'https://identity.example.test/oauth/introspect',
    'resource_id' => 'synthetic-resource', 'secret_encrypted' => (new \Batoi\Press\Security\SecretStore($paths))->encrypt($secret)];
$activeConfiguration = array_replace($configuration, ['jwks' => $jwks, 'introspection' => $profile]);
$transport = static function (string $endpoint, array $headers, string $body) use (&$introspectionCalls, &$introspectionResponse, $secret, $check): array {
    ++$introspectionCalls;
    $check($endpoint === 'https://identity.example.test/oauth/introspect', 'configured issuer endpoint used');
    $authorization = array_values(array_filter($headers, static fn ($header): bool => str_starts_with($header, 'Authorization: Basic ')))[0] ?? '';
    $credentials = base64_decode(substr($authorization, strlen('Authorization: Basic ')), true);
    $check($credentials === urlencode('synthetic-resource') . ':' . urlencode($secret), 'resource credentials form-encoded in Basic header');
    parse_str($body, $parameters);
    $check(isset($parameters['token']) && $parameters['token_type_hint'] === 'access_token', 'token submitted in form body');
    return $introspectionResponse;
};
$activeVerifier = new OAuthTokenVerifier($paths, $activeConfiguration, $files, null, $transport);
$check($activeVerifier->verify($encode($claims)) !== null && $introspectionCalls === 1, 'active matching token accepted');
$check($activeVerifier->verify($encode($claims)) !== null && $introspectionCalls === 2, 'active state checked on each request without positive cache');
foreach ([['active' => false], ['active' => 'true'], ['sub' => 'another'], ['aud' => 'https://other.example.test/mcp'],
    ['client_id' => 'another'], ['jti' => 'another'], ['exp' => (string)$claims['exp']], ['scope' => 'content:read'], ['token_type' => 'refresh_token']] as $change) {
    $introspectionResponse = array_replace(['active' => true, 'token_type' => 'Bearer'] + $claims, $change);
    $check($activeVerifier->verify($encode($claims)) === null, 'inactive or inconsistent active-state response rejected');
}
$introspectionResponse = ['active' => true];
$check($activeVerifier->verify($encode($claims)) === null, 'incomplete active-state response rejected');
$outage = new OAuthTokenVerifier($paths, $activeConfiguration, $files, null, static function (): array { throw new RuntimeException('synthetic private provider detail'); });
$check($outage->verify($encode($claims)) === null, 'provider outage fails closed');
$beforeInvalid = $introspectionCalls;
$check($activeVerifier->verify($encode($claims, ['typ' => 'JWT'])) === null && $introspectionCalls === $beforeInvalid, 'wrong type never sends token to introspection');
$check($activeVerifier->verify(implode('.', $segments)) === null && $introspectionCalls === $beforeInvalid, 'invalid signature never sends token to introspection');
foreach ([null, [], array_replace($profile, ['required' => false]), array_replace($profile, ['secret_encrypted' => 'plaintext']),
    array_replace($profile, ['endpoint' => 'http://identity.example.test/oauth/introspect']),
    array_replace($profile, ['endpoint' => 'https://other.example.test/oauth/introspect']),
    array_replace($profile, ['endpoint' => 'https://identity.example.test:444/oauth/introspect']),
    array_replace($profile, ['endpoint' => 'https://user:pass@identity.example.test/oauth/introspect']),
    array_replace($profile, ['endpoint' => 'https://identity.example.test/oauth/introspect?token=x'])] as $badProfile) {
    $bad = new OAuthTokenVerifier($paths, array_replace($activeConfiguration, ['introspection' => $badProfile]), $files, null, $transport);
    $check($bad->verify($encode($claims)) === null && $introspectionCalls === $beforeInvalid, 'bad profile cannot downgrade or disclose token');
}
$files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'data']);
$files->writeJson($root . '/radpress/config/site.json', ['base_url' => 'https://press.example.test']);
$files->writeJson($root . '/radpress/config/users.json', ['users' => [['username' => 'owner', 'role' => 'owner']]]);
$files->writeJson($root . '/radpress/config/security.json', ['oauth' => array_replace($activeConfiguration, ['introspection' => []])]);
$config = \Batoi\Press\Core\Config::load($root);
$pat = (new \Batoi\Press\Security\AccessTokenRepository($config->paths()))->issue('Recovery fixture', ['site:read'], 'owner', new DateTimeImmutable('+1 hour'));
$patRequest = new \Batoi\Press\Core\Request('GET', '/api/v2/site', [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pat['token']]);
$patAccess = (new \Batoi\Press\Security\MachineAuthenticator($config))->authorize($patRequest, ['site:read']);
$check(($patAccess['oauth'] ?? false) === false && $patAccess['principal'] === 'owner', 'PAT recovery remains available with broken introspection profile');
$files->writeJson($root . '/radpress/config/security.json', ['oauth' => $activeConfiguration]);
$metadata = (new \Batoi\Press\Api\OAuthMetadataController(\Batoi\Press\Core\Config::load($root)))
    ->handle(new \Batoi\Press\Core\Request('GET', '/.well-known/oauth-protected-resource', [], [], []))->content();
$check(!str_contains($metadata, $profile['secret_encrypted']) && !str_contains($metadata, $profile['resource_id']) && !str_contains($metadata, $secret), 'public resource metadata excludes private introspection configuration');
echo "OAuth verifier hardening checks passed\n";
