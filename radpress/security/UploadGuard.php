<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

final class UploadGuard
{
    public function __construct(
        private readonly array $allowedExtensions,
        private readonly int $maxBytes
    ) {
    }

    public function validate(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'Upload failed.';
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxBytes) {
            return 'File size is not allowed.';
        }

        $name = (string)($file['name'] ?? '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $this->allowedExtensions, true)) {
            return 'File type is not allowed.';
        }

        if (in_array($extension, ['php', 'phtml', 'phar', 'html', 'htm', 'exe', 'sh', 'bat', 'cmd'], true)) {
            return 'Server-executable uploads are not allowed.';
        }

        return null;
    }

    public function safeName(string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $base = strtolower(pathinfo($original, PATHINFO_FILENAME));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'file';
        $base = trim($base, '-');

        return $base . '-' . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '');
    }
}
