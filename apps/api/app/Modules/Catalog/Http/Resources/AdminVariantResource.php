<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminVariant` schema.
 *
 * @mixin ProductVariant
 */
class AdminVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'sku' => $this->sku,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            // An empty map must stay a JSON object, not become [].
            'option_values' => (object) ($this->option_values ?? []),
            'weight_grams' => $this->weight_grams,
            'length_mm' => $this->length_mm,
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'status' => $this->status->value,
        ];
    }
}
