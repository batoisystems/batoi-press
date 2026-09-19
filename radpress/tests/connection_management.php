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
use Batoi\Press\Security\MfaRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\Totp;

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
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [$owner, ['username' => 'editor', 'role' => 'editor']]]);
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
    assertConnection(str_contains($index->content(), 'bearer_token_env_var') && str_contains($index->content(), 'https://press.example.test/mcp'), 'client setup uses the canonical installation endpoint and secret reference');
    assertConnection(str_contains($index->content(), 'Read only (includes drafts)') && str_contains($index->content(), 'name="principal"'), 'onboarding defaults to least privilege and an explicit principal');
    assertConnection(str_contains($index->content(), 'Publisher (approval required)') && str_contains($index->content(), 'Cannot publish unattended.'), 'publishing consent accurately describes administrator approval');

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
    $badRotation = $controller->rotate(new Request('POST', '/admin/connections/rotate', [], [
        'csrf_token' => 'invalid', 'token_id' => $tokenId, 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($badRotation->status() === 400 && $tokens->authenticate($plainToken) !== null, 'rotation requires CSRF without invalidating the existing token');
    $rotated = $controller->rotate(new Request('POST', '/admin/connections/rotate', [], [
        'csrf_token' => $csrf->token(), 'token_id' => $tokenId, 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    preg_match('/bp2_[a-f0-9]{24}_[A-Za-z0-9_-]{43}/', $rotated->content(), $rotationMatches);
    $rotatedToken = $rotationMatches[0] ?? '';
    assertConnection($rotated->status() === 200 && $rotatedToken !== '' && $rotatedToken !== $plainToken, 'rotation shows a new secret once');
    assertConnection($tokens->authenticate($plainToken) === null && $tokens->authenticate($rotatedToken) !== null, 'rotation invalidates the previous secret immediately');
    assertConnection(($rotated->headers()['Cache-Control'] ?? '') === 'private, no-store', 'rotated secret must not be cached');
    $tokens->markUsed($tokenId);
    assertConnection(($tokens->authenticate($rotatedToken)['last_used_at'] ?? '') !== '', 'successful use metadata is recorded');
    $revoked = $controller->revoke(new Request('POST', '/admin/connections/revoke', [], [
        'csrf_token' => $csrf->token(), 'token_id' => $tokenId, 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($revoked->status() === 302, 'verified owners should be able to revoke active tokens');
    assertConnection($tokens->authenticate($plainToken) === null, 'revoked connection tokens should stop authenticating immediately');
    assertConnection($tokens->rotate($tokenId) === null, 'rotation cannot reactivate a revoked token');

    $delegated = $controller->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(), 'name' => 'Editor client', 'principal' => 'editor',
        'scopes' => ['content:read', 'content:write'], 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    preg_match('/bp2_[a-f0-9]{24}_[A-Za-z0-9_-]{43}/', $delegated->content(), $delegatedMatches);
    $delegatedAccess = $tokens->authenticate($delegatedMatches[0] ?? '');
    assertConnection($delegated->status() === 200 && ($delegatedAccess['principal_username'] ?? '') === 'editor' && ($delegatedAccess['issued_by'] ?? '') === 'owner', 'delegation retains distinct principal and issuer');
    $forbiddenGrant = $controller->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(), 'name' => 'Excess grant', 'principal' => 'editor',
        'scopes' => ['audit:read'], 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($forbiddenGrant->status() === 422, 'owner cannot delegate scopes beyond the target role');
    $readProfile = $controller->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(), 'name' => 'Read profile', 'profile' => 'read',
        'scopes' => ['content:publish'], 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    preg_match('/bp2_[a-f0-9]{24}_[A-Za-z0-9_-]{43}/', $readProfile->content(), $profileMatches);
    assertConnection(($tokens->authenticate($profileMatches[0] ?? '')['scopes'] ?? []) === ['content:read', 'site:read'], 'read profile never inherits hidden custom publish grants');

    assertConnection(($delegatedAccess['legacy_identity'] ?? true) === false, 'new credentials report an explicit account binding');
    $legacy = $tokens->issue('Legacy <client>', ['content:read'], 'owner', new DateTimeImmutable('+1 hour'));
    $tokenStorePath = $config->paths()->dataPath('integrations/access-tokens.json');
    $storedTokens = $files->readJson($tokenStorePath);
    foreach ($storedTokens['tokens'] as &$record) {
        if ($record['id'] === $legacy['access']['id']) unset($record['principal_username'], $record['principal_created_at']);
    }
    unset($record);
    $files->writeJson($tokenStorePath, $storedTokens);
    $legacyAccess = $tokens->authenticate($legacy['token']);
    assertConnection(($legacyAccess['legacy_identity'] ?? false) === true && $legacyAccess['principal_username'] === 'owner', 'legacy report retains issuer mapping without inventing a binding');
    $beforeReport = $files->read($tokenStorePath);
    $migrationReport = $controller->index()->content();
    assertConnection(str_contains($migrationReport, '1 active legacy credential(s)') && str_contains($migrationReport, 'Legacy issuer mapping') && str_contains($migrationReport, 'Account-bound identity'), 'owners can distinguish legacy and account-bound credentials');
    assertConnection(str_contains($migrationReport, 'Legacy &lt;client&gt;') && !str_contains($migrationReport, $legacy['token']), 'migration report escapes labels and never reveals credential secrets');
    assertConnection($files->read($tokenStorePath) === $beforeReport, 'viewing migration status does not change stored identities or grants');
    $legacyRotated = $tokens->rotate($legacy['access']['id']);
    assertConnection(($legacyRotated['access']['legacy_identity'] ?? false) === true, 'secret rotation must not silently migrate identity');
    $tokens->revoke($legacy['access']['id']);
    assertConnection(!str_contains($controller->index()->content(), 'active legacy credential(s)'), 'revoked legacy metadata is retained without prompting active migration');

    $files->writeJson($config->paths()->configPath('users.json'), ['users' => [$owner]]);
    $mfaSecret = Totp::generateSecret();
    (new MfaRepository($config->paths(), $files))->enable('owner', $mfaSecret, Totp::recoveryCodes(6));
    $mfaOwner = (array)(new MfaRepository($config->paths(), $files))->findUser('owner');
    $mfaController = new ConnectionController($config, $tokens, $csrf, new AuditLog($config->paths(), $files), $mfaOwner);
    $missingMfa = $mfaController->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(), 'name' => 'Missing step-up', 'scopes' => ['content:read'], 'expires_days' => '7', 'current_password' => $password,
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($missingMfa->status() === 403, 'MFA-enabled owners should need a second factor for connection changes');
    $withMfa = $mfaController->issue(new Request('POST', '/admin/connections/issue', [], [
        'csrf_token' => $csrf->token(), 'name' => 'MFA protected client', 'scopes' => ['content:read'], 'expires_days' => '7', 'current_password' => $password, 'mfa_code' => Totp::currentCode($mfaSecret),
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertConnection($withMfa->status() === 200 && str_contains($withMfa->content(), 'MFA protected client'), 'valid MFA step-up should allow a machine credential change');

    $audit = (string)file_get_contents($config->paths()->dataPath('log/audit.jsonl'));
    assertConnection(str_contains($audit, 'machine.token.issued') && str_contains($audit, 'machine.token.revoked'), 'token lifecycle changes should be audited');
    assertConnection(!str_contains($audit, $plainToken), 'audit log should not contain plaintext machine credentials');
    assertConnection(!str_contains($audit, $rotatedToken), 'audit log should not contain rotated credentials');

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
