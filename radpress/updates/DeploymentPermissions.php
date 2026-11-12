<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DeploymentPermissions
{
    public static function repairLegacy(string $root): void
    {
        if (DIRECTORY_SEPARATOR !== '/') return;

        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        self::repairEntry($root . '/public_html', $owner);

        $private = $root . '/radpress';
        if (!is_readable($private) || !is_dir($private) || is_link($private)) return;

        self::repairEntry($private, $owner);
        $entries = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !$entry->isLink() && ($entry->isDir() || $entry->isFile())
        );
        foreach (new RecursiveIteratorIterator($entries, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD) as $entry) {
            self::repairEntry($entry->getPathname(), $owner);
        }
    }

    private static function repairEntry(string $path, ?int $owner): void
    {
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 07777) !== 0777 || ($owner !== null && $stat['uid'] !== $owner)) return;
        $type = $stat['mode'] & 0170000;

        // The owner keeps write access. A different owner must repair the path on the host.
        if ($type === 0040000) {
            @chmod($path, 0755);
        } elseif ($type === 0100000 && $stat['nlink'] === 1) {
            @chmod($path, 0644);
        }
    }
}
