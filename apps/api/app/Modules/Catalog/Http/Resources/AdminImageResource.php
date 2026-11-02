<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminImage` schema. Load `variant` first.
 *
 * @mixin ProductImage
 */
class AdminImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'url' => $this->resource->url(),
            'product_variant_id' => $this->variant?->uuid,
            'alt_ar' => $this->alt_ar,
            'alt_en' => $this->alt_en,
            'sort_order' => $this->sort_order,
        ];
    }
}
