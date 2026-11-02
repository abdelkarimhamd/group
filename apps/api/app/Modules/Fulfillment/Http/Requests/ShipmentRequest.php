<?php

namespace App\Modules\Fulfillment\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ShipmentInput` schema.
 */
final class ShipmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'courier' => ['required', 'string', 'max:100'],
            'tracking_number' => ['required', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'string', 'max:2048', 'url:https,http'],
            'shipping_cost_halalas' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
