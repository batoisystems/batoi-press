<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use Batoi\Press\Core\Paths;
use ZipArchive;

final class BackupManager
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function create(): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'ZipArchive is not available.'];
        }

        $stamp = date('Ymd-His');
        $zipPath = $this->paths->dataPath('backups/batoi-press-backup-' . $stamp . '.zip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Unable to create backup ZIP.'];
        }

        foreach ($this->backupRoots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach ($this->files($root) as $file) {
                $relative = ltrim(substr($file, strlen($this->paths->root())), '/');
                $zip->addFile($file, $relative);
            }
        }

        $zip->addFromString('backup-manifest.json', json_encode([
            'created_at' => date(DATE_ATOM),
            'type' => 'batoi-press-update-backup',
            'paths' => array_map(fn (string $path): string => ltrim(substr($path, strlen($this->paths->root())), '/'), $this->backupRoots()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return ['ok' => true, 'path' => $zipPath];
    }

    private function backupRoots(): array
    {
        return [
            $this->paths->configPath(),
            $this->paths->contentPath(),
            $this->paths->themePath(),
            $this->paths->get('app'),
        ];
    }

    private function files(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $files = array_merge($files, $this->files($path));
            } elseif (is_file($path)) {
                $files[] = $path;
            }
        }
        return $files;
    }
}
