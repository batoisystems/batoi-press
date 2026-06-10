<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\Paths;

final class MediaRepository
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function count(): int
    {
        $dir = $this->paths->contentPath('media');
        return is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;
    }
}

