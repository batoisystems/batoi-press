<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use Batoi\Press\Core\Cache;
use Batoi\Press\Core\MaintenanceMode;
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
        if (!is_dir($stageDir)) {
            return ['ok' => false, 'error' => 'The selected staged package is no longer available. Stage the package again.'];
        }

        if (!$this->isInside($stageDir, $this->paths->dataPath('tmp'))) {
            return ['ok' => false, 'error' => 'The selected staged package is outside update storage. Stage the package again from the Updates screen.'];
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

        $maintenance = new MaintenanceMode($this->paths);
        $maintenance->enable('Applying Batoi Press update');

        $installed = 0;
        $installedTargets = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                return $this->failAndRollback('Release manifest contains an invalid file entry.', (string)($backup['path'] ?? ''), $maintenance, $installedTargets);
            }

            $sourceRelative = (string)($file['source'] ?? $file['path'] ?? '');
            $targetRelative = (string)($file['target'] ?? $file['path'] ?? '');
            $checksum = (string)($file['sha256'] ?? '');
            $source = $this->joinRelative($stageDir, $sourceRelative);
            $target = $this->joinRelative($this->paths->root(), $targetRelative);

            if ($source === null || $target === null || !$this->isAllowedTarget($targetRelative)) {
                return $this->failAndRollback('Release manifest contains an unsafe file path.', (string)($backup['path'] ?? ''), $maintenance, $installedTargets);
            }

            if (!is_file($source)) {
                return $this->failAndRollback('Staged file is missing: ' . $sourceRelative, (string)($backup['path'] ?? ''), $maintenance, $installedTargets);
            }

            if ($checksum !== '' && hash_file('sha256', $source) !== strtolower($checksum)) {
                return $this->failAndRollback('Checksum failed for staged file: ' . $sourceRelative, (string)($backup['path'] ?? ''), $maintenance, $installedTargets);
            }

            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            $installedTargets[$target] = is_file($target);
            if (!copy($source, $target)) {
                return $this->failAndRollback('Unable to install file: ' . $targetRelative, (string)($backup['path'] ?? ''), $maintenance, $installedTargets);
            }

            $installed++;
        }

        $cacheCleared = (new Cache($this->paths))->clear();
        $health = (new UpdateHealthCheck($this->paths))->run($manifest);
        if (!($health['ok'] ?? false)) {
            return $this->failAndRollback(
                'Post-update health check failed: ' . implode('; ', $health['errors'] ?? []),
                (string)($backup['path'] ?? ''),
                $maintenance,
                $installedTargets
            );
        }

        $maintenance->disable();

        return [
            'ok' => true,
            'installed_files' => $installed,
            'cache_cleared' => $cacheCleared,
            'health_check' => $health,
            'backup' => $backup['path'] ?? null,
            'version' => (string)($manifest['version'] ?? ''),
        ];
    }

    public function stagedPackages(): array
    {
        $dirs = glob($this->paths->dataPath('tmp/update-stage-*'), GLOB_ONLYDIR) ?: [];
        $dirs = array_values(array_filter($dirs, fn (string $dir): bool => preg_match('/^update-stage-\d{8}-\d{6}$/', basename($dir)) === 1));
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

    private function isInside(string $path, string $root): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);
        return $realPath !== false && $realRoot !== false && str_starts_with($realPath, rtrim($realRoot, '/') . '/');
    }

    private function failAndRollback(string $error, string $backupPath, MaintenanceMode $maintenance, array $installedTargets = []): array
    {
        $rollback = $backupPath !== '' ? (new RollbackManager($this->paths))->restore($backupPath) : ['ok' => false, 'error' => 'No backup was available.'];
        foreach ($installedTargets as $target => $existedBefore) {
            if ($existedBefore === false && is_file($target)) {
                unlink($target);
            }
        }
        (new Cache($this->paths))->clear();
        $maintenance->disable();

        return [
            'ok' => false,
            'error' => $error,
            'backup' => $backupPath,
            'rolled_back' => $rollback['ok'] ?? false,
            'rollback_error' => $rollback['error'] ?? null,
        ];
    }
}
