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

    /** @var list<array{string, int, string}> Captures asked for: provider ID, amount, idempotency key. */
    public array $captures = [];

    /**
     * How Moyasar answers the next captures of a payment, in order (then
     * `ok`): `ok`, `declined`, `timeout` (unknown outcome, nothing
     * captured) or `timeout_after_capture` (unknown outcome, captured).
     *
     * @var array<string, list<string>>
     */
    public array $captureOutcomes = [];

    /** @var list<array{string, int, string}> Refunds asked for: provider ID, amount, idempotency key. */
    public array $refunds = [];

    /**
     * How Moyasar answers the next refunds (and voids) of a payment, in
     * order (then `ok`): `ok`, `declined`, `timeout` (unknown outcome,
     * nothing refunded) or `timeout_after_refund` (unknown outcome, refunded).
     *
     * @var array<string, list<string>>
     */
    public array $refundOutcomes = [];

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

        $captured = isset($this->payments[$providerPaymentId]) && $this->payments[$providerPaymentId]->status === 'captured';
        $outcome = 'ok';

        if ($captured) {
            // Voiding a capture is a full refund before settlement.
            $outcome = $this->nextRefundOutcome($providerPaymentId);

            if ($outcome === 'declined') {
                return GatewayResult::refused('void_not_allowed', 'The payment can no longer be voided.', ['id' => $providerPaymentId, 'status' => 'captured']);
            }

            if ($outcome === 'timeout') {
                throw new GatewayException('Moyasar timed out.');
            }
        }

        if (isset($this->payments[$providerPaymentId])) {
            $this->setStatus($providerPaymentId, 'voided', refundedHalalas: $captured ? $this->payments[$providerPaymentId]->capturedHalalas : null);
        }

        if ($captured && $outcome === 'timeout_after_refund') {
            throw new GatewayException('Moyasar timed out.');
        }

        return GatewayResult::succeeded($providerPaymentId, ['id' => $providerPaymentId, 'status' => 'voided']);
    }

    public function refund(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        $this->refunds[] = [$providerPaymentId, $amountHalalas, $idempotencyKey];

        if ($this->down) {
            throw new GatewayException('Moyasar is unreachable.');
        }

        $outcome = $this->nextRefundOutcome($providerPaymentId);

        if ($outcome === 'declined') {
            return GatewayResult::refused('refund_declined', 'Moyasar refused the refund.', ['id' => $providerPaymentId]);
        }

        if ($outcome === 'timeout') {
            throw new GatewayException('Moyasar timed out.');
        }

        $old = $this->payments[$providerPaymentId];
        $this->setStatus($providerPaymentId, 'refunded', refundedHalalas: $old->refundedHalalas + $amountHalalas);

        if ($outcome === 'timeout_after_refund') {
            throw new GatewayException('Moyasar timed out.');
        }

        return GatewayResult::succeeded('rf_'.count($this->refunds), ['id' => $providerPaymentId, 'status' => 'refunded', 'refunded' => $amountHalalas]);
    }

    private function nextRefundOutcome(string $providerPaymentId): string
    {
        return ($this->refundOutcomes[$providerPaymentId] ?? []) === [] ? 'ok' : array_shift($this->refundOutcomes[$providerPaymentId]);
    }

    public function capture(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        $this->captures[] = [$providerPaymentId, $amountHalalas, $idempotencyKey];

        if ($this->down) {
            throw new GatewayException('Moyasar is unreachable.');
        }

        $outcome = ($this->captureOutcomes[$providerPaymentId] ?? []) === [] ? 'ok' : array_shift($this->captureOutcomes[$providerPaymentId]);

        if ($outcome === 'declined') {
            return GatewayResult::refused('capture_declined', 'The issuer declined the capture.', ['id' => $providerPaymentId, 'status' => 'authorized']);
        }

        if ($outcome === 'timeout') {
            throw new GatewayException('Moyasar timed out.');
        }

        $this->setStatus($providerPaymentId, 'captured', $amountHalalas);

        if ($outcome === 'timeout_after_capture') {
            throw new GatewayException('Moyasar timed out.');
        }

        return GatewayResult::succeeded($providerPaymentId, ['id' => $providerPaymentId, 'status' => 'captured', 'captured' => $amountHalalas]);
    }

    /**
     * Moyasar has no record of this payment.
     */
    public function forget(string $id): void
    {
        unset($this->payments[$id]);
    }

    /**
     * Moyasar's view of a payment changes (captured, voided...).
     */
    public function setStatus(string $id, string $status, ?int $capturedHalalas = null, ?int $refundedHalalas = null): void
    {
        $old = $this->payments[$id];
        $this->payments[$id] = new ProviderPayment(
            id: $old->id,
            status: $status,
            amountHalalas: $old->amountHalalas,
            currency: $old->currency,
            sourceType: $old->sourceType,
            scheme: $old->scheme,
            cardLast4: $old->cardLast4,
            capturedHalalas: $capturedHalalas ?? $old->capturedHalalas,
            paymentId: $old->paymentId,
            raw: ['id' => $old->id, 'status' => $status],
            refundedHalalas: $refundedHalalas ?? $old->refundedHalalas,
        );
    }
}
