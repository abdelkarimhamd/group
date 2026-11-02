<?php

namespace App\Modules\Payments\Gateway;

/**
 * The card payment provider (Moyasar). Every call that moves or releases
 * money takes an idempotency key, and callers log it in a
 * payment_transactions row before calling (App\Modules\Payments\Services\Holds).
 *
 * Never call these inside an open database transaction: write the attempt
 * row, commit, call, then record the result.
 */
interface PaymentGateway
{
    /**
     * The payment as the provider sees it now.
     *
     * @throws ProviderPaymentNotFound when the provider has no such payment
     * @throws GatewayException when the provider cannot be reached
     */
    public function fetch(string $providerPaymentId): ProviderPayment;

    /**
     * Release an authorized hold without charging it.
     *
     * @throws GatewayException when the outcome is unknown (timeout, provider down)
     */
    public function void(string $providerPaymentId, string $idempotencyKey): GatewayResult;

    /**
     * Charge part or all of an authorized hold.
     *
     * @throws GatewayException when the outcome is unknown (timeout, provider down)
     */
    public function capture(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult;
}
