<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class View
{
    public static function text(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

