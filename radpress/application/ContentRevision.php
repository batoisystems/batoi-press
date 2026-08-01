<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

final class ContentRevision
{
    public static function for(array $record): string
    {
        self::sortRecursive($record);
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return 'sha256:' . hash('sha256', is_string($encoded) ? $encoded : serialize($record));
    }

    private static function sortRecursive(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
        unset($item);
    }
}
