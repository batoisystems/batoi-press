<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/radpress/data/security/release-signing.key';
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "The Sodium extension is required.\n");
    exit(1);
}
if (is_file($path)) {
    fwrite(STDERR, "Release signing key already exists; refusing to replace it.\n");
    exit(1);
}
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create private key directory.\n");
    exit(1);
}
$keypair = sodium_crypto_sign_keypair();
$temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
if (file_put_contents($temporary, base64_encode(sodium_crypto_sign_secretkey($keypair)) . PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Unable to write release signing key.\n");
    exit(1);
}
@chmod($path, 0600);
echo 'Key ID: batoi-press-release-2026-01' . PHP_EOL;
echo 'Public key: ' . base64_encode(sodium_crypto_sign_publickey($keypair)) . PHP_EOL;
echo 'Private key stored in excluded runtime security storage.' . PHP_EOL;
