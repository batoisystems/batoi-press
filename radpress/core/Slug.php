<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Slug
{
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

