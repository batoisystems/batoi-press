<?php
declare(strict_types=1);

// Exercise production transport options without contacting an external service.
namespace Batoi\Press\Security {
    final class IntrospectionTransportFixture
    {
        public static array $options = [];
        public static string $body = '';
        public static int $status = 200;
        public static bool $initialize = true;
        public static bool $configure = true;
        public static bool $execute = true;
        public static bool $throw = false;
        public static int $closed = 0;
        public static int $executed = 0;
    }
    function curl_init(string $url) { return IntrospectionTransportFixture::$initialize ? new \stdClass() : false; }
    function curl_setopt_array($handle, array $options): bool { IntrospectionTransportFixture::$options = $options; return IntrospectionTransportFixture::$configure; }
    function curl_exec($handle): bool {
        ++IntrospectionTransportFixture::$executed;
        if (IntrospectionTransportFixture::$throw) throw new \RuntimeException('Synthetic transport failure');
        if (!IntrospectionTransportFixture::$execute) return false;
        $body = IntrospectionTransportFixture::$body;
        return (IntrospectionTransportFixture::$options[CURLOPT_WRITEFUNCTION])($handle, $body) === strlen($body);
    }
    function curl_getinfo($handle, int $option): int { return IntrospectionTransportFixture::$status; }
    function curl_close($handle): void { ++IntrospectionTransportFixture::$closed; }
}
namespace {
    use Batoi\Press\Core\Paths;
    use Batoi\Press\Security\IntrospectionTransportFixture as Fixture;
    use Batoi\Press\Security\OAuthIntrospectionClient;
    use Batoi\Press\Security\SecretStore;
    require dirname(__DIR__) . '/autoload.php';
    if (!function_exists('curl_init')) throw new RuntimeException('Transport checks require PHP cURL constants.');
    $root = sys_get_temp_dir() . '/press-introspection-transport-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    try {
        $paths = new Paths($root, ['data' => 'data']);
        $claims = ['iss' => 'https://identity.example.test', 'aud' => 'https://press.example.test/mcp', 'sub' => 'fixture-owner',
            'client_id' => 'fixture-client', 'jti' => 'fixture-token', 'iat' => time(), 'exp' => time() + 60, 'scope' => 'site:read'];
        $configuration = ['issuer' => $claims['iss'], 'introspection' => ['required' => true,
            'endpoint' => $claims['iss'] . '/oauth/introspect', 'resource_id' => 'fixture-resource',
            'secret_encrypted' => (new SecretStore($paths))->encrypt('synthetic-private-credential')]];
        $client = new OAuthIntrospectionClient($paths, $configuration);
        $check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
        Fixture::$body = json_encode(['active' => true, 'token_type' => 'Bearer'] + $claims);
        $check($client->active('synthetic-access-token', $claims), 'matching production transport response accepted');
        $options = Fixture::$options;
        $check($options[CURLOPT_POST] === true && $options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0, 'POST only, redirects disabled');
        $check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2 && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'verified HTTPS only');
        $check($options[CURLOPT_CONNECTTIMEOUT] === 3 && $options[CURLOPT_TIMEOUT] === 6, 'transport time bounded');
        parse_str($options[CURLOPT_POSTFIELDS], $body);
        $check($body === ['token' => 'synthetic-access-token', 'token_type_hint' => 'access_token'], 'token only in expected form body');
        foreach ([302, 400, 401, 403, 429, 500] as $status) {
            Fixture::$status = $status;
            $check(!$client->active('synthetic-access-token', $claims), 'HTTP failure never grants access');
        }
        Fixture::$status = 200;
        foreach (['{invalid', 'null', '[]', str_repeat('x', 32769)] as $response) {
            Fixture::$body = $response;
            $check(!$client->active('synthetic-access-token', $claims), 'malformed or oversized body rejected');
        }
        Fixture::$body = json_encode(['active' => true, 'token_type' => 'Bearer'] + $claims);
        foreach (['initialize', 'configure', 'execute'] as $failure) {
            $closed = Fixture::$closed;
            Fixture::${$failure} = false;
            $check(!$client->active('synthetic-access-token', $claims), 'transport setup/execution fails closed');
            if ($failure !== 'initialize') $check(Fixture::$closed === $closed + 1, 'initialized failed transport closed');
            Fixture::${$failure} = true;
        }
        $closed = Fixture::$closed; Fixture::$throw = true;
        $check(!$client->active('synthetic-access-token', $claims) && Fixture::$closed === $closed + 1, 'exception fails closed and closes transport');
        Fixture::$throw = false;
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $private)) throw new RuntimeException('RSA fixture unavailable');
        $details = openssl_pkey_get_details($key);
        $b64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $files = new \Batoi\Press\Core\FileStore();
        $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'data']);
        $files->writeJson($root . '/radpress/config/site.json', ['base_url' => 'https://press.example.test']);
        $files->writeJson($root . '/radpress/config/users.json', ['users' => [['username' => 'owner', 'role' => 'owner']]]);
        $files->writeJson($root . '/radpress/config/security.json', ['oauth' => $configuration + [
            'enabled' => true, 'resource' => $claims['aud'], 'jwks' => ['keys' => [[
                'kid' => 'transport-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
                'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
            ]]],
        ]]);
        $config = \Batoi\Press\Core\Config::load($root);
        (new \Batoi\Press\Security\OAuthBindingRepository($config->paths()))->link('Fixture', $claims['sub'], $claims['client_id'], 'owner', ['site:read'], 'owner', 30);
        $jwt = \Firebase\JWT\JWT::encode($claims, $private, 'RS256', 'transport-key', ['typ' => 'at+jwt']);
        $request = static fn (string $token): \Batoi\Press\Core\Request => new \Batoi\Press\Core\Request('GET', '/api/v2/site', [], [], ['REMOTE_ADDR' => '127.0.0.7', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $authenticator = new \Batoi\Press\Security\MachineAuthenticator($config);
        Fixture::$status = 500;
        for ($attempt = 0; $attempt < 12; ++$attempt) {
            try {
                $authenticator->authorize($request($jwt), ['site:read']);
                throw new RuntimeException('Unavailable provider authorized a request');
            } catch (\Batoi\Press\Security\MachineAccessException $error) {
                $check($error->status() === 503 && ($error->headers()['Retry-After'] ?? '') === '30', 'outage is unavailable, not bad credential/rate limit');
            }
        }
        $pat = (new \Batoi\Press\Security\AccessTokenRepository($config->paths()))->issue('Recovery fixture', ['site:read'], 'owner', new \DateTimeImmutable('+1 hour'));
        $check($authenticator->authorize($request($pat['token']), ['site:read'])['principal'] === 'owner', 'same-IP PAT recovery after repeated provider outages');
        Fixture::$status = 200;
        Fixture::$body = json_encode(['active' => false]);
        try {
            $authenticator->authorize($request($jwt), ['site:read']);
            throw new RuntimeException('Inactive provider token accepted');
        } catch (\Batoi\Press\Security\MachineAccessException $error) { $check($error->status() === 401, 'inactive token remains unauthorized'); }
        Fixture::$body = json_encode(['active' => true, 'token_type' => 'Bearer'] + $claims);
        $check($authenticator->authorize($request($jwt), ['site:read'])['principal'] === 'owner', 'recovered provider still requires exact local binding');
        $parts = explode('.', $jwt); $parts[1] = $b64(json_encode(array_replace($claims, ['sub' => 'forged'])));
        $beforeInvalid = Fixture::$executed;
        try {
            $authenticator->authorize($request(implode('.', $parts)), ['site:read']);
            throw new RuntimeException('Forged JWT accepted');
        } catch (\Batoi\Press\Security\MachineAccessException $error) {
            $check($error->status() === 401 && Fixture::$executed === $beforeInvalid, 'invalid signature stays unauthorized without provider request');
        }
        $identity = hash('sha256', json_encode([$claims['iss'], 'fixture-resource', $claims['client_id'], $claims['sub']], JSON_THROW_ON_ERROR));
        $capacityPath = $paths->dataPath('integrations/oauth-introspection-limits/' . $identity . '.json');
        $files->writeJson($capacityPath, array_fill(0, 240, time()));
        $beforeQuota = Fixture::$executed;
        try {
            $authenticator->authorize($request($jwt), ['site:read']);
            throw new RuntimeException('Introspection quota bypassed');
        } catch (\Batoi\Press\Security\MachineAccessException $error) {
            $check($error->status() === 503 && Fixture::$executed === $beforeQuota, 'provider budget fails closed before network work');
        }
        $rotatedJti = \Firebase\JWT\JWT::encode(array_replace($claims, ['jti' => 'new-token']), $private, 'RS256', 'transport-key', ['typ' => 'at+jwt']);
        try {
            $authenticator->authorize($request($rotatedJti), ['site:read']);
            throw new RuntimeException('New token bypassed provider quota');
        } catch (\Batoi\Press\Security\MachineAccessException $error) { $check($error->status() === 503 && Fixture::$executed === $beforeQuota, 'JTI rotation does not bypass provider quota'); }
        $check($authenticator->authorize($request($pat['token']), ['site:read'])['principal'] === 'owner', 'provider quota leaves PAT recovery available');
        $files->write($capacityPath, '{invalid');
        $check(!$client->active('synthetic-access-token', $claims) && Fixture::$executed === $beforeQuota, 'corrupt capacity state fails closed before network');
        $files->writeJson($capacityPath, []);
        $lock = fopen($paths->dataPath('integrations/oauth-introspection-limits/' . $identity . '.lock'), 'c');
        flock($lock, LOCK_EX);
        $check(!$client->active('synthetic-access-token', $claims) && Fixture::$executed === $beforeQuota, 'concurrent capacity lock fails closed before network');
        flock($lock, LOCK_UN); fclose($lock);
        $lockPath = $paths->dataPath('integrations/oauth-introspection-limits/' . $identity . '.lock');
        unlink($lockPath); mkdir($lockPath, 0700);
        $check(!$client->active('synthetic-access-token', $claims) && Fixture::$executed === $beforeQuota, 'unavailable lock storage fails closed without transport');
        rmdir($lockPath);
        echo "OAuth introspection transport checks passed\n";
    } finally {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
        rmdir($root);
    }
}
