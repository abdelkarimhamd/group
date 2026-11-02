<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

/**
 * An immutable amount of money held as an integer number of minor units
 * (halalas for SAR). Floats are never used for money anywhere in the platform.
 */
final class Money implements JsonSerializable
{
    private function __construct(
        public readonly int $minor,
        public readonly Currency $currency,
    ) {}

    public static function of(int $minor, Currency $currency): self
    {
        return new self($minor, $currency);
    }

    public static function sar(int $halalas): self
    {
        return new self($halalas, Currency::SAR);
    }

    public static function zero(Currency $currency = Currency::SAR): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor + $other->minor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor - $other->minor), $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self(self::checked($this->minor * $quantity), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    /**
     * Split a VAT-inclusive amount into its net and VAT parts.
     *
     * Rule from the build spec: net = round(total x 10000 / (10000 + rate))
     * to the nearest minor unit, half away from zero; VAT = total - net.
     * Example: 9,800 halalas at 15% gives net 8,522 and VAT 1,278.
     *
     * @return array{net: self, vat: self}
     */
    public function splitVatInclusive(int $rateBp): array
    {
        if ($rateBp < 0) {
            throw new InvalidArgumentException('VAT rate cannot be negative.');
        }

        // abs(PHP_INT_MIN) is a float, so check it like every other operation.
        $absolute = self::checked(abs($this->minor));
        $scaled = self::checked($absolute * 10_000);
        $denominator = 10_000 + $rateBp;

        // Round half away from zero using the remainder, so no step can overflow.
        $net = intdiv($scaled, $denominator);
        if (2 * ($scaled % $denominator) >= $denominator) {
            $net++;
        }

        if ($this->minor < 0) {
            $net = -$net;
        }

        return [
            'net' => new self($net, $this->currency),
            'vat' => new self($this->minor - $net, $this->currency),
        ];
    }

    /**
     * A plain display string such as "49.00 SAR". Customer-facing
     * formatting in Arabic belongs to the clients, not the API.
     */
    public function format(): string
    {
        $decimals = $this->currency->decimals();
        $divisor = 10 ** $decimals;
        $sign = $this->minor < 0 ? '-' : '';

        // Split before taking the absolute value: abs(PHP_INT_MIN) would be a float.
        return sprintf(
            '%s%d.%0'.$decimals.'d %s',
            $sign,
            abs(intdiv($this->minor, $divisor)),
            abs($this->minor % $divisor),
            $this->currency->value,
        );
    }

    /**
     * @return array{amount_minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount_minor' => $this->minor,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s without an explicit conversion.',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }

    /**
     * PHP silently turns integer overflow into a float. Money must never do that.
     */
    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw new OverflowException('Money amount is outside the integer range.');
        }

        return $value;
    }
}
