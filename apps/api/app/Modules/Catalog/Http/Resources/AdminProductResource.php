<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminProduct` schema. Load RELATIONS first.
 *
 * @mixin Product
 */
class AdminProductResource extends JsonResource
{
    /**
     * Relations the resource reads.
     */
    public const RELATIONS = ['category', 'variants', 'images.variant'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'category_id' => $this->category->uuid,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'alibaba_url' => $this->alibaba_url,
            'hs_code' => $this->hs_code,
            'status' => $this->status->value,
            'variants' => AdminVariantResource::collection($this->variants),
            'images' => AdminImageResource::collection($this->images),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
