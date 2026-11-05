<?php

namespace App\Modules\Fulfillment\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ShipmentCancelInput` schema.
 */
final class ShipmentCancelRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
