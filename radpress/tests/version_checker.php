<?php
declare(strict_types=1);

use Batoi\Press\Update\VersionChecker;
use Batoi\Press\Update\ReleaseSignature;

require dirname(__DIR__) . '/autoload.php';

$manifest = json_encode([
    'version' => '1.8.0',
    'download_url' => 'https://www.batoi.com/pub/press/releases/batoi-press-1.8.0.zip',
], JSON_UNESCAPED_SLASHES);

$requestedUrl = '';
$available = (new VersionChecker('https://example.test/latest.json', static function (string $url) use (&$requestedUrl, $manifest): string {
    $requestedUrl = $url;
    return (string)$manifest;
}))->check('1.7.0');
assertVersion($requestedUrl === 'https://example.test/latest.json', 'The configured manifest URL should be fetched.');
assertVersion(($available['ok'] ?? false) === true, 'A valid update manifest should pass.');
assertVersion(($available['update_available'] ?? false) === true, 'A newer manifest version should be reported.');

$current = (new VersionChecker('https://example.test/latest.json', static fn (): string => (string)$manifest))->check('1.8.0');
assertVersion(($current['update_available'] ?? true) === false, 'The current release should not report an update.');

$invalid = (new VersionChecker('https://example.test/latest.json', static fn (): string => '<html>not json</html>'))->check('1.7.0');
assertVersion(($invalid['ok'] ?? true) === false && ($invalid['error'] ?? '') === 'Update manifest is invalid.', 'Invalid manifest content should fail clearly.');

$unavailable = (new VersionChecker('https://example.test/latest.json', static fn (): false => false))->check('1.7.0');
assertVersion(($unavailable['ok'] ?? true) === false, 'An unavailable manifest should fail.');
assertVersion(str_contains((string)($unavailable['error'] ?? ''), 'outbound HTTPS access'), 'A failed check should include actionable hosting diagnostics.');

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = base64_encode(sodium_crypto_sign_publickey($keypair));
$signedManifest = ReleaseSignature::sign([
    'version' => '2.0.0', 'download_url' => 'https://example.test/batoi-press-2.0.0.zip', 'checksum_sha256' => str_repeat('a', 64),
], $secret, 'version-test', 'release-index');
$signed = (new VersionChecker('https://example.test/latest.json', static fn (): string => (string)json_encode($signedManifest), ['version-test' => $public], true))->check('1.8.0');
assertVersion(($signed['ok'] ?? false) === true && ($signed['update_available'] ?? false) === true, 'valid signed release indexes should pass');
$tamperedManifest = $signedManifest;
$tamperedManifest['version'] = '2.0.1';
$tampered = (new VersionChecker('https://example.test/latest.json', static fn (): string => (string)json_encode($tamperedManifest), ['version-test' => $public], true))->check('1.8.0');
assertVersion(($tampered['ok'] ?? true) === false && str_contains((string)($tampered['error'] ?? ''), 'verification failed'), 'tampered signed release indexes should fail closed');

echo "Version checker checks passed\n";

function assertVersion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
