<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

final class PackageVerifier
{
    public function verifyChecksum(string $file, string $sha256): bool
    {
        return is_file($file) && hash_file('sha256', $file) === strtolower($sha256);
    }
}

