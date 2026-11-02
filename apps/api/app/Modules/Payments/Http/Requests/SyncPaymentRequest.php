<?php

namespace App\Modules\Payments\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The Moyasar payment ID from the payment form's return URL. Only compared
 * with what Moyasar itself reports; never trusted on its own.
 */
final class SyncPaymentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_payment_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
