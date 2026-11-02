<?php

namespace Tests\Fakes;

use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\GatewayResult;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Gateway\ProviderPaymentNotFound;
use App\Modules\Payments\Models\PaymentAuthorization;

/**
 * A Moyasar stand-in whose payments the test writes, for cases the fake
 * gateway cannot produce (wrong amount, another method, still processing).
 */
final class ScriptedMoyasarGateway implements PaymentGateway
{
    /** @var array<string, ProviderPayment> */
    private array $payments = [];

    /** @var list<string> */
    public array $voids = [];

    /** @var list<string> */
    public array $fetches = [];

    /** While true, every call fails as if Moyasar could not be reached. */
    public bool $down = false;

    /**
     * Moyasar reports this payment, made through the form for $payment.
     */
    public function report(string $id, PaymentAuthorization $payment, string $status = 'authorized', ?int $amount = null, string $currency = 'SAR', string $source = 'creditcard', ?string $scheme = 'mada'): void
    {
        $this->payments[$id] = new ProviderPayment(
            id: $id,
            status: $status,
            amountHalalas: $amount ?? $payment->authorized_amount_halalas,
            currency: $currency,
            sourceType: $source,
            scheme: $scheme,
            cardLast4: '4242',
            capturedHalalas: $status === 'paid' ? ($amount ?? $payment->authorized_amount_halalas) : 0,
            paymentId: $payment->uuid,
            raw: ['id' => $id, 'status' => $status],
        );
    }

    public function fetch(string $providerPaymentId): ProviderPayment
    {
        $this->fetches[] = $providerPaymentId;

        if ($this->down) {
            throw new GatewayException('Moyasar is unreachable.');
        }

        return $this->payments[$providerPaymentId] ?? throw new ProviderPaymentNotFound("No payment [{$providerPaymentId}].");
    }

    public function void(string $providerPaymentId, string $idempotencyKey): GatewayResult
    {
        $this->voids[] = $providerPaymentId;

        if ($this->down) {
            throw new GatewayException('Moyasar is unreachable.');
        }

        return GatewayResult::succeeded($providerPaymentId, ['id' => $providerPaymentId, 'status' => 'voided']);
    }

    public function capture(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        return GatewayResult::succeeded($providerPaymentId, ['id' => $providerPaymentId, 'status' => 'captured']);
    }
}
