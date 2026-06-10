<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

final class PackageVerifier
{
    public function verifyChecksum(string $file, string $sha256): bool
    {
        return is_file($file) && hash_file('sha256', $file) === strtolower($sha256);
    }

    public function verifyZip(string $file): bool
    {
        if (!class_exists(\ZipArchive::class) || !is_file($file)) {
            return false;
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($file);
        if ($opened !== true) {
            return false;
        }
        $status = $zip->status;
        $zip->close();

        return $status === \ZipArchive::ER_OK;
    }

    public function verifyZipEntries(string $file): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..')) {
                $zip->close();
                return false;
            }
        }

        $zip->close();
        return true;
    }
}
