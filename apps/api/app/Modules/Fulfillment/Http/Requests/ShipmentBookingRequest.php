<?php

namespace App\Modules\Fulfillment\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ShipmentBookingInput` schema.
 */
final class ShipmentBookingRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_cost_halalas' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
