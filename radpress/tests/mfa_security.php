<?php
declare(strict_types=1);

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Security\Auth;
use Batoi\Press\Security\MfaRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\SecretStore;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\Totp;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-mfa-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/data/sessions'] as $directory) mkdir($root . '/' . $directory, 0775, true);

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/users.json', ['users' => [[
        'username' => 'owner', 'role' => 'owner', 'password_hash' => Password::hash('OwnerPassword!2026'), 'created_at' => date(DATE_ATOM),
    ]]]);
    $paths = new Paths($root, ['config' => 'radpress/config', 'data' => 'radpress/data']);
    $secrets = new SecretStore($paths);
    $encrypted = $secrets->encrypt('sensitive-value');
    assertMfa($encrypted !== 'sensitive-value' && $secrets->decrypt($encrypted) === 'sensitive-value', 'secret store should encrypt and authenticate sensitive values');
    $keyPath = $paths->dataPath('security/master.key');
    assertMfa(is_file($keyPath) && ((fileperms($keyPath) ?: 0) & 0077) === 0, 'local fallback encryption key should be private');

    $rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    assertMfa(Totp::currentCode($rfcSecret, 59) === '287082', 'TOTP should match the RFC 6238 SHA-1 vector truncated to six digits');
    assertMfa(Totp::verify($rfcSecret, '287082', 59, 0), 'TOTP verification should accept the exact time-step code');
    assertMfa(!Totp::verify($rfcSecret, '000000', 59, 0), 'TOTP verification should reject incorrect codes');

    $secret = Totp::generateSecret();
    $recovery = Totp::recoveryCodes(6);
    $mfa = new MfaRepository($paths, $files, $secrets);
    $mfa->enable('owner', $secret, $recovery);
    $stored = $mfa->findUser('owner');
    assertMfa($mfa->enabled((array)$stored), 'MFA repository should mark the account enabled');
    assertMfa(!str_contains(json_encode($stored, JSON_UNESCAPED_SLASHES) ?: '', $secret), 'stored user configuration must not contain the plaintext TOTP secret');
    assertMfa(!str_contains(json_encode($stored, JSON_UNESCAPED_SLASHES) ?: '', str_replace('-', '', $recovery[0])), 'stored user configuration must not contain plaintext recovery codes');

    $session = new Session('batoi_press_mfa_test', $paths->dataPath('sessions'));
    $auth = new Auth($paths, $session, $files);
    assertMfa($auth->beginAttempt('owner', 'OwnerPassword!2026') === 'mfa_required', 'correct password should begin rather than bypass MFA');
    assertMfa(!$auth->check(), 'password verification alone must not create an authenticated session when MFA is enabled');
    assertMfa($auth->completeMfa('000000') === null && !$auth->check(), 'incorrect second factor should keep the session unauthenticated');
    assertMfa($auth->completeMfa(Totp::currentCode($secret)) === 'totp' && $auth->check(), 'current TOTP should complete authentication');
    $auth->logout();

    assertMfa($auth->beginAttempt('owner', 'OwnerPassword!2026') === 'mfa_required', 'MFA should be required for every fresh login');
    assertMfa($auth->completeMfa($recovery[0]) === 'recovery' && $auth->check(), 'unused recovery code should complete authentication once');
    $auth->logout();
    assertMfa($auth->beginAttempt('owner', 'OwnerPassword!2026') === 'mfa_required', 'password should still begin MFA after recovery login');
    assertMfa($auth->completeMfa($recovery[0]) === null, 'used recovery code must not work twice');

    echo "MFA security checks passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    removeMfaFixture($root);
}

function assertMfa(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeMfaFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($path);
}
