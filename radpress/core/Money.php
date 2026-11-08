<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;

/** Decimal input and minor-unit arithmetic; never convert money through floats. */
final class Money
{
    public const MAX_MINOR = 999999999999;
    public const CURRENCIES = ['AED'=>2, 'AUD'=>2, 'BHD'=>3, 'CAD'=>2, 'CHF'=>2, 'EUR'=>2, 'GBP'=>2, 'HKD'=>2, 'INR'=>2, 'JPY'=>0, 'KRW'=>0, 'KWD'=>3, 'NZD'=>2, 'OMR'=>3, 'SGD'=>2, 'USD'=>2];

    public static function exponent(string $currency): int
    {
        if (PHP_INT_SIZE < 8) throw new RuntimeException('Commerce requires a 64-bit PHP runtime.');
        return self::CURRENCIES[strtoupper($currency)] ?? throw new RuntimeException('Select a supported currency.');
    }

    public static function parse(string $decimal, string $currency): int
    {
        $exponent = self::exponent($currency);
        if (preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,3}))?$/D', trim($decimal), $parts) !== 1) throw new RuntimeException('Enter a non-negative decimal price without separators.');
        $fraction = $parts[2] ?? '';
        // Historic catalogues used two decimals even for zero-decimal currencies.
        if (strlen($fraction) > $exponent && trim(substr($fraction, $exponent), '0') !== '') throw new RuntimeException('Price has too many decimal places for ' . strtoupper($currency) . '.');
        $minor = (int)$parts[1] * (10 ** $exponent) + (int)str_pad(substr($fraction, 0, $exponent), $exponent, '0');
        self::check($minor);
        return $minor;
    }

    public static function format(int $minor, string $currency): string
    {
        self::check($minor);
        $exponent = self::exponent($currency);
        if ($exponent === 0) return (string)$minor;
        $digits = str_pad((string)$minor, $exponent + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    public static function add(int $left, int $right): int
    {
        self::check($left); self::check($right);
        if ($right > self::MAX_MINOR - $left) throw new RuntimeException('Money total exceeds the supported limit.');
        return $left + $right;
    }

    public static function multiply(int $minor, int $quantity): int
    {
        self::check($minor);
        if ($quantity < 1 || $quantity > 999 || $minor > intdiv(self::MAX_MINOR, $quantity)) throw new RuntimeException('Invalid quantity or money total.');
        return $minor * $quantity;
    }

    private static function check(int $minor): void
    {
        if ($minor < 0 || $minor > self::MAX_MINOR) throw new RuntimeException('Money amount exceeds the supported limit.');
    }
}
