<?php
declare(strict_types=1);

use Batoi\Press\Api\OAuthMetadataController;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Mcp\McpController;
use Batoi\Press\Security\MachineAccessException;
use Batoi\Press\Security\MachineAuthenticator;
use Firebase\JWT\JWT;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-oauth-resource-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/content/pages', 'radpress/content/posts', 'radpress/content/menus', 'radpress/data'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    assertOAuth($key !== false, 'OAuth test requires RSA key generation');
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    assertOAuth(is_array($details) && isset($details['rsa']['n'], $details['rsa']['e']), 'RSA public key details should be available');
    $jwks = ['keys' => [[
        'kty' => 'RSA',
        'kid' => 'oauth-test-key',
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => base64UrlOAuth($details['rsa']['n']),
        'e' => base64UrlOAuth($details['rsa']['e']),
    ]]];

    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'OAuth Test', 'base_url' => 'https://press.example.test']);
    $files->writeJson($root . '/radpress/config/security.json', ['oauth' => [
        'enabled' => true,
        'issuer' => 'https://identity.example.test',
        'resource' => 'https://press.example.test/mcp',
        'authorization_servers' => ['https://identity.example.test'],
        'scopes_supported' => ['site:read', 'content:read'],
        'jwks' => $jwks,
    ]]);
    $config = Config::load($root);
    $now = time();
    $claims = [
        'iss' => 'https://identity.example.test',
        'sub' => 'owner@example.test',
        'aud' => 'https://press.example.test/mcp',
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 300,
        'scope' => 'site:read content:read unknown:scope',
    ];
    $jwt = JWT::encode($claims, $privateKey, 'RS256', 'oauth-test-key');
    $request = new Request('GET', '/api/v2/site', [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
    $access = (new MachineAuthenticator($config))->authorize($request, ['site:read']);
    assertOAuth(($access['oauth'] ?? false) === true, 'valid issuer/audience JWT should authenticate as OAuth');
    assertOAuth(($access['scopes'] ?? []) === ['content:read', 'site:read'], 'OAuth scopes should be allowlisted and normalized');
    assertOAuth(!str_contains(json_encode($access), 'owner@example.test'), 'resolved OAuth access metadata should not expose the raw subject');

    $wrongAudience = $claims;
    $wrongAudience['aud'] = 'https://another.example.test/mcp';
    assertOAuthThrows(static fn () => (new MachineAuthenticator($config))->authorize(
        new Request('GET', '/api/v2/site', [], [], ['REMOTE_ADDR' => '127.0.0.2', 'HTTP_AUTHORIZATION' => 'Bearer ' . JWT::encode($wrongAudience, $privateKey, 'RS256', 'oauth-test-key')]),
        ['site:read']
    ), 'OAuth tokens for another resource should fail closed');

    $missingTokenException = null;
    try {
        (new MachineAuthenticator($config))->authorize(new Request('GET', '/mcp', [], [], ['REMOTE_ADDR' => '127.0.0.3']), ['site:read']);
    } catch (MachineAccessException $exception) {
        $missingTokenException = $exception;
    }
    assertOAuth($missingTokenException instanceof MachineAccessException, 'missing OAuth token should return an authorization challenge');
    assertOAuth(str_contains((string)($missingTokenException->headers()['WWW-Authenticate'] ?? ''), 'resource_metadata="https://press.example.test/.well-known/oauth-protected-resource"'), 'OAuth challenge should advertise protected resource metadata');

    $metadata = (new OAuthMetadataController($config))->handle(new Request('GET', '/.well-known/oauth-protected-resource', [], [], []));
    $metadataPayload = json_decode($metadata->content(), true);
    assertOAuth($metadata->status() === 200 && ($metadataPayload['resource'] ?? '') === 'https://press.example.test/mcp', 'protected resource metadata should publish the exact MCP audience');
    assertOAuth(($metadataPayload['authorization_servers'][0] ?? '') === 'https://identity.example.test', 'protected resource metadata should publish the configured authorization server');

    $html = new HtmlContent();
    $mcp = new McpController($config, new PageRepository($config->paths(), $files, $html), new PostRepository($config->paths(), $files, $html));
    $toolListRequest = new Request('POST', '/mcp', [], [], [
        'REMOTE_ADDR' => '127.0.0.4',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json, text/event-stream',
        'HTTP_MCP_PROTOCOL_VERSION' => McpController::PROTOCOL_VERSION,
    ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]));
    $toolList = json_decode($mcp->handle($toolListRequest)->content(), true);
    $tools = (array)($toolList['result']['tools'] ?? []);
    assertOAuth(($tools[0]['securitySchemes'][0]['type'] ?? '') === 'oauth2', 'MCP tools should declare their OAuth security scheme when OAuth is enabled');

    echo "OAuth resource server checks passed\n";
} finally {
    removeOAuthFixture($root);
}

function base64UrlOAuth(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function assertOAuth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertOAuthThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (MachineAccessException $exception) {
        if ($exception->status() === 401) {
            return;
        }
    }
    throw new RuntimeException($message);
}

function removeOAuthFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
