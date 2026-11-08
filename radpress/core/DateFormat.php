<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

/** Display preferences only; stored timestamps and machine-readable dates stay unchanged. */
final class DateFormat
{
    public const DATE_DEFAULT = 'M j, Y';
    public const TIME_DEFAULT = 'H:i';
    public const DATES = ['j F Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'd.m.Y', self::DATE_DEFAULT];
    public const TIMES = ['H:i', 'g:i A'];

    public static function validate(string $format): string
    {
        if (trim($format) === '' || strlen($format) > 80 || preg_match('/[\x00-\x1f\x7f]/', $format) || preg_match('//u', $format) !== 1) {
            throw new \RuntimeException('Date and time formats must contain 1–80 bytes, without control characters.');
        }
        if ((strlen($format) - strlen(rtrim($format, '\\'))) % 2 !== 0) {
            throw new \RuntimeException('A date or time format cannot end with an unpaired backslash.');
        }
        return $format;
    }

    public static function preference(array $site, string $kind): string
    {
        $fallback = $kind === 'time' ? self::TIME_DEFAULT : self::DATE_DEFAULT;
        try { return self::validate(is_string($site[$kind . '_format'] ?? null) ? $site[$kind . '_format'] : $fallback); }
        catch (\RuntimeException) { return $fallback; }
    }

    public static function timezone(array $site): \DateTimeZone
    {
        try { return new \DateTimeZone((string)($site['timezone'] ?? 'UTC')); }
        catch (\Exception) { return new \DateTimeZone('UTC'); }
    }

    public static function display(?string $value, array $site = [], string $kind = 'date'): string
    {
        if ($value === null || $value === '') return '';
        try {
            $date = (new \DateTimeImmutable($value, self::timezone($site)))->setTimezone(self::timezone($site));
            $format = $kind === 'datetime'
                ? self::preference($site, 'date') . ' ' . self::preference($site, 'time')
                : self::preference($site, $kind === 'time' ? 'time' : 'date');
            return $date->format($format);
        } catch (\Exception) { return $value; }
    }
}
