<?php

namespace App\Modules\Supplier\Http\Resources;

use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Supplier` schema. Load `reviewer` first.
 *
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    public const RELATIONS = ['reviewer'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'alibaba_store_url' => $this->alibaba_store_url,
            'country' => $this->country,
            'contact_name' => $this->contact_name,
            'contact_channel' => $this->contact_channel,
            'quality_rating' => $this->quality_rating,
            'review_notes' => $this->review_notes,
            'status' => $this->status->value,
            'reviewed_by' => $this->reviewer === null ? null : [
                'type' => 'admin',
                'id' => $this->reviewer->uuid,
                'name' => $this->reviewer->name,
            ],
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
        ];
    }
}
