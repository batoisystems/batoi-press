<?php
declare(strict_types=1);

use Batoi\Press\Admin\ConnectionController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';

$root = sys_get_temp_dir() . '/batoi-press-connections-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/data/sessions', 'radpress/data/log'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Connection Test', 'base_url' => 'https://press.example.test']);
    $password = 'OwnerPassword!2026';
    $owner = ['username' => 'owner', 'role' => 'owner', 'password_hash' => Password::hash($password)];
    $config = Config::load($root);
    $session = new Session('batoi_press_connection_test', $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $tokens = new AccessTokenRepository($config->paths(), $files);
    $controller = new ConnectionController($config, $tokens, $csrf, new AuditLog($config->paths(), $files), $owner);

    assertConnection(AdminAccess::canAccess($owner, '/admin/connections'), 'owners should manage machine connections');
    assertConnection(!AdminAccess::canAccess(['username' => 'admin', 'role' => 'admin'], '/admin/connections'), 'administrators should not manage owner-only machine connections');
    assertConnection(!AdminAccess::canSeeNav(['username' => 'editor', 'role' => 'editor'], '/admin/connections'), 'non-owners should not see connection navigation');

    $index = $controller->index();
    assertConnection($index->status() === 200 && str_contains($index->content(), 'Issue connection token'), 'connection manager should render issue and governance controls');
    assertConnection(($index->headers()['Cache-Control'] ?? '') === 'private, no-store', 'connection pages should not be cached');

    $bad = $controller->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(), 'name' => 'Bad attempt', 'scopes' => ['site:read'], 'expires_days' => '30', 'current_password' => 'incorrect',
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($bad->status() === 403 && $tokens->all() === [], 'failed reauthentication should not issue a token');

    $issued = $controller->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(),
        'name' => 'Claude editorial review',
        'scopes' => ['site:read', 'content:read', 'content:write'],
        'expires_days' => '30',
        'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    preg_match('/bp2_[a-f0-9]{24}_[A-Za-z0-9_-]{43}/', $issued->content(), $matches);
    $plainToken = (string)($matches[0] ?? '');
    assertConnection($issued->status() === 200 && $plainToken !== '', 'successful issuance should display the token exactly once');
    assertConnection(($issued->headers()['Cache-Control'] ?? '') === 'private, no-store', 'one-time token response should disable caching');
    assertConnection(($tokens->all()[0]['scopes'] ?? []) === ['content:read', 'content:write', 'site:read'], 'connection UI should issue governed draft-write scopes after rollout gates pass');
    assertConnection($tokens->authenticate($plainToken, ['content:read']) !== null, 'issued one-time token should authenticate for its granted scope');
    assertConnection(!str_contains($controller->index()->content(), $plainToken), 'subsequent connection views should not reveal the plaintext token');

    $tokenId = (string)($tokens->all()[0]['id'] ?? '');
    $revoked = $controller->revoke(new Request('POST', '/admin/connections/revoke', [], [
        'csrf_token' => $csrf->token(), 'token_id' => $tokenId, 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($revoked->status() === 302, 'verified owners should be able to revoke active tokens');
    assertConnection($tokens->authenticate($plainToken) === null, 'revoked connection tokens should stop authenticating immediately');

    $audit = (string)file_get_contents($config->paths()->dataPath('log/audit.jsonl'));
    assertConnection(str_contains($audit, 'machine.token.issued') && str_contains($audit, 'machine.token.revoked'), 'token lifecycle changes should be audited');
    assertConnection(!str_contains($audit, $plainToken), 'audit log should not contain plaintext machine credentials');

    echo "Connection management checks passed\n";
} finally {
    removeConnectionFixture($root);
}

function assertConnection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeConnectionFixture(string $path): void
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
