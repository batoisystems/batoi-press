<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the command line.\n");
    exit(1);
}

$path = dirname(__DIR__) . '/config/users.json';
$username = trim((string)($argv[1] ?? ''));
if ($username === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php radpress/bin/reset-admin-password.php USERNAME\n");
    exit(1);
}
fwrite(STDOUT, 'New password: ');
$password = trim((string)fgets(STDIN));
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}
$config = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$found = false;
foreach ($config['users'] ?? [] as &$user) {
    if ((string)($user['username'] ?? '') === $username) {
        $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $user['updated_at'] = date(DATE_ATOM);
        $found = true;
        break;
    }
}
unset($user);
if (!$found) {
    fwrite(STDERR, "User not found.\n");
    exit(1);
}
if (file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Unable to update users.json.\n");
    exit(1);
}
fwrite(STDOUT, "Password updated.\n");
