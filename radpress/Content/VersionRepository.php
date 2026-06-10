<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\Paths;

final class VersionRepository
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function count(): int
    {
        $dir = $this->paths->storagePath('versions');
        return is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;
    }
}

