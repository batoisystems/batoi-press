<?php
declare(strict_types=1);

// Isolated test fixture only. Never point this router at an installation's data.
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Request;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Mcp\McpController;
use Batoi\Press\Security\AccessTokenRepository;

require dirname(__DIR__, 2) . '/autoload.php';

$root = PHP_SAPI === 'cli' ? (string)($argv[2] ?? '') : (string)getenv('PRESS_MCP_FIXTURE_ROOT');
if (!is_dir($root) || is_link($root) || !str_starts_with(basename($root), 'press-mcp-http-')) {
    throw new RuntimeException('An isolated generated fixture directory is required.');
}
$files = new FileStore();
$marker = $root . '/fixture.json';
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'setup') {
    if (is_file($marker) || is_dir($root . '/radpress')) throw new RuntimeException('Fixture must be empty.');
    $base = (string)($argv[3] ?? '');
    if (!preg_match('#^http://127\.0\.0\.1:[0-9]+(?:/testsite/public_html)?$#D', $base)) throw new RuntimeException('Loopback fixture URL required.');
    umask(0077);
    $files->writeJson($marker, ['base_url' => $base]);
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [['username' => 'fixture-owner', 'role' => 'owner', 'created_at' => '2020-01-01T00:00:00+00:00']]]);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Isolated MCP acceptance', 'base_url' => $base, 'timezone' => 'UTC', 'locale' => 'en', 'theme' => 'default']);
    $files->writeJson($root . '/radpress/config/update.json', ['current_version' => 'acceptance-worktree']);
    $files->writeJson($root . '/radpress/content/pages/home/meta.json', ['id' => 'pg_acceptance_home', 'title' => 'Fixture Home', 'slug' => 'home', 'type' => 'page', 'status' => 'published']);
    $files->write($root . '/radpress/content/pages/home/body.html', '<p>Public fixture content.</p>');
    $files->writeJson($root . '/radpress/content/menus/main.json', ['schema_version' => 2, 'id' => 'menu_fixture', 'name' => 'Fixture menu', 'location' => 'primary', 'revision' => 1, 'items' => [['id' => 'mi_home', 'type' => 'page', 'label' => 'Home', 'url' => '/', 'enabled' => true]]]);
    $config = Config::load($root);
    $tokens = new AccessTokenRepository($config->paths());
    $result = [];
    foreach (['reader' => ['site:read', 'content:read'], 'editor' => ['site:read', 'content:read', 'content:write', 'content:publish'], 'manager' => ['site:read', 'site:write', 'media:read', 'media:write', 'audit:read']] as $name => $scopes) {
        $issued = $tokens->issue('Synthetic ' . $name, $scopes, 'fixture-owner', new DateTimeImmutable('+15 minutes'));
        $result[$name] = ['token' => $issued['token'], 'id' => $issued['access']['id']];
    }
    // Runner captures this pipe in memory; do not log or store its output.
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($marker)) throw new RuntimeException('Missing fixture marker.');
$config = Config::load($root);
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'revoke') throw new RuntimeException('Unknown fixture action.');
    if (!(new AccessTokenRepository($config->paths()))->revoke((string)($argv[3] ?? ''))) throw new RuntimeException('Fixture revocation failed.');
    exit;
}
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit;
}
$prefix = (string)(parse_url($files->readJson($marker)['base_url'], PHP_URL_PATH) ?? '');
$urlPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($urlPath === $prefix . '/fixture-ready') { header('Content-Type: text/plain'); echo 'ready'; exit; }
if ($urlPath !== $prefix . '/mcp') { http_response_code(404); exit; }
// Model the front controller's URL context while executing the worktree code.
$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/web' . $prefix . '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $root . '/web';
$html = new HtmlContent();
$controller = new McpController($config, new PageRepository($config->paths(), $files, $html), new PostRepository($config->paths(), $files, $html));
$controller->handle(Request::fromGlobals())->send();
