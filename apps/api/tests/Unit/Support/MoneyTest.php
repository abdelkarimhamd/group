<?php

namespace Tests\Unit\Support;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_does_arithmetic_in_minor_units(): void
    {
        $price = Money::sar(4_900);

        $this->assertSame(19_600, $price->times(4)->minor);
        $this->assertSame(9_800, $price->plus($price)->minor);
        $this->assertSame(0, $price->minus($price)->minor);
        $this->assertTrue($price->minus($price)->isZero());
        $this->assertTrue(Money::sar(-1)->isNegative());
    }

    #[Test]
    public function it_compares_amounts(): void
    {
        $low = Money::sar(4_900);
        $high = Money::sar(6_900);

        $this->assertTrue($high->greaterThan($low));
        $this->assertTrue($low->lessThan($high));
        $this->assertTrue($low->equals(Money::sar(4_900)));
        $this->assertFalse($low->equals(Money::of(4_900, Currency::USD)));
        $this->assertSame($low, $high->min($low));
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::sar(100)->plus(Money::of(100, Currency::USD));
    }

    #[Test]
    public function it_refuses_to_overflow_into_a_float(): void
    {
        $this->expectException(OverflowException::class);

        Money::sar(PHP_INT_MAX)->times(2);
    }

    /**
     * Cases from the build spec's VAT rule: net = round(total x 100 / 115), VAT = total - net.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function vatCases(): array
    {
        return [
            'spec example: 2 units at 49 SAR' => [9_800, 8_522, 1_278],
            'one unit at 49 SAR' => [4_900, 4_261, 639],
            'one unit at 69 SAR' => [6_900, 6_000, 900],
            'one halala' => [1, 1, 0],
            'zero' => [0, 0, 0],
            'exact half rounds up: 23 halalas' => [23, 20, 3],
            'credit note mirrors the invoice' => [-9_800, -8_522, -1_278],
        ];
    }

    #[Test]
    #[DataProvider('vatCases')]
    public function it_splits_a_vat_inclusive_amount(int $total, int $net, int $vat): void
    {
        $split = Money::sar($total)->splitVatInclusive(1_500);

        $this->assertSame($net, $split['net']->minor);
        $this->assertSame($vat, $split['vat']->minor);
        $this->assertSame($total, $split['net']->plus($split['vat'])->minor);
    }

    #[Test]
    public function it_formats_for_display_and_json(): void
    {
        $this->assertSame('49.00 SAR', Money::sar(4_900)->format());
        $this->assertSame('0.05 SAR', Money::sar(5)->format());
        $this->assertSame('-12.34 SAR', Money::sar(-1_234)->format());

        $this->assertSame(
            ['amount_minor' => 4_900, 'currency' => 'SAR', 'formatted' => '49.00 SAR'],
            Money::sar(4_900)->jsonSerialize(),
        );
    }
}
