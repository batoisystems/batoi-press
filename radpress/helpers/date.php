<?php
declare(strict_types=1);

function bp_date(?string $value, ?array $site = null): string
{
    return \Batoi\Press\Core\DateFormat::display($value, $site ?? ($GLOBALS['bp_date_settings'] ?? []));
}

function bp_time(?string $value, ?array $site = null): string
{
    return \Batoi\Press\Core\DateFormat::display($value, $site ?? ($GLOBALS['bp_date_settings'] ?? []), 'time');
}
