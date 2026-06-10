<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Validator
{
    public static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

