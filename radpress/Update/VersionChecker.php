<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

final class VersionChecker
{
    public function __construct(private readonly string $manifestUrl)
    {
    }

    public function manifestUrl(): string
    {
        return $this->manifestUrl;
    }
}

