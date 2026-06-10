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
        $dir = $this->paths->storagePath('cache');
        $files = is_dir($dir) ? glob($dir . '/*') ?: [] : [];

        return [
            'path' => $dir,
            'files' => count($files),
            'writable' => is_dir($dir) && is_writable($dir),
        ];
    }
}

