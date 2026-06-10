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
            $targetRelative = trim((string)($file['target'] ?? $file['path'] ?? ''), '/');
            if ($targetRelative === '' || str_contains($targetRelative, '..')) {
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
