<?php

namespace App\Modules\Pricing;

use App\Support\Money\Money;

/**
 * One price tier: a VAT-inclusive unit price for campaign totals from
 * `minQty` to `maxQty`, both inclusive.
 */
final class Tier
{
    public function __construct(
        public readonly int $minQty,
        public readonly int $maxQty,
        public readonly int $unitPriceHalalas,
        public readonly ?int $id = null,
    ) {}

    public function contains(int $qty): bool
    {
        return $qty >= $this->minQty && $qty <= $this->maxQty;
    }

    public function unitPrice(): Money
    {
        return Money::sar($this->unitPriceHalalas);
    }
}
