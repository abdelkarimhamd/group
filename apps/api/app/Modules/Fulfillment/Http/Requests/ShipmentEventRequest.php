<?php

namespace App\Modules\Fulfillment\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Fulfillment\Enums\ShipmentStatus;
use Illuminate\Validation\Rule;

/**
 * The `ShipmentEventInput` schema: a tracking update entered by hand.
 */
final class ShipmentEventRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ShipmentStatus::class)],
            'description' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:200'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
