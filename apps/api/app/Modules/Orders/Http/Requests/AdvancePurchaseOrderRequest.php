<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use Illuminate\Validation\Rule;

final class AdvancePurchaseOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_map(fn (PurchaseOrderStatus $status) => $status->value, PurchaseOrderStatus::advanceable()))],
            'occurred_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
