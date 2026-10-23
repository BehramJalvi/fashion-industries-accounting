<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_converts_to_paisa_exactly(): void
    {
        $this->assertSame(0, Money::toCents(null));
        $this->assertSame(0, Money::toCents(''));
        $this->assertSame(10000, Money::toCents('100'));
        $this->assertSame(10650, Money::toCents('106.5'));
        $this->assertSame(6973800, Money::toCents('69738.00'));
        $this->assertSame(10001, Money::toCents('100.005'));   // half-up
        $this->assertSame(10000, Money::toCents('100.004'));
        $this->assertSame(-250, Money::toCents('-2.50'));
        $this->assertSame(1010, Money::toCents(10.1));         // 10.1 is not exactly representable as a float
    }

    public function test_formats_back(): void
    {
        $this->assertSame('1234.50', Money::fromCents(123450));
        $this->assertSame('0.05', Money::fromCents(5));
        $this->assertSame('-2.50', Money::fromCents(-250));
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('12abc');
    }
}
