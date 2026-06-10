<?php
declare(strict_types=1);

function bp_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bp_attr(string $value): string
{
    return bp_esc($value);
}

