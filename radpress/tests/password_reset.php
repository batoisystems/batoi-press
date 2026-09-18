<?php
declare(strict_types=1);

// Run the actual CLI command against disposable accounts, never installation users.
$root = sys_get_temp_dir() . '/press-password-reset-' . bin2hex(random_bytes(6));
mkdir($root . '/bin', 0700, true);
mkdir($root . '/config', 0700, true);
copy(dirname(__DIR__) . '/bin/reset-admin-password.php', $root . '/bin/reset-admin-password.php');
$path = $root . '/config/users.json';
$before = ['users'=>[
    ['username'=>'owner', 'role'=>'owner', 'password_hash'=>password_hash('Old-fixture-password', PASSWORD_DEFAULT)],
    ['username'=>'editor', 'role'=>'editor', 'password_hash'=>password_hash('Other-fixture-password', PASSWORD_DEFAULT)],
], 'fixture_metadata'=>'preserve'];
file_put_contents($path, json_encode($before, JSON_THROW_ON_ERROR));
$run = static function (string $username, string $password) use ($root): array {
    $process = proc_open([PHP_BINARY, $root . '/bin/reset-admin-password.php', $username], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to launch reset command.');
    fwrite($pipes[0], $password . "\n");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
};
try {
    [$status, $output] = $run('owner', 'New-fixture-password');
    $saved = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($status !== 0 || !str_contains($output, 'Password updated.') || !password_verify('New-fixture-password', $saved['users'][0]['password_hash'])) throw new RuntimeException('Reset reported success without persisting the new password.');
    if (password_verify('Old-fixture-password', $saved['users'][0]['password_hash'])) throw new RuntimeException('Old password remains valid.');
    if (str_contains($output, 'New-fixture-password')) throw new RuntimeException('Reset output exposed the password.');
    $comparison = $saved;
    $comparison['users'][0]['password_hash'] = $before['users'][0]['password_hash'];
    unset($comparison['users'][0]['updated_at']);
    if ($comparison !== $before) throw new RuntimeException('Reset changed unrelated account data.');
    $bytes = file_get_contents($path);
    foreach ([['missing', 'Valid-fixture-password'], ['owner', 'short']] as [$username, $password]) {
        [$status] = $run($username, $password);
        if ($status === 0 || file_get_contents($path) !== $bytes) throw new RuntimeException('Rejected reset changed account data or returned success.');
    }
    echo "Password reset persistence checks passed\n";
} finally {
    unlink($root . '/bin/reset-admin-password.php');
    unlink($path);
    rmdir($root . '/bin');
    rmdir($root . '/config');
    rmdir($root);
}
