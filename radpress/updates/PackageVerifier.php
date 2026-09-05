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
        return $this->zipError($file) === null;
    }

    public function zipError(string $file): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return 'The PHP ZipArchive extension is unavailable on this server.';
        }
        if (!is_file($file)) {
            return 'The uploaded update package is no longer available.';
        }
        if ((int)filesize($file) < 4) {
            return 'The uploaded ZIP is empty or incomplete. Download the official release package again.';
        }

        $handle = fopen($file, 'rb');
        $signature = $handle !== false ? fread($handle, 4) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        $validSignatures = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];
        if (!is_string($signature) || !in_array($signature, $validSignatures, true)) {
            $sample = (string)file_get_contents($file, false, null, 0, 512);
            if (preg_match('/^\s*(?:<!doctype\s+html|<html|<head|<body)/i', $sample) === 1) {
                return 'The uploaded file contains an HTML page instead of ZIP data. Download the official release ZIP again without opening or renaming it.';
            }
            return 'The uploaded file does not contain ZIP data. Download the official release package again.';
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($file);
        if ($opened !== true) {
            return 'The ZIP archive could not be opened (error code ' . (int)$opened . '). The download may be incomplete; download it again.';
        }
        $status = $zip->status;
        $zip->close();

        return $status === \ZipArchive::ER_OK
            ? null
            : 'The ZIP archive failed its integrity check (error code ' . (int)$status . '). Download it again.';
    }

    public function verifyZipEntries(string $file): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            $trimmed = rtrim($name, '/');
            $segments = explode('/', $trimmed);
            if (
                $trimmed === ''
                || str_starts_with($name, '/')
                || preg_match('/^[A-Za-z]:\//', $name) === 1
                || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
                || in_array('', $segments, true)
                || in_array('.', $segments, true)
                || in_array('..', $segments, true)
            ) {
                $zip->close();
                return false;
            }
        }

        $zip->close();
        return true;
    }
}
