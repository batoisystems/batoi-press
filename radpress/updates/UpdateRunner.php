<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use Batoi\Press\Core\Paths;
use ZipArchive;

final class UpdateRunner
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function stage(string $packagePath, ?string $sha256 = null): array
    {
        $verifier = new PackageVerifier();
        if ($sha256 !== null && $sha256 !== '' && !$verifier->verifyChecksum($packagePath, $sha256)) {
            return ['ok' => false, 'error' => 'Package checksum verification failed.'];
        }

        if (!$verifier->verifyZip($packagePath)) {
            return ['ok' => false, 'error' => 'Package is not a valid ZIP archive.'];
        }

        if (!$verifier->verifyZipEntries($packagePath)) {
            return ['ok' => false, 'error' => 'Package contains unsafe ZIP paths.'];
        }

        $stageDir = $this->paths->dataPath('tmp/update-stage-' . date('Ymd-His'));
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0775, true);
        }

        $zip = new ZipArchive();
        $zip->open($packagePath);
        $zip->extractTo($stageDir);
        $zip->close();

        $manifest = $this->findManifest($stageDir);
        if ($manifest === null) {
            return ['ok' => false, 'error' => 'Staged package does not include a release manifest.', 'stage_dir' => $stageDir];
        }

        return ['ok' => true, 'stage_dir' => $stageDir, 'manifest' => $manifest];
    }

    public function apply(string $stageDir): array
    {
        if (!$this->isInside($stageDir, $this->paths->dataPath('tmp'))) {
            return ['ok' => false, 'error' => 'Invalid staged package path.'];
        }

        $manifest = $this->findManifest($stageDir);
        if ($manifest === null) {
            return ['ok' => false, 'error' => 'Staged package does not include a valid release manifest.'];
        }

        $files = $manifest['files'] ?? null;
        if (!is_array($files) || $files === []) {
            return ['ok' => false, 'error' => 'Release manifest does not list files to install.'];
        }

        $backup = (new BackupManager($this->paths))->create();
        if (!($backup['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Unable to create pre-update backup: ' . (string)($backup['error'] ?? 'backup failed')];
        }

        $installed = 0;
        foreach ($files as $file) {
            if (!is_array($file)) {
                return ['ok' => false, 'error' => 'Release manifest contains an invalid file entry.', 'backup' => $backup['path'] ?? null];
            }

            $sourceRelative = (string)($file['source'] ?? $file['path'] ?? '');
            $targetRelative = (string)($file['target'] ?? $file['path'] ?? '');
            $checksum = (string)($file['sha256'] ?? '');
            $source = $this->joinRelative($stageDir, $sourceRelative);
            $target = $this->joinRelative($this->paths->root(), $targetRelative);

            if ($source === null || $target === null || !$this->isAllowedTarget($targetRelative)) {
                return ['ok' => false, 'error' => 'Release manifest contains an unsafe file path.', 'backup' => $backup['path'] ?? null];
            }

            if (!is_file($source)) {
                return ['ok' => false, 'error' => 'Staged file is missing: ' . $sourceRelative, 'backup' => $backup['path'] ?? null];
            }

            if ($checksum !== '' && hash_file('sha256', $source) !== strtolower($checksum)) {
                return ['ok' => false, 'error' => 'Checksum failed for staged file: ' . $sourceRelative, 'backup' => $backup['path'] ?? null];
            }

            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            if (!copy($source, $target)) {
                return ['ok' => false, 'error' => 'Unable to install file: ' . $targetRelative, 'backup' => $backup['path'] ?? null];
            }

            $installed++;
        }

        return [
            'ok' => true,
            'installed_files' => $installed,
            'backup' => $backup['path'] ?? null,
            'version' => (string)($manifest['version'] ?? ''),
        ];
    }

    public function stagedPackages(): array
    {
        $dirs = glob($this->paths->dataPath('tmp/update-stage-*'), GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        return $dirs;
    }

    private function findManifest(string $stageDir): ?array
    {
        foreach (['release.json', 'batoi-press-release.json', 'manifest.json'] as $name) {
            $path = $stageDir . '/' . $name;
            if (is_file($path)) {
                $decoded = json_decode((string)file_get_contents($path), true);
                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    private function joinRelative(string $root, string $relative): ?string
    {
        $relative = trim($relative, '/');
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return null;
        }

        return rtrim($root, '/') . '/' . $relative;
    }

    private function isAllowedTarget(string $relative): bool
    {
        $relative = trim($relative, '/');
        foreach ($this->allowedTargetPrefixes() as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function allowedTargetPrefixes(): array
    {
        return [
            'README.md',
            'LICENSE',
            'public_html/',
            'radpress/admin/',
            'radpress/app/',
            'radpress/core/',
            'radpress/docs/',
            'radpress/helpers/',
            'radpress/security/',
            'radpress/tests/',
            'radpress/theme/',
            'radpress/updates/',
            'radpress/autoload.php',
            'radpress/config/update.json',
            'radpress/config/paths.json',
        ];
    }

    private function isInside(string $path, string $root): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);
        return $realPath !== false && $realRoot !== false && str_starts_with($realPath, rtrim($realRoot, '/') . '/');
    }
}
