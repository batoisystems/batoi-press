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

        $temporary = (string)($file['tmp_name'] ?? '');
        if ($temporary !== '' && is_file($temporary)) {
            $signatureError = $this->validateSignature($temporary, $extension);
            if ($signatureError !== null) {
                return $signatureError;
            }
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

    private function validateSignature(string $path, string $extension): ?string
    {
        $prefix = file_get_contents($path, false, null, 0, 4096);
        if (!is_string($prefix)) {
            return 'Unable to inspect uploaded file.';
        }
        if (preg_match('/<\?(?:php|=)/i', $prefix) === 1) {
            return 'Uploaded file content is not allowed.';
        }
        if (in_array($extension, ['txt', 'md', 'css', 'js', 'mjs'], true) && str_contains($prefix, "\0")) {
            return 'Uploaded text file contains binary content.';
        }
        $mime = '';
        if (class_exists(\finfo::class)) {
            $info = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $info->file($path);
            $mime = is_string($detected) ? strtolower($detected) : '';
        }
        $allowed = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'], 'md' => ['text/plain', 'text/markdown'], 'css' => ['text/plain', 'text/css'],
            'js' => ['text/plain', 'application/javascript', 'text/javascript'], 'mjs' => ['text/plain', 'application/javascript', 'text/javascript'],
            'mp3' => ['audio/mpeg', 'audio/mp3'], 'wav' => ['audio/wav', 'audio/x-wav'], 'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
            'm4a' => ['audio/mp4', 'video/mp4'], 'mp4' => ['video/mp4'], 'webm' => ['video/webm', 'audio/webm'],
            'mov' => ['video/quicktime'], 'zip' => ['application/zip', 'application/x-zip-compressed'],
        ];
        if ($mime !== '' && isset($allowed[$extension]) && !in_array($mime, $allowed[$extension], true)) {
            return 'File content does not match its extension.';
        }
        return null;
    }
}
