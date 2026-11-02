<?php

namespace App\Modules\Campaign\Http\Resources;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Progress;
use App\Modules\Catalog\Models\ProductImage;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;

/**
 * The customer `CampaignSummary` schema. Load RELATIONS first.
 *
 * @mixin Campaign
 */
class CampaignSummaryResource extends JsonResource
{
    public const RELATIONS = ['currentTiers', 'product.images.variant'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tiers = $this->resource->tierSet();
        $image = $this->product->images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first();

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'title' => $this->resource->title(App::getLocale()),
            'image' => $image === null ? null : self::image($image),
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'deadline_at' => $this->deadline_at?->toIso8601ZuluString(),
            'current_unit_price' => Progress::currentUnitPrice($this->resource),
            'lowest_unit_price' => $tiers?->lowest()->unitPrice() ?? Money::zero(),
            'progress' => Progress::of($this->resource),
        ];
    }

    /**
     * The customer `Image` schema.
     *
     * @return array<string, mixed>
     */
    public static function image(ProductImage $image): array
    {
        return [
            'url' => $image->url(),
            'alt' => App::getLocale() === 'en' ? $image->alt_en : $image->alt_ar,
            'product_variant_id' => $image->variant?->uuid,
        ];
    }
}
