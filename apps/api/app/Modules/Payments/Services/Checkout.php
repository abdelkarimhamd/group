<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentAuthorization;

/**
 * The settings the website and app pass to Moyasar's payment form: the
 * `MoyasarCheckout` schema. Card details are typed only into Moyasar's form.
 * The payment is authorize-only (`manual`), and carries our payment uuid in
 * its metadata so a webhook can find it even if the customer's return is lost.
 */
final class Checkout
{
    /**
     * @return array<string, mixed>
     */
    public static function for(PaymentAuthorization $payment, string $description): array
    {
        $base = rtrim((string) config('services.moyasar.callback_base_url'), '/');

        return [
            'publishable_api_key' => (string) config('services.moyasar.publishable_key'),
            'amount' => $payment->authorized_amount_halalas,
            'currency' => $payment->currency,
            'description' => $description,
            'callback_url' => $base.'/payments/return?payment_id='.$payment->uuid,
            'methods' => ['creditcard', 'applepay'],
            'supported_networks' => ['mada', 'visa', 'mastercard'],
            'manual' => true,
            'metadata' => ['payment_id' => $payment->uuid],
        ];
    }
}
