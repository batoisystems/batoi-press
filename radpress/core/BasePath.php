<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class BasePath
{
    public static function detect(array $server): string
    {
        $physical = self::fromPhysicalPaths(
            (string)($server['DOCUMENT_ROOT'] ?? ''),
            (string)($server['SCRIPT_FILENAME'] ?? '')
        );
        if ($physical !== null) {
            return $physical;
        }

        foreach (['SCRIPT_NAME', 'PHP_SELF', 'ORIG_SCRIPT_NAME'] as $key) {
            $script = self::normalize((string)($server[$key] ?? ''));
            if ($script === '' || !str_ends_with(strtolower($script), '.php')) {
                continue;
            }
            return self::urlBase(dirname($script));
        }

        return '';
    }

    private static function fromPhysicalPaths(string $documentRoot, string $scriptFilename): ?string
    {
        $root = rtrim(self::normalize($documentRoot), '/');
        $script = self::normalize($scriptFilename);
        if ($root === '' || $script === '' || !str_ends_with(strtolower($script), '.php')) {
            return null;
        }

        $directory = rtrim(dirname($script), '/');
        $caseInsensitive = preg_match('/^[a-z]:\//i', $root) === 1;
        $comparedRoot = $caseInsensitive ? strtolower($root) : $root;
        $comparedDirectory = $caseInsensitive ? strtolower($directory) : $directory;
        if ($comparedDirectory !== $comparedRoot && !str_starts_with($comparedDirectory, $comparedRoot . '/')) {
            return null;
        }

        return self::urlBase(substr($directory, strlen($root)));
    }

    private static function normalize(string $path): string
    {
        return preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))) ?? '';
    }

    private static function urlBase(string $path): string
    {
        $path = trim(self::normalize($path), '/');
        return $path === '' || $path === '.' ? '' : '/' . $path;
    }
}
