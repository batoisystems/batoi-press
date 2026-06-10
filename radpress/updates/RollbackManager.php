<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use Batoi\Press\Core\Paths;
use ZipArchive;

final class RollbackManager
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function restore(string $backupZip): array
    {
        if (!class_exists(ZipArchive::class) || !is_file($backupZip)) {
            return ['ok' => false, 'error' => 'Backup ZIP is not available.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($backupZip) !== true) {
            return ['ok' => false, 'error' => 'Unable to open backup ZIP.'];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === 'backup-manifest.json' || str_contains($name, '..') || str_starts_with($name, '/')) {
                continue;
            }
            $target = $this->paths->root() . '/' . $name;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents !== false) {
                file_put_contents($target, $contents, LOCK_EX);
            }
        }
        $zip->close();

        return ['ok' => true, 'path' => $backupZip];
    }
}
