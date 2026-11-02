<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Support\Money\Currency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The `PurchaseOrderInput` schema: the fields sent change, the rest keep
 * their value. A SAR order's rate is 1, and a new currency comes with the
 * total in that currency.
 */
final class PurchaseOrderRequest extends ApiRequest
{
    private const MAX_MINOR = 1_000_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alibaba_order_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
            // The amount means nothing in another currency: a new currency needs its total.
            'total_minor' => ['required_with:currency', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'currency' => ['sometimes', Rule::enum(Currency::class)],
            'fx_rate_to_sar' => ['sometimes', 'string', 'regex:/^[0-9]{1,10}(\.[0-9]{1,8})?$/', 'regex:/[1-9]/'],
            'freight_forwarder' => ['sometimes', 'nullable', 'string', 'max:100'],
            'freight_tracking_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
            'eta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'paid_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['alibaba_order_ref', 'total_minor', 'currency', 'fx_rate_to_sar', 'freight_forwarder', 'freight_tracking_ref', 'eta', 'paid_at'])) {
                    $validator->errors()->add('alibaba_order_ref', __('messages.errors.nothing_to_update'));
                }

                if ($this->input('currency') === Currency::SAR->value && $this->has('fx_rate_to_sar')
                    && ! preg_match('/^0*1(\.0*)?$/', (string) $this->input('fx_rate_to_sar'))) {
                    $validator->errors()->add('fx_rate_to_sar', __('validation.in', ['attribute' => 'fx_rate_to_sar']));
                }
            },
        ];
    }
}
