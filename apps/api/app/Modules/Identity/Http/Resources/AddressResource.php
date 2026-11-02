<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Address` schema in openapi/v1.yaml.
 *
 * @mixin Address
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'building_no' => $this->building_no,
            'postal_code' => $this->postal_code,
            'additional_no' => $this->additional_no,
            'short_address' => $this->short_address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'is_default' => $this->is_default,
        ];
    }
}
