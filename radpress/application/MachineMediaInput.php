<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Core\AssetManager;
use Batoi\Press\Security\UploadGuard;
use InvalidArgumentException;
use RuntimeException;

/** Strict input preparation only; does not publish, persist or fetch media. */
final class MachineMediaInput
{
    public const MAX_BYTES = 786432; // 768 KiB fits the existing API/MCP JSON envelope.
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'md'];

    public static function prepare(array $input, array $uploadConfig = []): array
    {
        if (array_diff(array_keys($input), ['name', 'content_base64', 'metadata']) !== []
            || !is_string($input['name'] ?? null) || !is_string($input['content_base64'] ?? null)) {
            throw new InvalidArgumentException('Supply a filename, base64 content and optional metadata only.');
        }
        $name = $input['name'];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,179}\.(jpg|jpeg|png|gif|webp|txt|md)$/iD', $name)) {
            throw new InvalidArgumentException('Use a plain image or text filename without a directory.');
        }
        $extensions = array_values(array_intersect(self::EXTENSIONS, AssetManager::effectiveUploadExtensions((array)($uploadConfig['allowed_extensions'] ?? []))));
        $limit = min(self::MAX_BYTES, AssetManager::effectiveMaxBytes((int)($uploadConfig['max_bytes'] ?? 0)));
        $encoded = $input['content_base64'];
        if ($encoded === '' || strlen($encoded) > 4 * (int)ceil($limit / 3)) {
            throw new InvalidArgumentException('Media exceeds the configured machine upload limit.');
        }
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || base64_encode($bytes) !== $encoded || strlen($bytes) === 0 || strlen($bytes) > $limit) {
            throw new InvalidArgumentException('Supply canonical base64 content within the upload limit.');
        }
        // Inspect the entire bounded file, not only UploadGuard's initial prefix.
        if (str_contains($bytes, '<?')) throw new InvalidArgumentException('Executable file content is not allowed.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true)) throw new InvalidArgumentException('This file type is disabled by the installation.');
        if (in_array($extension, ['txt', 'md'], true)
            && (preg_match('//u', $bytes) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes))) {
            throw new InvalidArgumentException('Text media must contain valid UTF-8 without binary control characters.');
        }
        if (!class_exists(\finfo::class)) throw new RuntimeException('File MIME inspection is unavailable.');
        $temporary = tmpfile();
        if ($temporary === false) throw new RuntimeException('Unable to prepare private media inspection.');
        try {
            if (fwrite($temporary, $bytes) !== strlen($bytes)) throw new RuntimeException('Unable to inspect media.');
            $path = stream_get_meta_data($temporary)['uri'];
            $guard = new UploadGuard($extensions, $limit);
            $error = $guard->validate(['error' => UPLOAD_ERR_OK, 'name' => $name, 'size' => strlen($bytes), 'tmp_name' => $path]);
            if ($error !== null) throw new InvalidArgumentException($error);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!is_string($mime) || $mime === '') throw new RuntimeException('Unable to identify media content.');
        } finally {
            fclose($temporary);
        }
        $dimensions = null;
        if (!in_array($extension, ['txt', 'md'], true)) {
            $size = @getimagesizefromstring($bytes);
            if ($size === false || ($size['mime'] ?? '') !== $mime || $size[0] < 1 || $size[1] < 1
                || $size[0] > 8192 || $size[1] > 8192 || $size[0] * $size[1] > 16777216) {
                throw new InvalidArgumentException('Image dimensions or content are not supported.');
            }
            $dimensions = ['width' => $size[0], 'height' => $size[1]];
        }
        return ['name' => $name, 'extension' => $extension, 'bytes' => $bytes, 'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes), 'mime_type' => $mime, 'dimensions' => $dimensions,
            'metadata' => self::metadata(array_key_exists('metadata', $input) ? $input['metadata'] : [])];
    }

    public static function metadata(mixed $input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['title', 'alt', 'caption']) !== []) {
            throw new InvalidArgumentException('Media metadata accepts title, alt and caption only.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            $limit = $key === 'caption' ? 2000 : ($key === 'alt' ? 1000 : 200);
            if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Media metadata must be bounded UTF-8 text.');
            }
            $result[$key] = trim($value); // Text, never trusted HTML or instructions.
        }
        return $result;
    }
}
