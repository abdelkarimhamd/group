<?php

namespace App\Modules\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * One settlement line. `amount` is net of the fee and negative for money
 * going back (refunds, chargebacks); `fee` includes its VAT (`tax`).
 */
final class ProviderSettlementLine
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $paymentId,
        public readonly int $amountHalalas,
        public readonly int $feeHalalas,
        public readonly int $taxHalalas,
        public readonly ?int $paymentAmountHalalas,
        public readonly ?CarbonImmutable $transactedAt,
        public readonly array $raw,
    ) {}
}
