<?php
declare(strict_types=1);

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Security\SessionRegistry;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-session-registry-' . bin2hex(random_bytes(5));
mkdir($root . '/radpress/data/security', 0700, true);
try {
    $paths = new Paths($root, ['data' => 'radpress/data']);
    $registry = new SessionRegistry($paths, new FileStore());
    $registry->touch('secret-session-one', 'owner', time() - 60, time() - 5, '127.0.0.1', 'Test Browser');
    $registry->touch('secret-session-two', 'owner', time() - 120, time() - 10, '192.0.2.10', 'Other Browser');
    $records = $registry->allFor('owner');
    assertSessionRegistry(count($records) === 2, 'session inventory should list the account sessions');
    $stored = file_get_contents($paths->dataPath('security/session-inventory.json')) ?: '';
    assertSessionRegistry(!str_contains($stored, 'secret-session-one') && str_contains($stored, hash('sha256', 'secret-session-one')), 'session inventory must retain only hashed identifiers');
    assertSessionRegistry($registry->revoke(hash('sha256', 'secret-session-two'), 'owner'), 'an owned session should be revocable');
    assertSessionRegistry($registry->isRevoked('secret-session-two'), 'revoked sessions should fail on their next request');
    assertSessionRegistry(!$registry->revoke(hash('sha256', 'secret-session-one'), 'someone-else'), 'accounts must not revoke another account session');
    echo "Session inventory checks passed\n";
} finally {
    removeSessionRegistryFixture($root);
}

function assertSessionRegistry(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeSessionRegistryFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($path);
}
