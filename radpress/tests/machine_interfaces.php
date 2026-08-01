<?php
declare(strict_types=1);

use Batoi\Press\Api\ApiController;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Router;
use Batoi\Press\Core\Theme;
use Batoi\Press\Mcp\McpController;
use Batoi\Press\Security\AccessTokenRepository;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-machine-interfaces-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/content/pages/home', 'radpress/content/pages/private-notes', 'radpress/content/posts/launch', 'radpress/content/menus', 'radpress/data'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', [
        'name' => 'Machine Test Site', 'tagline' => 'Verified automation', 'base_url' => 'https://press.example.test', 'locale' => 'en', 'timezone' => 'UTC', 'theme' => 'default',
    ]);
    $files->writeJson($root . '/radpress/config/update.json', ['current_version' => '2.0.0-dev']);
    $files->writeJson($root . '/radpress/config/security.json', ['machine_allowed_origins' => ['https://client.example.test']]);
    writeContentFixture($files, $root . '/radpress/content/pages/home', [
        'id' => 'pg_home', 'type' => 'page', 'title' => 'Home', 'slug' => 'home', 'status' => 'published', 'updated_at' => '2026-08-01T10:00:00+00:00',
    ], '<h1>Machine-readable publishing</h1><p>Welcome to the site.</p>');
    writeContentFixture($files, $root . '/radpress/content/pages/private-notes', [
        'id' => 'pg_private', 'type' => 'page', 'title' => 'Private Notes', 'slug' => 'private-notes', 'status' => 'draft', 'updated_at' => '2026-08-01T11:00:00+00:00',
    ], '<p>Draft workflow notes.</p>');
    writeContentFixture($files, $root . '/radpress/content/posts/launch', [
        'id' => 'post_launch', 'type' => 'post', 'title' => 'Launch', 'slug' => 'launch', 'status' => 'published', 'published_at' => '2026-08-01T12:00:00+00:00', 'updated_at' => '2026-08-01T12:00:00+00:00',
    ], '<p>Launch details for Batoi Press.</p>');
    $files->writeJson($root . '/radpress/content/menus/main.json', [
        'schema_version' => 2, 'id' => 'menu_main', 'name' => 'Primary navigation', 'location' => 'primary', 'revision' => 3,
        'items' => [['id' => 'mi_home', 'type' => 'page', 'label' => 'Home', 'url' => '/', 'parent_id' => null, 'presentation' => 'link', 'column' => 1, 'target' => '_self', 'enabled' => true]],
    ]);

    $config = Config::load($root);
    $html = new HtmlContent();
    $pages = new PageRepository($config->paths(), $files, $html);
    $posts = new PostRepository($config->paths(), $files, $html);
    $tokens = new AccessTokenRepository($config->paths());
    $issued = $tokens->issue('Interface test', ['site:read', 'content:read'], 'owner', new DateTimeImmutable('+1 hour'));
    $token = (string)$issued['token'];
    $contentOnly = (string)$tokens->issue('Content only', ['content:read'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    $editorToken = (string)$tokens->issue('Automation editor', ['site:read', 'content:read', 'content:write', 'content:publish'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    $api = new ApiController($config, $pages, $posts);
    $router = new Router(new Theme($config->paths(), $config->site()), $pages, $posts, $config);

    $unauthorized = $api->handle(machineRequest('GET', '/api/v2/pages'));
    assertMachineInterface($unauthorized->status() === 401 && isset($unauthorized->headers()['WWW-Authenticate']), 'API should require bearer authentication');
    $forwardedAuthorization = $api->handle(new Request('GET', '/api/v2', [], [], ['REMOTE_ADDR' => '127.0.0.1', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $token]));
    assertMachineInterface($forwardedAuthorization->status() === 200, 'API should accept bearer authorization forwarded by common Apache/FastCGI configurations');

    $pagesResponse = $api->handle(machineRequest('GET', '/api/v2/pages', $token, ['limit' => '1'], '', ['HTTP_X_REQUEST_ID' => 'request_fixture_01']));
    $pagesPayload = decodeMachineResponse($pagesResponse);
    assertMachineInterface($pagesResponse->status() === 200, 'authorized page list should succeed');
    assertMachineInterface(count($pagesPayload['data']['data'] ?? []) === 1 && ($pagesPayload['data']['meta']['has_more'] ?? false) === true, 'page list should return bounded cursor pagination');
    assertMachineInterface(($pagesPayload['request_id'] ?? '') === 'request_fixture_01', 'valid caller correlation IDs should be preserved');

    $pageResponse = $api->handle(machineRequest('GET', '/api/v2/pages/pg_home', $token));
    $pagePayload = decodeMachineResponse($pageResponse);
    assertMachineInterface(($pagePayload['data']['id'] ?? '') === 'pg_home' && str_contains((string)($pagePayload['data']['body'] ?? ''), 'Machine-readable'), 'page detail should support stable IDs and include governed content');
    assertMachineInterface(isset($pageResponse->headers()['ETag']), 'resource reads should expose an ETag revision');
    assertMachineInterface($router->dispatch(machineRequest('GET', '/api/v2', $token))->status() === 200, 'main router should dispatch the versioned API before public content routes');

    $forbidden = $api->handle(machineRequest('GET', '/api/v2/site', $contentOnly));
    assertMachineInterface($forbidden->status() === 403, 'API should distinguish insufficient scope from invalid authentication');

    $createdResponse = $api->handle(machineRequest('POST', '/api/v2/pages', $editorToken, [], json_encode([
        'title' => 'API Draft', 'slug' => 'api-draft', 'body' => '<p>Created safely</p>',
    ], JSON_UNESCAPED_SLASHES), ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'api-create-0001']));
    $createdPayload = decodeMachineResponse($createdResponse);
    assertMachineInterface($createdResponse->status() === 201 && ($createdPayload['data']['resource']['status'] ?? '') === 'draft', 'API create should produce drafts through the mutation service');
    $createdRevision = (string)($createdPayload['data']['resource']['revision'] ?? '');
    $updatedResponse = $api->handle(machineRequest('PATCH', '/api/v2/pages/api-draft', $editorToken, [], json_encode([
        'title' => 'API Draft Revised',
    ], JSON_UNESCAPED_SLASHES), ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => '"' . $createdRevision . '"']));
    $updatedPayload = decodeMachineResponse($updatedResponse);
    assertMachineInterface($updatedResponse->status() === 200 && ($updatedPayload['data']['resource']['revision'] ?? '') !== $createdRevision, 'API draft update should require and advance the revision');
    $staleResponse = $api->handle(machineRequest('PATCH', '/api/v2/pages/api-draft', $editorToken, [], '{"title":"Stale"}', ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => $createdRevision]));
    assertMachineInterface($staleResponse->status() === 409 && (decodeMachineResponse($staleResponse)['error']['code'] ?? '') === 'revision_conflict', 'API should reject stale draft updates');
    $latestRevision = (string)($updatedPayload['data']['resource']['revision'] ?? '');
    $publishedResponse = $api->handle(machineRequest('POST', '/api/v2/pages/api-draft/publish', $editorToken, [], '{}', [
        'CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => $latestRevision, 'HTTP_IDEMPOTENCY_KEY' => 'api-publish-0001',
    ]));
    assertMachineInterface($publishedResponse->status() === 200 && (decodeMachineResponse($publishedResponse)['data']['resource']['status'] ?? '') === 'published', 'API publish should require its distinct scope and operation');
    $readTokenPublish = $api->handle(machineRequest('POST', '/api/v2/pages/private-notes/publish', $token, [], '{}', ['CONTENT_TYPE' => 'application/json']));
    assertMachineInterface($readTokenPublish->status() === 403, 'read-only API tokens must not invoke publish operations');

    $mcp = new McpController($config, $pages, $posts);
    $initialize = $mcp->handle(mcpRequest($token, 'initialize', ['protocolVersion' => McpController::PROTOCOL_VERSION, 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']], 1));
    $initializePayload = decodeMachineResponse($initialize);
    assertMachineInterface(($initializePayload['result']['protocolVersion'] ?? '') === McpController::PROTOCOL_VERSION, 'MCP should negotiate the current protocol version');
    assertMachineInterface(($initializePayload['result']['capabilities']['tools']['listChanged'] ?? null) === false, 'MCP should advertise stable tool capabilities');
    assertMachineInterface($router->dispatch(mcpRequest($token, 'ping', [], 99))->status() === 200, 'main router should dispatch the MCP endpoint');

    $toolList = decodeMachineResponse($mcp->handle(mcpRequest($token, 'tools/list', [], 2)));
    $toolNames = array_column((array)($toolList['result']['tools'] ?? []), 'name');
    assertMachineInterface(in_array('search', $toolNames, true) && in_array('menu_get', $toolNames, true), 'MCP should expose scoped content and site read tools');
    assertMachineInterface(!in_array('page_create_draft', $toolNames, true), 'MCP should omit write tools when the connection lacks write scope');

    $writeToolList = decodeMachineResponse($mcp->handle(mcpRequest($editorToken, 'tools/list', [], 21)));
    $writeTools = array_column((array)($writeToolList['result']['tools'] ?? []), 'name');
    assertMachineInterface(in_array('page_create_draft', $writeTools, true) && in_array('post_publish', $writeTools, true), 'MCP should expose draft and publish tools only for matching scopes');
    $mcpCreated = decodeMachineResponse($mcp->handle(mcpRequest($editorToken, 'tools/call', [
        'name' => 'post_create_draft',
        'arguments' => ['content' => ['title' => 'MCP Draft', 'slug' => 'mcp-draft', 'body' => '<p>Review me</p>'], 'idempotency_key' => 'mcp-create-0001'],
    ], 22)));
    assertMachineInterface(($mcpCreated['result']['structuredContent']['resource']['status'] ?? '') === 'draft', 'MCP create tools should return a governed draft result');

    $search = decodeMachineResponse($mcp->handle(mcpRequest($token, 'tools/call', ['name' => 'search', 'arguments' => ['query' => 'Launch']], 3)));
    $structured = (array)($search['result']['structuredContent'] ?? []);
    assertMachineInterface(($structured['results'][0]['id'] ?? '') === 'post_launch', 'MCP search should return stable content IDs');
    assertMachineInterface(($structured['results'][0]['url'] ?? '') === 'https://press.example.test/blog/launch', 'MCP search should return canonical citation URLs');
    assertMachineInterface(json_decode((string)($search['result']['content'][0]['text'] ?? ''), true) === $structured, 'MCP tool results should mirror structured content in the text content block');
    assertMachineInterface(!str_contains($search['result']['content'][0]['text'] ?? '', $token), 'MCP output must never contain bearer credentials');

    $notification = $mcp->handle(mcpRequest($token, 'notifications/initialized', [], null, false));
    assertMachineInterface($notification->status() === 202 && $notification->content() === '', 'MCP notifications should be accepted without a response body');

    $get = $mcp->handle(machineRequest('GET', '/mcp', $token, [], '', ['HTTP_ORIGIN' => 'https://client.example.test']));
    assertMachineInterface($get->status() === 405, 'stateless MCP should explicitly decline server-initiated SSE streams');
    $badOrigin = $mcp->handle(machineRequest('POST', '/mcp', $token, [], '{}', ['HTTP_ORIGIN' => 'https://evil.example.test', 'CONTENT_TYPE' => 'application/json']));
    assertMachineInterface($badOrigin->status() === 403, 'MCP should reject unapproved Origin headers');

    echo "Machine interface checks passed\n";
} finally {
    removeMachineInterfaceFixture($root);
}

function machineRequest(string $method, string $path, string $token = '', array $query = [], string $body = '', array $server = []): Request
{
    if ($token !== '') {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $server['REMOTE_ADDR'] ??= '127.0.0.1';
    return new Request($method, $path, $query, [], $server, $body);
}

function mcpRequest(string $token, string $method, array $params, mixed $id, bool $includeId = true): Request
{
    $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
    if ($includeId) {
        $message['id'] = $id;
    }
    return machineRequest('POST', '/mcp', $token, [], json_encode($message, JSON_UNESCAPED_SLASHES), [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json, text/event-stream',
        'HTTP_MCP_PROTOCOL_VERSION' => McpController::PROTOCOL_VERSION,
    ]);
}

function writeContentFixture(FileStore $files, string $directory, array $meta, string $body): void
{
    $files->writeJson($directory . '/meta.json', $meta);
    $files->write($directory . '/body.html', $body);
}

function decodeMachineResponse($response): array
{
    $decoded = json_decode($response->content(), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Expected a JSON machine response.');
    }
    return $decoded;
}

function assertMachineInterface(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeMachineInterfaceFixture(string $path): void
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
