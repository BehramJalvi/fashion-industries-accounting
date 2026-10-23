<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on integer minor units. Never use floats to compare or add amounts.
 * Money = paisa (scale 2), quantities = thousandths (scale 3), unit costs = ten-thousandths (scale 4).
 * Works without the bcmath extension.
 */
class Money
{
    /** "1234.5" | 1234.5 | 1234 | null  =>  scaled integer. Rounds half-up at the digit after $scale. */
    public static function toScaled(string|int|float|null $value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $s = is_float($value) ? number_format($value, $scale + 2, '.', '') : trim((string) $value);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m)) {
            throw new InvalidArgumentException("Not a valid number: {$s}");
        }

        $fraction = str_pad($m[3] ?? '', $scale + 1, '0');
        $units = ((int) $m[2]) * (10 ** $scale) + (int) substr($fraction, 0, $scale) + ((int) $fraction[$scale] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$units : $units;
    }

    public static function fromScaled(int $units, int $scale): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        $base = 10 ** $scale;

        return sprintf('%s%d.%0' . $scale . 'd', $sign, intdiv($units, $base), $units % $base);
    }

    /** "1234.5" => 123450 */
    public static function toCents(string|int|float|null $value): int
    {
        return self::toScaled($value, 2);
    }

    /** 123450 => "1234.50" */
    public static function fromCents(int $cents): string
    {
        return self::fromScaled($cents, 2);
    }

    /** quantity x unit cost in paisa, rounded half-up. */
    public static function lineCents(string|int|float $qty, string|int|float $unitCost): int
    {
        // qty (1e-3) x unit cost (1e-4) = 1e-7 rupees; /1e5 => paisa
        return intdiv(self::toScaled($qty, 3) * self::toScaled($unitCost, 4) + 50000, 100000);
    }

    /** $rate percent of an amount in paisa, rounded half-up (rate kept to 2 decimals). */
    public static function percentOf(int $cents, string|int|float $rate): int
    {
        return intdiv($cents * self::toScaled($rate, 2) + 5000, 10000);
    }

    /** 123450 => "1,234.50", -250 => "-2.50" (for screens and CSV) */
    public static function display(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2);
    }
}
