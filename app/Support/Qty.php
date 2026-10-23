<?php

namespace App\Support;

/** Quantities are held as integer thousandths so stock arithmetic is exact. */
class Qty
{
    public static function toMilli(string|int|float|null $qty): int
    {
        return Money::toScaled($qty, 3);
    }

    public static function fromMilli(int $milli): string
    {
        return Money::fromScaled($milli, 3);
    }
}
