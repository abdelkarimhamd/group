<?php

namespace App\Modules\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * A settlement (payout to the bank) as Moyasar lists it. The amounts we
 * post come from its lines; the object itself is kept for the record.
 */
final class ProviderSettlement
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $currency,
        public readonly ?CarbonImmutable $settledAt,
        public readonly array $raw,
    ) {}
}
