<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Cache
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function status(): array
    {
        $dir = $this->paths->dataPath('cache');
        $files = is_dir($dir) ? glob($dir . '/*') ?: [] : [];

        return [
            'path' => $dir,
            'files' => count($files),
            'writable' => is_dir($dir) && is_writable($dir),
        ];
    }

    public function clear(): int
    {
        $dir = $this->paths->dataPath('cache');
        if (!is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
