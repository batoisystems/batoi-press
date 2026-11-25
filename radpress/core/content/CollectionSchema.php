<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use InvalidArgumentException;

final class CollectionSchema
{
    public const TYPES = ['text' => 'Text', 'multiline' => 'Multiline text', 'number' => 'Number', 'date' => 'Date', 'choice' => 'Choice', 'url' => 'URL', 'media' => 'Media'];
    public const MAX_FIELDS = 24;
    public const MAX_VALUE = 2000;

    public static function key(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) === 1;
    }

    public static function text(string $value, int $limit): string
    {
        if (strlen($value) > $limit || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Text is too long or contains invalid characters.');
        }
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    public static function fields(array $submitted): array
    {
        if (count($submitted) > self::MAX_FIELDS) throw new InvalidArgumentException('A collection supports up to ' . self::MAX_FIELDS . ' fields.');
        $fields = [];
        foreach ($submitted as $input) {
            if (!is_array($input)) throw new InvalidArgumentException('Invalid field definition.');
            foreach ($input as $part) if (!is_scalar($part)) throw new InvalidArgumentException('Invalid field settings.');
            $key = (string)($input['key'] ?? '');
            $label = self::text((string)($input['label'] ?? ''), 120);
            if ($key === '' && $label === '') continue;
            $type = (string)($input['type'] ?? '');
            if (!self::key($key) || $label === '' || !isset(self::TYPES[$type]) || isset($fields[$key])) {
                throw new InvalidArgumentException('Each field needs a unique lowercase key, a label, and a supported type.');
            }
            $choices = $type === 'choice' ? preg_split('/\R/u', (string)($input['choices'] ?? '')) : [];
            $choices = array_values(array_unique(array_filter(array_map(static fn (string $value): string => self::text($value, 120), $choices ?: []), static fn (string $value): bool => $value !== '')));
            if ($type === 'choice' && (count($choices) < 1 || count($choices) > 100)) throw new InvalidArgumentException('A choice field requires 1–100 options, one per line.');
            $fields[$key] = ['label' => $label, 'type' => $type, 'choices' => $choices];
            foreach (['required', 'unique', 'public'] as $flag) $fields[$key][$flag] = !empty($input[$flag]);
        }
        if ($fields === []) throw new InvalidArgumentException('Add at least one field.');
        return $fields;
    }

    public static function value(array $field, mixed $input): ?string
    {
        if ($input instanceof \DateTimeInterface) $input = $input->format('Y-m-d');
        if ($input !== null && !is_scalar($input)) throw new InvalidArgumentException('Expected a single value.');
        $value = self::text((string)$input, self::MAX_VALUE);
        if ($value === '') {
            if ($field['required']) throw new InvalidArgumentException('A value is required.');
            return null;
        }
        if ($field['type'] !== 'multiline' && str_contains($value, "\n")) throw new InvalidArgumentException('Use a single line of text.');
        return match ($field['type']) {
            'number' => self::number($value),
            'date' => self::date($value),
            'choice' => in_array($value, $field['choices'], true) ? $value : throw new InvalidArgumentException('Use an existing choice.'),
            'url', 'media' => self::url($value, $field['type'] === 'media'),
            default => $value,
        };
    }

    public static function row(array $fields, array $input): array
    {
        $values = [];
        foreach ($fields as $key => $field) {
            try { $values[$key] = self::value($field, $input[$key] ?? null); }
            catch (InvalidArgumentException $error) { throw new InvalidArgumentException($field['label'] . ' — ' . $error->getMessage()); }
        }
        return $values;
    }

    public static function unique(array $fields, array $records): void
    {
        foreach ($fields as $key => $field) {
            if (!$field['unique']) continue;
            $seen = [];
            foreach ($records as $record) {
                $value = $record['values'][$key] ?? null;
                if ($value === null) continue;
                if (isset($seen[$value])) throw new InvalidArgumentException($field['label'] . ' must be unique across the collection, including archived records.');
                $seen[$value] = true;
            }
        }
    }

    private static function number(string $value): string
    {
        if (preg_match('/^([+-]?)(\d{1,20})(?:\.(\d{1,20}))?[eE]([+-]?\d{1,3})$/D', $value, $scientific)) {
            $digits = $scientific[2] . $scientific[3];
            $point = strlen($scientific[2]) + (int)$scientific[4];
            if ($point <= 0) $decimal = '0.' . str_repeat('0', -$point) . $digits;
            elseif ($point >= strlen($digits)) $decimal = str_pad($digits, $point, '0');
            else $decimal = substr($digits, 0, $point) . '.' . substr($digits, $point);
            $value = $scientific[1] . (str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal);
        }
        if (preg_match('/^([+-]?)([0-9]{1,12})(?:\.([0-9]{1,8}))?$/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Use a decimal number with up to 12 whole digits and 8 decimal places.');
        }
        $whole = ltrim($parts[2], '0') ?: '0';
        $fraction = rtrim($parts[3] ?? '', '0');
        return ($parts[1] === '-' && ($whole !== '0' || $fraction !== '') ? '-' : '') . $whole . ($fraction !== '' ? '.' . $fraction : '');
    }

    private static function date(string $value): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new InvalidArgumentException('Use a valid date in YYYY-MM-DD format.');
        }
        return $value;
    }

    private static function url(string $value, bool $media): string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $value) || str_contains($value, '\\')) throw new InvalidArgumentException('Use a valid URL.');
        if ($media && preg_match('#^/(assets|media)/[a-zA-Z0-9/_%.~-]+$#D', $value) && !preg_match('#(?:^|/)\.\.?(/|$)|[\x00-\x20\x7f\\\\]#', rawurldecode($value))) return $value;
        if (!$media && str_starts_with($value, '/') && !str_starts_with($value, '//') && !preg_match('#^/(%2f|%5c)#i', $value)) return $value;
        if (!$media && preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) return $value;
        throw new InvalidArgumentException($media ? 'Use a site Media Library URL.' : 'Use an HTTP, HTTPS, or site-relative URL.');
    }
}
