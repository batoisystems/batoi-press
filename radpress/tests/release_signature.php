<?php
declare(strict_types=1);

use Batoi\Press\Update\ReleaseSignature;

require dirname(__DIR__) . '/autoload.php';

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = base64_encode(sodium_crypto_sign_publickey($keypair));
$manifest = ['name' => 'Batoi Press', 'version' => '2.0.0', 'files' => [['path' => 'README.md', 'sha256' => str_repeat('a', 64)]]];
$signed = ReleaseSignature::sign($manifest, $secret, 'release-test', 'package-manifest');
assertReleaseSignature(ReleaseSignature::verify($signed, ['release-test' => $public], true, 'package-manifest') === null, 'valid package signatures should verify');

$tampered = $signed;
$tampered['files'][0]['sha256'] = str_repeat('b', 64);
assertReleaseSignature(ReleaseSignature::verify($tampered, ['release-test' => $public], true, 'package-manifest') === 'Release signature verification failed.', 'signed metadata changes should fail verification');
assertReleaseSignature(ReleaseSignature::verify($signed, [], true, 'package-manifest') === 'Release signature key or value is invalid.', 'unknown release keys should fail closed');
assertReleaseSignature(ReleaseSignature::verify($manifest, ['release-test' => $public], true, 'package-manifest') === 'Release signature is required but missing.', 'required unsigned manifests should fail closed');

echo "Release signature checks passed\n";

function assertReleaseSignature(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
