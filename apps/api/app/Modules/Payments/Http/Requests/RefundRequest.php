<?php

namespace App\Modules\Payments\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Payments\Enums\RefundReason;
use Illuminate\Validation\Rule;

final class RefundRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_id' => ['required', 'string', 'uuid'],
            'customer_order_id' => ['nullable', 'string', 'uuid'],
            'amount_halalas' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reason' => ['required', Rule::enum(RefundReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
