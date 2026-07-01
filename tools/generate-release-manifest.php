<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$version = readCurrentVersion($root);
$zipPath = optionValue($argv, '--zip') ?? $root . '/dist/batoi-press-' . $version . '.zip';
$outputPath = optionValue($argv, '--output') ?? $root . '/dist/latest.json';
$releasedAt = optionValue($argv, '--released-at') ?? date(DATE_ATOM);
$githubReleaseUrl = optionValue($argv, '--github-release-url');

if (!is_file($zipPath)) {
    buildReleasePackage($root, $zipPath);
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "Release package not found: {$zipPath}\n");
    exit(1);
}

$checksum = hash_file('sha256', $zipPath);
if ($checksum === false) {
    fwrite(STDERR, "Unable to compute SHA-256 checksum: {$zipPath}\n");
    exit(1);
}

$manifest = [
    'channel' => 'stable',
    'version' => $version,
    'released_at' => $releasedAt,
    'download_url' => 'https://www.batoi.com/pub/press/releases/batoi-press-' . $version . '.zip',
    'checksum_sha256' => $checksum,
    'minimum_php' => '8.1',
    'github_release_url' => $githubReleaseUrl !== null && trim($githubReleaseUrl) !== '' ? trim($githubReleaseUrl) : null,
    'notes_url' => 'https://www.batoi.com/press/releases#' . $version,
    'trust' => [
        'signature_required' => false,
        'signature_algorithm' => null,
        'signature_url' => null,
        'public_key_url' => null,
    ],
];

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false || file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    fwrite(STDERR, "Unable to write release manifest: {$outputPath}\n");
    exit(1);
}

echo "Release manifest created: {$outputPath}\n";
echo "Release package: {$zipPath}\n";
echo "Version: {$version}\n";
echo "SHA-256: {$checksum}\n";

function readCurrentVersion(string $root): string
{
    $path = $root . '/radpress/config/update.json';
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $version = is_array($decoded) ? (string)($decoded['current_version'] ?? '') : '';
    $version = preg_replace('/[^0-9A-Za-z._-]/', '-', $version) ?: '';

    if ($version === '') {
        fwrite(STDERR, "Missing current_version in {$path}\n");
        exit(1);
    }

    return $version;
}

function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return (string)$argv[$index + 1];
        }
        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return null;
}

function buildReleasePackage(string $root, string $zipPath): void
{
    $script = $root . '/tools/build-release.php';
    if (!is_file($script)) {
        fwrite(STDERR, "Release build script not found: {$script}\n");
        exit(1);
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --output ' . escapeshellarg($zipPath);
    passthru($command, $status);

    if ($status !== 0) {
        fwrite(STDERR, "Release build failed with exit code {$status}.\n");
        exit($status);
    }
}
