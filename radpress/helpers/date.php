<?php
declare(strict_types=1);

function bp_date(?string $value): string
{
    if (!$value) {
        return '';
    }

    $time = strtotime($value);
    return $time ? date('M j, Y', $time) : $value;
}

