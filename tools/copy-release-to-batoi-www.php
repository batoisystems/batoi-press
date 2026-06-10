<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$version = readCurrentVersion($root);
$defaultRepo = dirname($root) . '/batoi-www';
$targetRepo = optionValue($argv, '--target') ?? getenv('BATOI_WWW_REPO') ?: $defaultRepo;
$targetPublic = rtrim($targetRepo, '/') . '/public_html/pub/press';
$zipPath = $root . '/dist/batoi-press-' . $version . '.zip';
$manifestPath = $root . '/dist/latest.json';
$dryRun = in_array('--dry-run', $argv, true);

ensureReleaseFiles($root, $zipPath, $manifestPath);

if (!is_dir(rtrim($targetRepo, '/') . '/public_html')) {
    fwrite(STDERR, "Target repo public_html directory not found: {$targetRepo}/public_html\n");
    exit(1);
}

$copies = [
    $manifestPath => $targetPublic . '/latest.json',
    $zipPath => $targetPublic . '/releases/batoi-press-' . $version . '.zip',
];

foreach ($copies as $source => $destination) {
    if ($dryRun) {
        echo "Would copy {$source} to {$destination}\n";
        continue;
    }

    $destinationDir = dirname($destination);
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
        fwrite(STDERR, "Unable to create destination directory: {$destinationDir}\n");
        exit(1);
    }

    if (!copy($source, $destination)) {
        fwrite(STDERR, "Unable to copy {$source} to {$destination}\n");
        exit(1);
    }

    echo "Copied {$source} to {$destination}\n";
}

echo "Batoi Press {$version} staged in {$targetPublic}\n";

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

function ensureReleaseFiles(string $root, string $zipPath, string $manifestPath): void
{
    if (is_file($zipPath) && is_file($manifestPath)) {
        return;
    }

    $script = $root . '/tools/generate-release-manifest.php';
    if (!is_file($script)) {
        fwrite(STDERR, "Manifest generator not found: {$script}\n");
        exit(1);
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    passthru($command, $status);

    if ($status !== 0) {
        fwrite(STDERR, "Release manifest generation failed with exit code {$status}.\n");
        exit($status);
    }
}
