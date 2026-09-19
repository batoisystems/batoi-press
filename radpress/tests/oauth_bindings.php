<?php
declare(strict_types=1);

use Batoi\Press\Admin\ConnectionController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\MachineAccessPolicy;
use Batoi\Press\Security\OAuthBindingRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-oauth-bindings-' . bin2hex(random_bytes(6));
mkdir($root . '/radpress/config', 0700, true);
try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['base_url' => 'https://press.example.test']);
    $files->writeJson($root . '/radpress/config/security.json', ['oauth' => ['enabled' => true, 'issuer' => 'https://identity.example.test', 'jwks' => ['keys' => []]]]);
    $owner = ['username' => 'owner', 'role' => 'owner', 'created_at' => '2020-01-01T00:00:00Z', 'password_hash' => Password::hash('BindingFixture!2026')];
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [$owner, ['username' => 'editor', 'role' => 'editor', 'created_at' => '2020-01-01T00:00:00Z']]]);
    $config = Config::load($root);
    $bindings = new OAuthBindingRepository($config->paths());
    $policy = new MachineAccessPolicy($config->paths());
    $first = $bindings->link('Client one', 'provider-subject', 'client-one', 'editor', ['content:read'], 'owner', 30);
    $second = $bindings->link('Client two', 'provider-subject', 'client-two', 'editor', ['content:read'], 'owner', 30);
    $raw = ['oauth' => true, 'issuer' => 'https://identity.example.test', 'subject' => 'provider-subject', 'client_id' => 'client-one', 'scopes' => ['content:read', 'content:write']];
    $resolved = $policy->resolve($raw);
    checkBinding(($resolved['id'] ?? '') === $first['id'] && $resolved['scopes'] === ['content:read'], 'OAuth resolves the exact client and local consent scopes');
    checkBinding($policy->resolve(array_replace($raw, ['client_id' => 'unknown'])) === null, 'unlinked clients cannot borrow the subject grant');
    checkBinding($bindings->revoke($first['id']), 'local grant can be revoked');
    checkBinding($policy->resolve($raw) === null && $policy->connection($second['id']) !== null, 'revocation is client-specific');
    $replacement = $bindings->link('Replacement', 'provider-subject', 'client-one', 'editor', ['content:read'], 'owner', 30);
    checkBinding($replacement['id'] !== $first['id'] && $policy->connection($first['id']) === null, 're-linking cannot revive old connection authority');
    checkBinding(count($bindings->all()) === 3, 'revoked consent history is retained');
    $bindingPath = $config->paths()->dataPath('integrations/oauth-bindings.json');
    $savedBindings = $files->readJson($bindingPath);
    $expiredBindings = $savedBindings;
    foreach ($expiredBindings['bindings'] as &$record) {
        if ($record['id'] === $replacement['id']) $record['expires_at'] = date(DATE_ATOM, time() - 60);
    }
    unset($record);
    $files->writeJson($bindingPath, $expiredBindings);
    checkBinding($policy->resolve($raw) === null && $policy->connection($replacement['id']) === null, 'expired consent denies requests and pending-proposal authority');
    checkBinding($policy->connection($second['id']) !== null, 'expiry remains client-specific');
    $files->writeJson($bindingPath, $savedBindings);
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [$owner, ['username' => 'editor', 'role' => 'editor', 'created_at' => '2021-01-01T00:00:00Z']]]);
    checkBinding($policy->connection($replacement['id']) === null, 'a replacement local account cannot inherit the old OAuth link');

    $session = new Session('press_binding_test', $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $controller = new ConnectionController($config, new AccessTokenRepository($config->paths()), $csrf, new AuditLog($config->paths(), $files), $owner);
    $input = ['name' => '<script>unsafe label</script>', 'subject' => 'owner-subject', 'client_id' => 'owner-client', 'principal' => 'owner', 'profile' => 'read', 'current_password' => 'BindingFixture!2026'];
    $denied = $controller->oauthChange(new Request('POST', '/admin/connections/oauth/link', [], $input, []), false);
    checkBinding($denied->status() === 400 && count($bindings->all()) === 3, 'linking requires CSRF');
    $input['csrf_token'] = $csrf->token();
    $wrongPassword = $controller->oauthChange(new Request('POST', '/admin/connections/oauth/link', [], array_replace($input, ['current_password' => 'IncorrectFixturePassword']), []), false);
    checkBinding($wrongPassword->status() === 403 && count($bindings->all()) === 3, 'valid CSRF does not replace owner password verification');
    $linked = $controller->oauthChange(new Request('POST', '/admin/connections/oauth/link', [], $input, []), false);
    checkBinding($linked->status() === 200 && count($bindings->all()) === 4, 'verified owner can link a local administrator through Connections');
    checkBinding(str_contains($linked->content(), '&lt;script&gt;unsafe label&lt;/script&gt;') && !str_contains($linked->content(), '<script>unsafe label'), 'connection names are escaped');
    checkBinding(($linked->headers()['Cache-Control'] ?? '') === 'private, no-store', 'OAuth control responses are private');
    $audit = $files->read($config->paths()->dataPath('log/audit.jsonl'));
    checkBinding(!str_contains($audit, 'BindingFixture!2026') && !str_contains($audit, 'owner-subject'), 'audit excludes passwords and raw provider subject');

    $securityPath = $config->paths()->configPath('security.json');
    $security = $files->readJson($securityPath);
    $configured = ['issuer' => 'https://identity.example.test', 'subject' => 'configured-subject', 'client_id' => 'configured-client', 'username' => 'owner', 'scopes' => ['content:read'], 'enabled' => true];
    $legacy = $configured;
    unset($legacy['client_id']);
    $security['oauth']['bindings'] = [$configured, $legacy];
    $files->writeJson($securityPath, $security);
    $configuredRaw = array_replace($raw, ['subject' => 'configured-subject', 'client_id' => 'configured-client']);
    $configuredAccess = $policy->resolve($configuredRaw);
    checkBinding($configuredAccess !== null, 'explicit configured client binding remains usable');
    $migration = array_values(array_filter($bindings->all(), static fn (array $record): bool => !empty($record['migration_required'])));
    checkBinding(count($migration) === 1 && !$migration[0]['enabled'], 'subject-only legacy grants are visible but disabled for migration');
    checkBinding($policy->resolve(array_replace($configuredRaw, ['client_id' => ''])) === null, 'legacy subject-only grants cannot authorize an unidentified client');
    checkBinding($bindings->revoke($configuredAccess['id']), 'configured grants can be revoked locally');
    checkBinding($policy->resolve($configuredRaw) === null && $policy->connection($configuredAccess['id']) === null, 'persisted revocation shadows the still-present configured grant');
    echo "OAuth binding checks passed\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}
function checkBinding(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
