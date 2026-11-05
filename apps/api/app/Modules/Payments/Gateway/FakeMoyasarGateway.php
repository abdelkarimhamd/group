<?php

namespace App\Modules\Payments\Gateway;

use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Stands in for Moyasar until the merchant account is approved, so the
 * website, admin portal and mobile app can run the whole join and payment
 * flow. It makes no network call and keeps no state.
 *
 * A provider payment ID names its outcome and our payment:
 * `fake_authorized_<payment uuid>` is a hold for exactly the payment's
 * amount (mada, card ending 0000), and `fake_declined_<payment uuid>` is a
 * declined card. Captures, voids and refunds always succeed. Its lists are
 * empty: no payments we do not know of, and no settlements. Refuses to run
 * in production.
 */
final class FakeMoyasarGateway implements PaymentGateway, PaymentReports
{
    private const PATTERN = '/^fake_(authorized|declined)_([0-9a-f-]{36})$/';

    public function __construct(bool $production)
    {
        if ($production) {
            throw new LogicException('The fake Moyasar gateway cannot run in production. Set MOYASAR_DRIVER.');
        }
    }

    public function fetch(string $providerPaymentId): ProviderPayment
    {
        if (preg_match(self::PATTERN, $providerPaymentId, $match) !== 1) {
            throw new ProviderPaymentNotFound("Unknown fake payment [{$providerPaymentId}].");
        }

        $payment = PaymentAuthorization::query()->where('uuid', $match[2])->first()
            ?? throw new ProviderPaymentNotFound("Unknown fake payment [{$providerPaymentId}].");

        $authorized = $match[1] === 'authorized';
        $status = match (true) {
            ! $authorized => 'failed',
            $payment->voided_at !== null => 'voided',
            $payment->refunded_amount_halalas > 0 => 'refunded',
            $payment->captured_amount_halalas > 0 => 'captured',
            default => 'authorized',
        };

        return new ProviderPayment(
            id: $providerPaymentId,
            status: $status,
            amountHalalas: $payment->authorized_amount_halalas,
            currency: 'SAR',
            sourceType: 'creditcard',
            scheme: 'mada',
            cardLast4: '0000',
            capturedHalalas: $payment->captured_amount_halalas,
            paymentId: $payment->uuid,
            raw: ['id' => $providerPaymentId, 'status' => $status, 'fake' => true],
            refundedHalalas: $payment->refunded_amount_halalas,
        );
    }

    public function payments(CarbonImmutable $from, CarbonImmutable $to, int $page = 1): array
    {
        return ['items' => [], 'next_page' => null];
    }

    public function settlements(int $page = 1): array
    {
        return ['items' => [], 'next_page' => null];
    }

    public function settlementLines(string $settlementId, int $page = 1): array
    {
        return ['items' => [], 'next_page' => null];
    }

    public function void(string $providerPaymentId, string $idempotencyKey): GatewayResult
    {
        return GatewayResult::succeeded($providerPaymentId, ['id' => $providerPaymentId, 'status' => 'voided', 'fake' => true]);
    }

    public function capture(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        return GatewayResult::succeeded($providerPaymentId, [
            'id' => $providerPaymentId, 'status' => 'captured', 'captured' => $amountHalalas, 'fake' => true,
        ]);
    }

    public function refund(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        return GatewayResult::succeeded($providerPaymentId, [
            'id' => $providerPaymentId, 'status' => 'refunded', 'refunded' => $amountHalalas, 'fake' => true,
        ]);
    }
}
