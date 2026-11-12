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

        $zipError = $verifier->zipError($packagePath);
        if ($zipError !== null) {
            return ['ok' => false, 'error' => $zipError];
        }

        if (!$verifier->verifyZipEntries($packagePath)) {
            return ['ok' => false, 'error' => 'Package contains unsafe ZIP paths.'];
        }

        $stageDir = $this->paths->dataPath('tmp/update-stage-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)));
        if (!mkdir($stageDir, 0700, true)) {
            return ['ok'=>false, 'error'=>'Unable to create update staging storage.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) return ['ok'=>false, 'error'=>'Unable to open update archive.'];
        $previousMask = umask(0077);
        try {
            if (!$zip->extractTo($stageDir)) return ['ok'=>false, 'error'=>'Unable to extract update archive.'];
        } finally {
            umask($previousMask);
            $zip->close();
        }

        $package = $this->findPackage($stageDir);
        if ($package === null) {
            return ['ok' => false, 'error' => 'Staged package does not include a release manifest.', 'stage_dir' => $stageDir];
        }

        $updateConfig = $this->updateConfig();
        try {
            $signatureError = ReleaseSignature::verify(
                (array)$package['manifest'],
                (array)($updateConfig['release_public_keys'] ?? []),
                (bool)($updateConfig['require_signed_packages'] ?? false),
                'package-manifest'
            );
        } catch (\RuntimeException $exception) {
            $signatureError = $exception->getMessage();
        }
        if ($signatureError !== null) {
            return ['ok' => false, 'error' => $signatureError, 'stage_dir' => $stageDir];
        }
        $fileError = $this->verifyStagedFiles((string)$package['root'], (array)$package['manifest'], (bool)($updateConfig['require_signed_packages'] ?? false));
        if ($fileError !== null) {
            return ['ok' => false, 'error' => $fileError, 'stage_dir' => $stageDir];
        }

        return ['ok' => true, 'stage_dir' => $stageDir, 'manifest' => $package['manifest']];
    }

    public function apply(string $stageDir, ?array $server = null): array
    {
        $directory = $this->paths->dataPath('tmp');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) return ['ok'=>false, 'error'=>'Unable to prepare update storage.'];
        $previousMask = umask(umask() | 0022);
        try {
            $lock = fopen($directory . '/update-apply.lock', 'c+');
            if ($lock === false) return ['ok'=>false, 'error'=>'Unable to lock update storage.'];
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) return ['ok'=>false, 'error'=>'Another update is already running. Please try again later.'];
                return $this->applyLocked($stageDir, $server ?? $_SERVER);
            } finally {
                fclose($lock);
            }
        } finally {
            umask($previousMask);
        }
    }

    private function applyLocked(string $stageDir, array $server): array
    {
        if (!is_dir($stageDir)) {
            return ['ok' => false, 'error' => 'The selected staged package is no longer available. Stage the package again.'];
        }

        if (!$this->isInside($stageDir, $this->paths->dataPath('tmp'))) {
            return ['ok' => false, 'error' => 'The selected staged package is outside update storage. Stage the package again from the Updates screen.'];
        }

        $package = $this->findPackage($stageDir);
        if ($package === null) {
            return ['ok' => false, 'error' => 'Staged package does not include a valid release manifest.'];
        }
        $manifest = $package['manifest'];
        $packageRoot = $package['root'];
        $verificationError = $this->verifyPackage($package);
        if ($verificationError !== null) return ['ok'=>false, 'error'=>$verificationError];

        $files = $manifest['files'] ?? null;
        if (!is_array($files) || $files === []) {
            return ['ok' => false, 'error' => 'Release manifest does not list files to install.'];
        }

        foreach ([$this->paths->configPath('site.json'), $this->paths->dataPath('updates/installed-release.json')] as $path) {
            if (is_link($path) || is_link(dirname($path))) return ['ok'=>false, 'error'=>'Linked upgrade configuration is not supported.'];
        }
        $compatibility = new UpgradeCompatibility($this->paths);
        try {
            $compatibility->preflight($manifest);
            DeploymentPermissions::repairLegacy($this->paths->root());
        }
        catch (\Throwable $error) { return ['ok'=>false, 'error'=>$error->getMessage()]; }
        $backup = (new BackupManager($this->paths))->create();
        if (!($backup['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Unable to create pre-update backup: ' . (string)($backup['error'] ?? 'backup failed')];
        }

        $maintenance = new MaintenanceMode($this->paths);
        $maintenance->enable('Applying Batoi Press update');

        $installed = 0;
        $installedTargets = [];
        $createdDirectories = [];
        $preservedTargets = [];
        try {
            $migrations = $compatibility->migrate($manifest, $server, $installedTargets, $createdDirectories);
            foreach ($files as $file) {
                if (!is_array($file)) {
                    throw new \RuntimeException('Release manifest contains an invalid file entry.');
                }

                $sourceRelative = (string)($file['source'] ?? $file['path'] ?? '');
                $targetRelative = (string)($file['target'] ?? $file['path'] ?? '');
                $checksum = (string)($file['sha256'] ?? '');
                $source = $this->joinRelative($packageRoot, $sourceRelative);
                $target = $this->joinRelative($this->paths->root(), $targetRelative);

                if ($source === null || $target === null || !$this->isAllowedTarget($targetRelative)) {
                    throw new \RuntimeException('Release manifest contains an unsafe or unsupported file path: ' . $sourceRelative . ' → ' . $targetRelative . '. Use a compatible official package; do not disable path checks.');
                }

                if (!is_file($source)) {
                    throw new \RuntimeException('Staged file is missing: ' . $sourceRelative);
                }

                if ($checksum !== '' && hash_file('sha256', $source) !== strtolower($checksum)) {
                    throw new \RuntimeException('Checksum failed for staged file: ' . $sourceRelative);
                }

                $keepTheme = false;
                foreach ($migrations['preserved_prefixes'] as $prefix) if (str_starts_with(str_replace('\\', '/', $targetRelative), $prefix)) $keepTheme = true;
                if ($keepTheme || ($this->preserveExistingTarget($targetRelative) && is_file($target))) {
                    $preservedTargets[trim($targetRelative, '/')] = true;
                    continue;
                }

                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        throw new \RuntimeException('Unable to create deployment directory: ' . $targetRelative);
                    }
                }

                $installedTargets[$target] = is_file($target);
                $this->secureExistingTarget($target, str_replace('\\', '/', $targetRelative));
                if (!copy($source, $target)) {
                    throw new \RuntimeException('Unable to install file: ' . $targetRelative);
                }

                $installed++;
                if (str_ends_with($target, '.php') && function_exists('opcache_invalidate')) {
                    opcache_invalidate($target, true);
                }
            }

            $cacheCleared = (new Cache($this->paths))->clear();
            $healthManifest = $manifest;
            $healthManifest['files'] = array_values(array_filter((array)($manifest['files'] ?? []), static function (mixed $file) use ($preservedTargets): bool {
                if (!is_array($file)) return true;
                $target = trim((string)($file['target'] ?? $file['path'] ?? ''), '/');
                return !isset($preservedTargets[$target]);
            }));
            $health = (new UpdateHealthCheck($this->paths))->run($healthManifest);
            if (!($health['ok'] ?? false)) {
                throw new \RuntimeException('Post-update health check failed: ' . implode('; ', $health['errors'] ?? []));
            }
            $compatibility->recordInstalledRelease($manifest, $installedTargets, $createdDirectories);
            unset($migrations['preserved_prefixes']);
        } catch (\Throwable $error) {
            return $this->failAndRollback($error->getMessage(), (string)($backup['path'] ?? ''), $maintenance, $installedTargets, $createdDirectories);
        }

        $maintenance->disable();

        return [
            'ok' => true,
            'installed_files' => $installed,
            'cache_cleared' => $cacheCleared,
            'health_check' => $health,
            'backup' => $backup['path'] ?? null,
            'version' => (string)($manifest['version'] ?? ''),
            'compatibility' => $migrations,
        ];
    }

    public function stagedPackages(): array
    {
        $dirs = glob($this->paths->dataPath('tmp/update-stage-*'), GLOB_ONLYDIR) ?: [];
        $dirs = array_values(array_filter($dirs, function(string $dir): bool {
            if (is_link($dir) || preg_match('/^update-stage-\d{8}-\d{6}(?:-[a-f0-9]{6})?$/', basename($dir)) !== 1) return false;
            $package = $this->findPackage($dir);
            return $package !== null && $this->verifyPackage($package) === null;
        }));
        rsort($dirs);
        return $dirs;
    }

    private function findPackage(string $stageDir): ?array
    {
        $manifest = $this->manifestAt($stageDir);
        if ($manifest !== null) {
            return ['root' => $stageDir, 'manifest' => $manifest];
        }

        $entries = array_values(array_filter(scandir($stageDir) ?: [], static function (string $entry) use ($stageDir): bool {
            if ($entry === '.' || $entry === '..' || $entry === '.DS_Store' || $entry === '__MACOSX') {
                return false;
            }
            return is_dir($stageDir . '/' . $entry);
        }));
        if (count($entries) !== 1) {
            return null;
        }
        $root = $stageDir . '/' . $entries[0];
        $manifest = $this->manifestAt($root);
        return $manifest !== null ? ['root' => $root, 'manifest' => $manifest] : null;
    }

    private function updateConfig(): array
    {
        $path = $this->paths->configPath('update.json');
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /** Recheck current trust policy and source inventory at the install boundary. */
    private function verifyPackage(array $package): ?string
    {
        $config = $this->updateConfig();
        $manifest = $package['manifest'];
        try {
            $error = ReleaseSignature::verify($manifest, (array)($config['release_public_keys'] ?? []), (bool)($config['require_signed_packages'] ?? false), 'package-manifest');
            return $error ?? $this->verifyStagedFiles($package['root'], $manifest, (bool)($config['require_signed_packages'] ?? false) || ($manifest['trust']['signature_required'] ?? false) === true);
        } catch (\RuntimeException $error) { return $error->getMessage(); }
    }

    private function verifyStagedFiles(string $root, array $manifest, bool $checksumsRequired): ?string
    {
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || $files === []) return 'Release manifest does not list files to install.';
        foreach ($files as $file) {
            if (!is_array($file)) return 'Release manifest contains an invalid file entry.';
            $sourceRelative = (string)($file['source'] ?? $file['path'] ?? '');
            $targetRelative = (string)($file['target'] ?? $file['path'] ?? '');
            $source = $this->joinRelative($root, $sourceRelative);
            if ($source === null || $this->joinRelative($this->paths->root(), $targetRelative) === null || !$this->isAllowedTarget($targetRelative)) {
                return 'Release manifest contains an unsafe or unsupported file path: ' . $sourceRelative . ' → ' . $targetRelative . '. Use a compatible official package; do not disable path checks.';
            }
            if (!is_file($source)) return 'Staged file is missing: ' . $sourceRelative . '. Download and stage the official package again.';
            $checksum = strtolower((string)($file['sha256'] ?? ''));
            if ($checksum === '' && !$checksumsRequired) continue;
            if ($checksum === '' || !hash_equals($checksum, (string)hash_file('sha256', $source))) return 'Checksum failed for staged file: ' . $sourceRelative;
        }
        return null;
    }

    private function manifestAt(string $root): ?array
    {
        foreach (['release.json', 'batoi-press-release.json', 'manifest.json'] as $name) {
            $path = $root . '/' . $name;
            if (is_file($path)) {
                $decoded = json_decode((string)file_get_contents($path), true);
                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    private function joinRelative(string $root, string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) === 1 || preg_match('/[\x00-\x1F\x7F]/', $relative) === 1) {
            return null;
        }

        $segments = explode('/', rtrim($relative, '/'));
        if ($segments === [] || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return rtrim(str_replace('\\', '/', $root), '/') . '/' . implode('/', $segments);
    }

    private function isAllowedTarget(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        foreach ($this->allowedTargetPrefixes() as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function preserveExistingTarget(string $relative): bool
    {
        if (str_starts_with(trim(str_replace('\\', '/', $relative), '/'), 'radpress/app/')) return true;
        return in_array(trim(str_replace('\\', '/', $relative), '/'), ['radpress/config/aif.json', 'radpress/config/paths.json', 'public_html/.htaccess'], true);
    }

    private function secureExistingTarget(string $target, string $relative): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_file($target) || is_link($target)
            || !$this->isInside($target, $this->paths->root())) return;
        $stat = lstat($target);
        if ($stat === false || $stat['nlink'] !== 1) return;

        $publicPhp = str_starts_with($relative, 'public_html/') && str_ends_with(strtolower($relative), '.php');
        $mode = $stat['mode'] & 07777;
        $safeMode = $publicPhp && $mode === 0777 ? 0644 : $mode & ~($publicPhp ? 0022 : 0002);
        if ($safeMode !== $mode && !chmod($target, $safeMode)) {
            throw new \RuntimeException('Unable to set safe deployment permissions: ' . $relative);
        }
    }

    private function allowedTargetPrefixes(): array
    {
        return [
            'README.md',
            'LICENSE',
            'public_html/',
            'radpress/admin/',
            'radpress/aif/',
            'radpress/api/',
            'radpress/app/',
            'radpress/application/',
            'radpress/core/',
            'radpress/docs/',
            'radpress/helpers/',
            'radpress/mcp/',
            'radpress/security/',
            'radpress/tests/',
            'radpress/theme/',
            'radpress/uif/',
            'radpress/updates/',
            'radpress/vendor/',
            'radpress/autoload.php',
            'radpress/composer.json',
            'radpress/composer.lock',
            'radpress/config/aif.json',
            'radpress/config/update.json',
            'radpress/config/paths.json',
        ];
    }

    private function isInside(string $path, string $root): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if ($realPath === false || $realRoot === false) {
            return false;
        }

        $normalizedPath = rtrim(str_replace('\\', '/', $realPath), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        if (preg_match('/^[A-Za-z]:\//', $normalizedRoot) === 1) {
            $normalizedPath = strtolower($normalizedPath);
            $normalizedRoot = strtolower($normalizedRoot);
        }

        return $normalizedPath !== $normalizedRoot && str_starts_with($normalizedPath, $normalizedRoot . '/');
    }

    private function failAndRollback(string $error, string $backupPath, MaintenanceMode $maintenance, array $installedTargets = [], array $createdDirectories = []): array
    {
        $rollback = $backupPath !== '' ? (new RollbackManager($this->paths))->restore($backupPath) : ['ok' => false, 'error' => 'No backup was available.'];
        foreach ($installedTargets as $target => $existedBefore) {
            if ($existedBefore === false && is_file($target)) {
                unlink($target);
            }
        }
        foreach (array_reverse($createdDirectories) as $directory) {
            if (is_dir($directory) && !is_link($directory) && (scandir($directory) ?: []) === ['.', '..']) rmdir($directory);
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
