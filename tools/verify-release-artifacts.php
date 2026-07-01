<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$version = readCurrentVersion($root);
$zipPath = optionValue($argv, '--zip') ?? $root . '/dist/batoi-press-' . $version . '.zip';
$manifestPath = optionValue($argv, '--manifest') ?? $root . '/dist/latest.json';

if (!class_exists(ZipArchive::class)) {
    fail('ZipArchive is not available.');
}

if (!is_file($zipPath)) {
    fail("Release package not found: {$zipPath}");
}

if (!is_file($manifestPath)) {
    fail("Release manifest not found: {$manifestPath}");
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fail("Unable to open release package: {$zipPath}");
}

$entries = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = (string)$zip->getNameIndex($index);
    $entries[$name] = true;
}
$zip->close();

foreach (requiredEntries() as $entry) {
    if (!isset($entries[$entry])) {
        fail("Release package is missing required file: {$entry}");
    }
}

$packageManifestJson = readZipEntry($zipPath, 'release.json');
$packageManifest = json_decode($packageManifestJson, true);
if (!is_array($packageManifest)) {
    fail('Release package root release.json is not valid JSON.');
}

if ((string)($packageManifest['version'] ?? '') !== $version) {
    fail('Release package root release.json version does not match current_version.');
}

$packageFiles = $packageManifest['files'] ?? null;
if (!is_array($packageFiles) || $packageFiles === []) {
    fail('Release package root release.json does not list installable files.');
}

foreach ($packageFiles as $file) {
    if (!is_array($file)) {
        fail('Release package root release.json contains an invalid file entry.');
    }

    $path = (string)($file['source'] ?? $file['path'] ?? '');
    $checksum = strtolower((string)($file['sha256'] ?? ''));
    if ($path === '' || !isset($entries[$path])) {
        fail("Release package manifest references a missing file: {$path}");
    }
    if (!isAllowedManifestTarget($path)) {
        fail("Release package manifest references a non-installable path: {$path}");
    }
    if ($checksum === '' || !hash_equals($checksum, hash('sha256', readZipEntry($zipPath, $path)))) {
        fail("Release package manifest checksum does not match: {$path}");
    }
}

foreach (array_keys($entries) as $entry) {
    if (isExcludedEntry($entry)) {
        fail("Release package contains generated runtime state: {$entry}");
    }
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fail("Release manifest is not valid JSON: {$manifestPath}");
}

if ((string)($manifest['version'] ?? '') !== $version) {
    fail('Release manifest version does not match current_version.');
}

$checksum = hash_file('sha256', $zipPath);
if ($checksum === false || !hash_equals($checksum, (string)($manifest['checksum_sha256'] ?? ''))) {
    fail('Release manifest checksum does not match the release package.');
}

$downloadUrl = (string)($manifest['download_url'] ?? '');
if (!str_ends_with($downloadUrl, '/releases/batoi-press-' . $version . '.zip')) {
    fail('Release manifest download_url does not point to the current package name.');
}

$trust = $manifest['trust'] ?? null;
if (!is_array($trust) || !array_key_exists('signature_required', $trust)) {
    fail('Release manifest is missing package trust metadata.');
}

echo "Release artifacts verified\n";
echo "Package: {$zipPath}\n";
echo "Manifest: {$manifestPath}\n";
echo "Version: {$version}\n";
echo "SHA-256: {$checksum}\n";

function readCurrentVersion(string $root): string
{
    $path = $root . '/radpress/config/update.json';
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $version = is_array($decoded) ? (string)($decoded['current_version'] ?? '') : '';
    $version = preg_replace('/[^0-9A-Za-z._-]/', '-', $version) ?: '';

    if ($version === '') {
        fail("Missing current_version in {$path}");
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

function requiredEntries(): array
{
    return [
        'release.json',
        'README.md',
        'LICENSE',
        'public_html/index.php',
        'public_html/admin.php',
        'public_html/assets/uif/uif.css',
        'public_html/assets/uif/uif.iife.js',
        'public_html/assets/uif/uif.life.js',
        'public_html/assets/uif/uif.esm.js',
        'public_html/assets/img/press-color.svg',
        'public_html/assets/img/batoi-press/press-color.svg',
        'public_html/assets/img/batoi-press/press-color-tile-180.png',
        'public_html/assets/img/batoi-press/press-color-tile-512.png',
        'public_html/assets/img/batoi-press/press-mono.svg',
        'radpress/autoload.php',
        'radpress/config/update.json',
        'radpress/docs/release-management.md',
    ];
}

function readZipEntry(string $zipPath, string $entry): string
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fail("Unable to open release package: {$zipPath}");
    }

    $contents = $zip->getFromName($entry);
    $zip->close();

    if ($contents === false) {
        fail("Unable to read release package entry: {$entry}");
    }

    return $contents;
}

function isAllowedManifestTarget(string $relative): bool
{
    $relative = trim($relative, '/');
    foreach (allowedManifestTargetPrefixes() as $prefix) {
        if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    return false;
}

function allowedManifestTargetPrefixes(): array
{
    return [
        'README.md',
        'LICENSE',
        'public_html/',
        'radpress/admin/',
        'radpress/aif/',
        'radpress/app/',
        'radpress/core/',
        'radpress/docs/',
        'radpress/helpers/',
        'radpress/security/',
        'radpress/tests/',
        'radpress/theme/',
        'radpress/uif/',
        'radpress/updates/',
        'radpress/autoload.php',
        'radpress/config/aif.json',
        'radpress/config/update.json',
        'radpress/config/paths.json',
    ];
}

function isExcludedEntry(string $entry): bool
{
    if (basename($entry) === 'installed.lock') {
        return true;
    }

    foreach (excludedPrefixes() as $prefix) {
        if ($entry === rtrim($prefix, '/') || str_starts_with($entry, $prefix)) {
            return !in_array(basename($entry), ['.gitkeep', '.htaccess'], true);
        }
    }

    return false;
}

function excludedPrefixes(): array
{
    return [
        'dist/',
        'radpress/data/backups/',
        'radpress/data/cache/',
        'radpress/data/export/',
        'radpress/data/log/',
        'radpress/data/sessions/',
        'radpress/data/tmp/',
        'radpress/data/versions/',
    ];
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
