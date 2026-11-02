<?php

namespace App\Modules\Payments\Gateway;

/**
 * A payment as the provider reports it. Statuses use Moyasar's words:
 * initiated, paid, authorized, failed, voided, captured, refunded.
 */
final class ProviderPayment
{
    /**
     * @param  array<string, mixed>  $raw  The provider's response, for the record.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly int $amountHalalas,
        public readonly string $currency,
        public readonly ?string $sourceType,
        public readonly ?string $scheme,
        public readonly ?string $cardLast4,
        public readonly int $capturedHalalas,
        public readonly ?string $paymentId,
        public readonly array $raw,
    ) {}
}
