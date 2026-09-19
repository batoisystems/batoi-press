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
foreach (['radpress/config', 'radpress/content/pages/home', 'radpress/content/pages/private-notes', 'radpress/content/posts/launch', 'radpress/content/menus', 'radpress/content/assets/images/2026/08', 'radpress/data'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [['username' => 'owner', 'role' => 'owner']]]);
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', [
        'name' => 'Machine Test Site', 'tagline' => 'Verified automation', 'base_url' => 'https://press.example.test', 'locale' => 'en', 'timezone' => 'UTC', 'theme' => 'default',
        'operator_private_note' => 'PRIVATE_SETTINGS_SECRET',
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
        'id' => 'post_launch', 'type' => 'post', 'title' => 'Launch', 'slug' => 'launch', 'status' => 'published', 'published_at' => '2026-08-01T12:00:00+00:00', 'updated_at' => '2026-08-01T12:00:00+00:00', 'category' => 'Product', 'tags' => ['Launch'],
    ], '<p>Launch details for Batoi Press.</p>');
    $files->writeJson($root . '/radpress/content/menus/main.json', [
        'schema_version' => 2, 'id' => 'menu_main', 'name' => 'Primary navigation', 'location' => 'primary', 'revision' => 3,
        'items' => [['id' => 'mi_home', 'type' => 'page', 'label' => 'Home', 'url' => '/', 'parent_id' => null, 'presentation' => 'link', 'column' => 1, 'target' => '_self', 'enabled' => true]],
    ]);
    $files->write($root . '/radpress/content/assets/images/2026/08/launch.png', "\x89PNG\r\n\x1a\nfixture");

    $config = Config::load($root);
    $html = new HtmlContent();
    $pages = new PageRepository($config->paths(), $files, $html);
    $posts = new PostRepository($config->paths(), $files, $html);
    $websiteChanges = new \Batoi\Press\Application\WebsiteChangeService(new \Batoi\Press\Content\MenuRepository($config->paths(), $files), $pages, $posts, $config);
    $menuPath = $config->paths()->contentPath('menus/main.json');
    $menuBytes = $files->read($menuPath);
    $menuItems = [['id' => 'mi_home', 'type' => 'page', 'label' => 'Home preview', 'url' => $pages->publicPath('home'), 'enabled' => true]];
    $menuPreview = $websiteChanges->prepareMenu('main', ['items' => $menuItems], 3, 'owner');
    assertMachineInterface($menuPreview['after']['items'][0]['label'] === 'Home preview' && $files->read($menuPath) === $menuBytes, 'website menu preparation validates without changing live navigation');
    assertMachineInterface($websiteChanges->prepareMenu('main', ['name' => 'Renamed navigation'], 3, 'owner')['after']['items'][0]['url'] === '/', 'the configured public homepage resolves at the site root');
    foreach ([
        ['items' => [array_replace($menuItems[0], ['url' => '/missing-page'])]],
        ['items' => [array_replace($menuItems[0], ['url' => $pages->publicPath('private-notes')])]],
        ['items' => [array_replace($menuItems[0], ['type' => 'link', 'url' => 'javascript:alert(1)'])]],
        ['items' => [array_replace($menuItems[0], ['type' => 'link', 'url' => 'https://user:secret@example.test/'])]],
        ['items' => [array_replace($menuItems[0], ['type' => 'link', 'url' => '/%2fevil.test'])]],
        ['items' => [array_replace($menuItems[0], ['enabled' => 'yes'])]],
        ['items' => [array_replace($menuItems[0], ['onclick' => 'alert(1)'])]],
        ['items' => [array_replace($menuItems[0], ['parent_id' => 'mi_home'])]],
        ['items' => [] , 'custom_js' => 'alert(1)'],
    ] as $invalidMenu) {
        try { $websiteChanges->prepareMenu('main', $invalidMenu, 3, 'owner'); throw new RuntimeException('Invalid machine menu accepted'); }
        catch (\Batoi\Press\Application\ContentMutationException $exception) { assertMachineInterface($exception->httpStatus() === 422, 'invalid menu changes are rejected'); }
    }
    assertMachineInterface($files->read($menuPath) === $menuBytes, 'invalid menu previews leave live bytes unchanged');
    $tokens = new AccessTokenRepository($config->paths());
    $issued = $tokens->issue('Interface test', ['site:read', 'content:read'], 'owner', new DateTimeImmutable('+1 hour'));
    $token = (string)$issued['token'];
    $contentOnly = (string)$tokens->issue('Content only', ['content:read'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    $editorToken = (string)$tokens->issue('Automation editor', ['site:read', 'content:read', 'content:write', 'content:publish'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    $api = new ApiController($config, $pages, $posts);
    $websiteToken = (string)$tokens->issue('Website manager', ['site:read', 'site:write'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    $menuChangeHeaders = ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => '3', 'HTTP_IDEMPOTENCY_KEY' => 'api-menu-change-1'];
    $menuDenied = $api->handle(machineRequest('POST', '/api/v2/menus/main/proposals', $token, [], '{"name":"Reviewed menu"}', $menuChangeHeaders));
    assertMachineInterface($menuDenied->status() === 403, 'read-only clients cannot propose navigation changes');
    $menuResponse = $api->handle(machineRequest('POST', '/api/v2/menus/main/proposals', $websiteToken, [], '{"name":"Reviewed menu"}', $menuChangeHeaders));
    $menuProposal = decodeMachineResponse($menuResponse)['data'];
    assertMachineInterface($menuResponse->status() === 202 && $menuProposal['state'] === 'pending' && $files->read($menuPath) === $menuBytes, 'API menu mutation only prepares approval');
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/menu-proposals/' . $menuProposal['id'], $websiteToken))->status() === 200, 'website client can read its proposal without content-read access');
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/menu-proposals/' . $menuProposal['id'], $token))->status() === 404, 'other connections cannot inspect a menu proposal');
    $initialWidgets = decodeMachineResponse($api->handle(machineRequest('GET', '/api/v2/widgets', $websiteToken)))['data'];
    $widgetHeaders = ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => $initialWidgets['revision'], 'HTTP_IDEMPOTENCY_KEY' => 'api-widgets-change-1'];
    $widgetPayload = json_encode(['widgets' => [['type' => 'tag_cloud', 'title' => 'Reviewed tags']]]);
    assertMachineInterface($api->handle(machineRequest('POST', '/api/v2/widgets/proposals', $token, [], $widgetPayload, $widgetHeaders))->status() === 403, 'read-only clients cannot propose widget changes');
    $widgetResponse = $api->handle(machineRequest('POST', '/api/v2/widgets/proposals', $websiteToken, [], $widgetPayload, $widgetHeaders));
    $widgetProposal = decodeMachineResponse($widgetResponse)['data'];
    assertMachineInterface($widgetResponse->status() === 202 && $widgetProposal['state'] === 'pending', 'API prepares a widget approval request');
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/widget-proposals/' . $widgetProposal['id'], $websiteToken))->status() === 200, 'website client reads its widget proposal without content scope');
    assertMachineInterface((new \Batoi\Press\Content\WidgetRepository($config->paths()))->load() === $initialWidgets, 'API widget proposal does not change live content');
    $settingsRead = $api->handle(machineRequest('GET', '/api/v2/public-settings', $websiteToken));
    $publicSettings = decodeMachineResponse($settingsRead)['data'];
    assertMachineInterface(!str_contains($settingsRead->content(), 'PRIVATE_SETTINGS_SECRET') && isset($publicSettings['capabilities']), 'public-settings reads project only safe fields and explicit theme capabilities');
    $settingsHeaders = ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => $publicSettings['revision'], 'HTTP_IDEMPOTENCY_KEY' => 'api-settings-change-1'];
    assertMachineInterface($api->handle(machineRequest('POST', '/api/v2/public-settings/proposals', $token, [], '{"name":"Proposed name"}', $settingsHeaders))->status() === 403, 'read-only settings access cannot propose changes');
    $settingsResponse = $api->handle(machineRequest('POST', '/api/v2/public-settings/proposals', $websiteToken, [], '{"name":"Proposed name"}', $settingsHeaders));
    $settingsProposal = decodeMachineResponse($settingsResponse)['data'];
    assertMachineInterface($settingsResponse->status() === 202 && $settingsProposal['state'] === 'pending' && $files->readJson($config->paths()->configPath('site.json'))['name'] === 'Machine Test Site', 'API public settings remain unchanged until approval');
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/settings-proposals/' . $settingsProposal['id'], $websiteToken))->status() === 200, 'settings proposals support connection-owned status');
    $auditToken = (string)$tokens->issue('Activity reviewer', ['audit:read'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/activity', $token))->status() === 403, 'site/content read does not authorize activity reports');
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/activity', $auditToken))->status() === 200, 'audit scope authorizes bounded reports');
    assertMachineInterface($api->handle(machineRequest('GET', '/api/v2/activity', $auditToken, ['limit' => 101]))->status() === 422, 'activity limits are enforced at runtime');
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
    $taxonomyPayload = decodeMachineResponse($api->handle(machineRequest('GET', '/api/v2/taxonomies', $contentOnly)));
    assertMachineInterface(($taxonomyPayload['data']['categories'][0]['slug'] ?? '') === 'product', 'API should expose shared taxonomy counts under content read scope');
    $mediaPayload = decodeMachineResponse($api->handle(machineRequest('GET', '/api/v2/media', $contentOnly)));
    $mediaRecord = (array)($mediaPayload['data']['data'][0] ?? []);
    assertMachineInterface(str_starts_with((string)($mediaRecord['id'] ?? ''), 'asset_') && ($mediaRecord['url'] ?? '') === 'https://press.example.test/assets/images/2026/08/launch.png', 'API should expose stable public media metadata');
    assertMachineInterface(!array_key_exists('path', $mediaRecord), 'machine media records must not expose filesystem paths');
    $healthPayload = decodeMachineResponse($api->handle(machineRequest('GET', '/api/v2/content-health', $contentOnly, ['id' => 'pg_private'])));
    assertMachineInterface(($healthPayload['data']['data'][0]['issues'][0]['code'] ?? '') === 'missing_seo_description', 'API should expose deterministic content-health findings');

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
    assertMachineInterface($publishedResponse->status() === 202 && (decodeMachineResponse($publishedResponse)['data']['state'] ?? '') === 'pending', 'API publish should create a review proposal rather than publish immediately');
    assertMachineInterface(($pages->findBySlug('api-draft')['status'] ?? '') === 'draft', 'publication requests leave current visibility unchanged');
    $readTokenPublish = $api->handle(machineRequest('POST', '/api/v2/pages/private-notes/publish', $token, [], '{}', ['CONTENT_TYPE' => 'application/json']));
    assertMachineInterface($readTokenPublish->status() === 403, 'read-only API tokens must not invoke publish operations');

    $mcp = new McpController($config, $pages, $posts);
    $initialize = $mcp->handle(mcpRequest($token, 'initialize', ['protocolVersion' => McpController::PROTOCOL_VERSION, 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']], 1));
    $initializePayload = decodeMachineResponse($initialize);
    assertMachineInterface(($initializePayload['result']['protocolVersion'] ?? '') === McpController::PROTOCOL_VERSION, 'MCP should negotiate the current protocol version');
    assertMachineInterface(($initializePayload['result']['capabilities']['tools']['listChanged'] ?? null) === false, 'MCP should advertise stable tool capabilities');
    assertMachineInterface($router->dispatch(mcpRequest($token, 'ping', [], 99))->status() === 200, 'main router should dispatch the MCP endpoint');
    $pingWire = json_decode($mcp->handle(mcpRequest($token, 'ping', [], 100))->content());
    assertMachineInterface($pingWire->result instanceof stdClass, 'MCP ping returns an empty result object, not a JSON array');

    // Decode as objects: associative decoding hides the important {} versus [] distinction.
    $allScopesToken = (string)$tokens->issue('Schema acceptance', ['site:read', 'site:write', 'content:read', 'content:write', 'content:publish', 'media:read', 'media:write', 'audit:read'], 'owner', new DateTimeImmutable('+1 hour'))['token'];
    $schemaWire = json_decode($mcp->handle(mcpRequest($allScopesToken, 'tools/list', [], 101))->content());
    $checkSchemaObjects = static function (mixed $node) use (&$checkSchemaObjects): void {
        if (!is_object($node) && !is_array($node)) return;
        if (is_object($node)) {
            foreach (['properties', '$defs', 'patternProperties'] as $keyword) {
                if (property_exists($node, $keyword)) assertMachineInterface($node->$keyword instanceof stdClass, 'JSON Schema ' . $keyword . ' must be an object on the wire');
            }
        }
        foreach ($node as $child) $checkSchemaObjects($child);
    };
    foreach ($schemaWire->result->tools as $tool) {
        $checkSchemaObjects($tool->inputSchema);
        $checkSchemaObjects($tool->outputSchema);
    }

    $toolList = decodeMachineResponse($mcp->handle(mcpRequest($token, 'tools/list', [], 2)));
    $websiteTools = decodeMachineResponse($mcp->handle(mcpRequest($websiteToken, 'tools/list', [], 210)));
    assertMachineInterface(in_array('menu_propose_changes', array_column($websiteTools['result']['tools'], 'name'), true), 'website-scoped clients discover menu proposals');
    assertMachineInterface(!in_array('menu_propose_changes', array_column($toolList['result']['tools'], 'name'), true), 'menu mutation discovery requires website write scope');
    $mcpMenu = decodeMachineResponse($mcp->handle(mcpRequest($websiteToken, 'tools/call', ['name' => 'menu_propose_changes', 'arguments' => ['key' => 'main', 'changes' => ['name' => 'MCP reviewed menu'], 'expected_revision' => 3, 'idempotency_key' => 'mcp-menu-change-1']], 211)));
    assertMachineInterface(($mcpMenu['result']['structuredContent']['state'] ?? '') === 'pending' && $files->read($menuPath) === $menuBytes, 'MCP navigation changes cannot publish without approval');
    assertMachineInterface(in_array('widgets_propose_changes', array_column($websiteTools['result']['tools'], 'name'), true) && !in_array('widgets_propose_changes', array_column($toolList['result']['tools'], 'name'), true), 'widget tools obey website-write discovery scope');
    $mcpWidget = decodeMachineResponse($mcp->handle(mcpRequest($websiteToken, 'tools/call', ['name' => 'widgets_propose_changes', 'arguments' => ['widgets' => [['type' => 'tag_cloud', 'title' => 'MCP tags']], 'expected_revision' => $initialWidgets['revision'], 'idempotency_key' => 'mcp-widget-change-1']], 212)));
    assertMachineInterface(($mcpWidget['result']['structuredContent']['state'] ?? '') === 'pending' && (new \Batoi\Press\Content\WidgetRepository($config->paths()))->load() === $initialWidgets, 'MCP widget changes require administrator approval');
    assertMachineInterface(in_array('public_settings_propose_changes', array_column($websiteTools['result']['tools'], 'name'), true) && !in_array('public_settings_propose_changes', array_column($toolList['result']['tools'], 'name'), true), 'public-settings tool discovery is scope filtered');
    $mcpSettings = decodeMachineResponse($mcp->handle(mcpRequest($websiteToken, 'tools/call', ['name' => 'public_settings_propose_changes', 'arguments' => ['changes' => ['tagline' => 'Proposed tagline'], 'expected_revision' => $publicSettings['revision'], 'idempotency_key' => 'mcp-settings-change-1']], 213)));
    assertMachineInterface(($mcpSettings['result']['structuredContent']['state'] ?? '') === 'pending' && !str_contains(json_encode($mcpSettings), 'PRIVATE_SETTINGS_SECRET'), 'MCP proposes public settings without exposing raw configuration');
    $auditTools = decodeMachineResponse($mcp->handle(mcpRequest($auditToken, 'tools/list', [], 201)));
    assertMachineInterface(in_array('activity_list', array_column($auditTools['result']['tools'], 'name'), true), 'audit tool is discoverable with audit scope');
    assertMachineInterface(!in_array('activity_list', array_column($toolList['result']['tools'], 'name'), true), 'audit tool is hidden without audit scope');
    $auditResult = decodeMachineResponse($mcp->handle(mcpRequest($auditToken, 'tools/call', ['name' => 'activity_list', 'arguments' => ['limit' => 1]], 202)));
    assertMachineInterface(isset($auditResult['result']['structuredContent']['data']), 'MCP returns structured bounded audit data');
    $auditDenied = decodeMachineResponse($mcp->handle(mcpRequest($token, 'tools/call', ['name' => 'activity_list', 'arguments' => []], 203)));
    assertMachineInterface(isset($auditDenied['error']), 'direct tool calls cannot bypass audit scope');
    $toolNames = array_column((array)($toolList['result']['tools'] ?? []), 'name');
    assertMachineInterface(in_array('search', $toolNames, true) && in_array('menu_get', $toolNames, true) && in_array('taxonomy_list', $toolNames, true) && in_array('media_get', $toolNames, true) && in_array('content_health_check', $toolNames, true), 'MCP should expose scoped content, media, taxonomy, health, and site read tools');
    assertMachineInterface(!in_array('page_create_draft', $toolNames, true), 'MCP should omit write tools when the connection lacks write scope');

    $writeToolList = decodeMachineResponse($mcp->handle(mcpRequest($editorToken, 'tools/list', [], 21)));
    $writeTools = array_column((array)($writeToolList['result']['tools'] ?? []), 'name');
    assertMachineInterface(in_array('page_create_draft', $writeTools, true) && in_array('post_publish', $writeTools, true), 'MCP should expose draft and publish tools only for matching scopes');
    $mcpCreated = decodeMachineResponse($mcp->handle(mcpRequest($editorToken, 'tools/call', [
        'name' => 'post_create_draft',
        'arguments' => ['content' => ['title' => 'MCP Draft', 'slug' => 'mcp-draft', 'body' => '<p>Review me</p>'], 'idempotency_key' => 'mcp-create-0001'],
    ], 22)));
    assertMachineInterface(($mcpCreated['result']['structuredContent']['resource']['status'] ?? '') === 'draft', 'MCP create tools should return a governed draft result');
    $mcpPublish = decodeMachineResponse($mcp->handle(mcpRequest($editorToken, 'tools/call', [
        'name' => 'post_publish', 'arguments' => ['id' => 'mcp-draft', 'expected_revision' => $mcpCreated['result']['structuredContent']['resource']['revision'], 'idempotency_key' => 'mcp-publish-review-1'],
    ], 23)));
    $mcpProposal = $mcpPublish['result']['structuredContent'] ?? [];
    assertMachineInterface(($mcpProposal['state'] ?? '') === 'pending' && ($posts->findBySlug('mcp-draft')['status'] ?? '') === 'draft', 'MCP publish cannot bypass administrator approval');
    $proposalStatus = decodeMachineResponse($mcp->handle(mcpRequest($editorToken, 'tools/call', ['name' => 'proposal_get', 'arguments' => ['id' => $mcpProposal['id']]], 24)));
    assertMachineInterface(($proposalStatus['result']['structuredContent']['approval_required'] ?? false) === true, 'originating client can poll its proposal status');
    $otherClientStatus = $api->handle(machineRequest('GET', '/api/v2/proposals/' . $mcpProposal['id'], $token));
    assertMachineInterface($otherClientStatus->status() === 404, 'a different connection cannot inspect a proposal by ID');
    $home = decodeMachineResponse($api->handle(machineRequest('GET', '/api/v2/pages/pg_home', $editorToken)));
    $apiProposal = $api->handle(machineRequest('POST', '/api/v2/pages/pg_home/proposals', $editorToken, [], '{"changes":{"title":"Proposed Home"}}', ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => $home['data']['revision'], 'HTTP_IDEMPOTENCY_KEY' => 'api-proposal-home-1']));
    assertMachineInterface($apiProposal->status() === 202 && ($pages->findBySlug('home')['title'] ?? '') === 'Home', 'API live edits create a proposal without changing the page');

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

    $files->write($config->paths()->contentPath('widgets/sidebar.json'), '{BROKEN_PRIVATE_STORAGE');
    $storageFailure = $api->handle(machineRequest('GET', '/api/v2/widgets', $websiteToken));
    assertMachineInterface($storageFailure->status() === 500 && !str_contains($storageFailure->content(), $root) && !str_contains($storageFailure->content(), 'BROKEN_PRIVATE_STORAGE'), 'API storage failures do not disclose paths or document bytes');
    $mcpStorageFailure = $mcp->handle(mcpRequest($websiteToken, 'tools/call', ['name' => 'widgets_get', 'arguments' => []], 250));
    assertMachineInterface($mcpStorageFailure->status() === 500 && !str_contains($mcpStorageFailure->content(), $root), 'MCP storage failures use safe error envelopes');
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
