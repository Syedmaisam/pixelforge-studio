<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in this app is stored as integer NANODOLLARS.
 *
 *   1 USD = 1,000,000,000 nanodollars
 *
 * Pixelforge reports costs with exactly 9 decimals ("0.046125000"),
 * so one nanodollar is the smallest amount it can ever charge us.
 * Integers never drift the way floats do, so every sum stays exact.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /** Whole dollars to nanodollars: Money::dollars(5) === 5_000_000_000 */
    public static function dollars(int $dollars): int
    {
        return $dollars * self::NANOS_PER_DOLLAR;
    }

    /** A Pixelforge money string to nanodollars: "0.046125000" => 46_125_000 */
    public static function parseDollars(string $amount): int
    {
        return self::parseDecimal($amount, 9);
    }

    /**
     * A Pixelforge rate (US dollars per 1 million tokens) to nanodollars per token.
     *
     *   $12.50 per 1,000,000 tokens
     *   = 12.50 * 1,000,000,000 / 1,000,000 nanodollars per token
     *   = 12.50 * 1,000
     *   = 12,500 nanodollars per token
     */
    public static function ratePerTokenInNanos(string $ratePerMillionTokens): int
    {
        return self::parseDecimal($ratePerMillionTokens, 3);
    }

    /** Nanodollars to a Pixelforge-style string: 46_125_000 => "0.046125000" */
    public static function toDecimalString(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        $whole = intdiv($nanos, self::NANOS_PER_DOLLAR);
        $fraction = str_pad((string) ($nanos % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);

        return "{$sign}{$whole}.{$fraction}";
    }

    /**
     * Nanodollars to a display string, rounded half up: 420_393_750 => "$0.4204".
     * Only for showing to people. Never parse this back or store it.
     */
    public static function format(int $nanos, int $decimals = 4): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        $step = 10 ** (9 - $decimals);                     // nanos per displayed unit
        $rounded = intdiv($nanos + intdiv($step, 2), $step); // round half up

        $whole = intdiv($rounded, 10 ** $decimals);
        $fraction = str_pad((string) ($rounded % 10 ** $decimals), $decimals, '0', STR_PAD_LEFT);

        return $decimals > 0 ? "{$sign}\${$whole}.{$fraction}" : "{$sign}\${$whole}";
    }

    /**
     * Parse a plain decimal string ("12.50") into an integer scaled by 10^$scale,
     * using only string operations, so no float ever touches the value.
     * Throws if the value has more precision than $scale allows.
     */
    private static function parseDecimal(string $value, int $scale): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', trim($value), $parts)) {
            throw new InvalidArgumentException("Not a valid decimal amount: [{$value}]");
        }

        $whole = $parts[1];
        $fraction = rtrim($parts[2] ?? '', '0');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("[{$value}] has more than {$scale} decimals and would lose precision.");
        }

        return (int) $whole * 10 ** $scale + (int) str_pad($fraction, $scale, '0');
    }
}
