<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use Batoi\Press\Core\Paths;

final class UpdateHealthCheck
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function run(array $manifest): array
    {
        $errors = [];

        foreach ($this->requiredFiles() as $file) {
            if (!is_file($this->paths->root() . '/' . $file)) {
                $errors[] = 'Missing required file: ' . $file;
            }
        }

        foreach ($this->requiredJsonFiles() as $file) {
            $path = $this->paths->root() . '/' . $file;
            $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
            if (!is_array($decoded)) {
                $errors[] = 'Invalid JSON file: ' . $file;
            }
        }

        foreach ($this->requiredWritableDirs() as $dir) {
            $path = $this->paths->root() . '/' . $dir;
            if (!is_dir($path) || !is_writable($path)) {
                $errors[] = 'Directory is not writable: ' . $dir;
            }
        }

        foreach (($manifest['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $targetRelative = trim(str_replace('\\', '/', (string)($file['target'] ?? $file['path'] ?? '')), '/');
            if ($targetRelative === '' || in_array('..', explode('/', $targetRelative), true)) {
                $errors[] = 'Invalid manifest target during health check.';
                continue;
            }

            $target = $this->paths->root() . '/' . $targetRelative;
            if (!is_file($target)) {
                $errors[] = 'Installed file is missing: ' . $targetRelative;
                continue;
            }

            $checksum = (string)($file['sha256'] ?? '');
            if ($checksum !== '' && hash_file('sha256', $target) !== strtolower($checksum)) {
                $errors[] = 'Installed file checksum failed: ' . $targetRelative;
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /** Reuse updater runtime-directory checks without exposing absolute paths. */
    public function hosting(): array
    {
        $rows = [];
        $add = static function (string $name, bool $ok, string $severity, string $help) use (&$rows): void {
            $rows[] = ['name' => $name, 'status' => $ok ? 'OK' : $severity, 'help' => $ok ? 'Available' : $help];
        };
        $add('PHP 8.1+', version_compare(PHP_VERSION, '8.1', '>='), 'Blocking', 'Upgrade the web PHP runtime.');
        foreach (['json', 'session', 'hash', 'filter', 'fileinfo', 'dom', 'mbstring'] as $extension) $add('PHP ' . $extension, extension_loaded($extension), 'Blocking', 'Enable this extension in web PHP.');
        $add('Sodium for signed updates', function_exists('sodium_crypto_sign_verify_detached'), 'Blocks signed updates', 'Enable Sodium; signature verification must remain enabled.');
        $add('ZIP for package operations', class_exists('ZipArchive'), 'Blocks ZIP operations', 'Enable PHP ZIP for updates and exports.');
        $add('OPcache', function_exists('opcache_get_status') && @opcache_get_status(false) !== false, 'Recommendation', 'Enable OPcache in web PHP for lower compilation cost.');
        foreach (['Config' => $this->paths->configPath(), 'Content' => $this->paths->contentPath(), 'Data' => $this->paths->dataPath()] as $name => $path) $add($name . ' writable', is_dir($path) && is_writable($path), 'Blocking', 'Ask the host to correct write permissions.');
        foreach ($this->requiredWritableDirs() as $dir) {
            $relative = substr($dir, strlen('radpress/data/'));
            $path = $this->paths->dataPath($relative);
            $add('Runtime ' . $relative . ' writable', is_dir($path) && is_writable($path), 'Blocks related operations', 'Create the runtime directory and grant web PHP write access.');
        }
        foreach (['imagewebp' => 'WebP derivatives', 'imageavif' => 'AVIF derivatives'] as $function => $name) $add($name, function_exists($function), 'Optional', 'Install GD with this encoder; originals remain usable.');
        return $rows;
    }

    private function requiredFiles(): array
    {
        return [
            'public_html/index.php',
            'public_html/admin.php',
            'radpress/autoload.php',
        ];
    }

    private function requiredJsonFiles(): array
    {
        return [
            'radpress/config/paths.json',
            'radpress/config/site.json',
            'radpress/config/security.json',
            'radpress/config/update.json',
        ];
    }

    private function requiredWritableDirs(): array
    {
        return [
            'radpress/data/cache',
            'radpress/data/log',
            'radpress/data/sessions',
            'radpress/data/tmp',
        ];
    }
}
