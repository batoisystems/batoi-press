<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$version = readVersion($root);
$output = optionValue($argv, '--output') ?? $root . '/dist/batoi-press-' . $version . '.zip';

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive is not available.\n");
    exit(1);
}

$outputDir = dirname($output);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

validateRequiredAssets($root);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create release ZIP: {$output}\n");
    exit(1);
}

foreach (releaseRoots($root) as $path) {
    addPath($zip, $root, $path);
}

if (!$zip->close()) {
    fwrite(STDERR, "Unable to finalize release ZIP: {$output}\n");
    exit(1);
}

echo "Release package created: {$output}\n";

function readVersion(string $root): string
{
    $path = $root . '/radpress/config/update.json';
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $version = is_array($decoded) ? (string)($decoded['current_version'] ?? '0.1.0') : '0.1.0';
    return preg_replace('/[^0-9A-Za-z._-]/', '-', $version) ?: '0.1.0';
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

function releaseRoots(string $root): array
{
    return [
        $root . '/public_html',
        $root . '/radpress',
        $root . '/README.md',
        $root . '/LICENSE',
    ];
}

function validateRequiredAssets(string $root): void
{
    $assets = [
        'public_html/assets/uif/uif.css' => 50000,
        'public_html/assets/uif/uif.iife.js' => 250000,
        'public_html/assets/uif/uif.life.js' => 250000,
        'public_html/assets/uif/uif.esm.js' => 250000,
        'public_html/assets/uif/uif.js' => 100,
        'public_html/assets/img/press-color.svg' => 100,
        'public_html/assets/img/batoi-press/press-color.svg' => 100,
        'public_html/assets/img/batoi-press/press-color-tile-180.png' => 1000,
        'public_html/assets/img/batoi-press/press-color-tile-512.png' => 5000,
        'public_html/assets/img/batoi-press/press-mono.svg' => 100,
    ];

    foreach ($assets as $relative => $minimumBytes) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            fwrite(STDERR, "Required release asset is missing: {$relative}\n");
            exit(1);
        }
        if (filesize($path) < $minimumBytes) {
            fwrite(STDERR, "Required release asset appears incomplete: {$relative}\n");
            exit(1);
        }
    }
}

function addPath(ZipArchive $zip, string $root, string $path): void
{
    if (shouldExclude($root, $path)) {
        return;
    }

    if (is_file($path)) {
        $zip->addFile($path, ltrim(substr($path, strlen($root)), '/'));
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        addPath($zip, $root, $path . '/' . $item);
    }
}

function shouldExclude(string $root, string $path): bool
{
    $relative = ltrim(substr($path, strlen($root)), '/');
    if ($relative === '') {
        return false;
    }

    $basename = basename($relative);
    if ($basename === '.DS_Store' || $basename === 'installed.lock') {
        return true;
    }

    if (is_dir($path)) {
        return false;
    }

    foreach (excludedPrefixes() as $prefix) {
        if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
            return !in_array($basename, ['.gitkeep', '.htaccess'], true);
        }
    }

    return false;
}

function excludedPrefixes(): array
{
    return [
        '.git/',
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
