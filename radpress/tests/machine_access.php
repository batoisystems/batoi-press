<?php
declare(strict_types=1);

use Batoi\Press\Core\Paths;
use Batoi\Press\Security\AccessTokenRepository;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-machine-access-' . bin2hex(random_bytes(6));
mkdir($root . '/radpress/data', 0775, true);

try {
    $repository = new AccessTokenRepository(new Paths($root, ['data' => 'radpress/data']));
    $issued = $repository->issue(
        'ChatGPT publishing connection',
        ['content:write', 'content:read', 'content:read'],
        'owner',
        new DateTimeImmutable('+1 hour')
    );

    $token = (string)($issued['token'] ?? '');
    $access = is_array($issued['access'] ?? null) ? $issued['access'] : [];
    assertTrue((bool)preg_match('/^bp2_[a-f0-9]{24}_[A-Za-z0-9_-]{43}$/D', $token), 'issued token should use the versioned machine-token format');
    assertSame(['content:read', 'content:write'], $access['scopes'] ?? null, 'issued scopes should be normalized and deduplicated');
    assertTrue(!array_key_exists('secret_hash', $access), 'issued public metadata should not expose the token hash');

    $storagePath = $root . '/radpress/data/integrations/access-tokens.json';
    $stored = (string)file_get_contents($storagePath);
    assertTrue(!str_contains($stored, $token), 'plaintext token should never be stored');
    assertTrue(!str_contains($stored, substr($token, strrpos($token, '_') + 1)), 'plaintext token secret should never be stored');
    assertTrue(str_contains($stored, 'secret_hash'), 'token storage should retain only a password hash');

    $authenticated = $repository->authenticate($token, ['content:read']);
    assertSame($access['id'] ?? null, $authenticated['id'] ?? null, 'valid token should authenticate for a granted scope');
    assertSame(null, $repository->authenticate($token, ['content:publish']), 'token should fail closed for a missing scope');
    assertSame(null, $repository->authenticate($token . 'x'), 'malformed token should fail authentication');
    assertSame(null, $repository->authenticate(substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a')), 'incorrect token secret should fail authentication');

    $listed = $repository->all();
    assertSame(1, count($listed), 'token metadata list should contain the issued token');
    assertTrue(!array_key_exists('secret_hash', $listed[0]), 'token metadata list should not expose password hashes');
    assertTrue(($listed[0]['active'] ?? false) === true, 'new unexpired token should be active');

    assertTrue($repository->revoke((string)$access['id']), 'active token should be revocable');
    assertTrue(!$repository->revoke((string)$access['id']), 'revoking an already revoked token should report no change');
    assertSame(null, $repository->authenticate($token), 'revoked token should fail authentication');
    assertTrue(($repository->all()[0]['active'] ?? true) === false, 'revoked token metadata should be inactive');

    $expired = $repository->issue('Expired fixture', ['site:read'], 'owner', new DateTimeImmutable('+1 second'));
    sleep(2);
    assertSame(null, $repository->authenticate((string)$expired['token']), 'expired token should fail authentication');

    assertThrows(
        static fn () => $repository->issue('Invalid scope', ['users:write'], 'owner'),
        InvalidArgumentException::class,
        'unknown scopes should be rejected'
    );
    assertThrows(
        static fn () => $repository->issue('Past expiry', ['site:read'], 'owner', new DateTimeImmutable('-1 minute')),
        InvalidArgumentException::class,
        'past expiry should be rejected'
    );
} finally {
    removeTree($root);
}

echo "Machine access checks passed\n";

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $class, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw new RuntimeException($message . ' threw ' . $exception::class . ' instead of ' . $class);
    }
    throw new RuntimeException($message . ' did not throw ' . $class);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
