<?php

namespace App\Modules\Campaign\Http\Resources;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Pricing\Tier;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The customer `CampaignDetail` schema. Load RELATIONS first.
 *
 * @mixin Campaign
 */
class CampaignDetailResource extends CampaignSummaryResource
{
    public const RELATIONS = [...CampaignSummaryResource::RELATIONS, 'product.category.parent', 'variants'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();
        $tiers = $this->resource->tierSet();
        $current = $tiers?->current($this->committed_qty);
        $category = $this->product->category;

        return parent::toArray($request) + [
            'description' => (string) ($locale === 'en' ? $this->product->description_en : $this->product->description_ar),
            'category' => $category === null ? null : CategoryResource::make($category)->resolve($request),
            'variants' => $this->variants->map(fn (ProductVariant $variant) => self::variant($variant))->all(),
            'images' => $this->product->images
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn (ProductImage $image) => self::image($image))
                ->values()
                ->all(),
            'tiers' => array_map(fn (Tier $tier) => [
                'min_qty' => $tier->minQty,
                'max_qty' => $tier->maxQty,
                'unit_price' => $tier->unitPrice(),
                'is_current' => $tier === $current,
            ], $tiers?->tiers ?? []),
            'per_customer_max_qty' => $this->per_customer_max_qty,
            'original_deadline_at' => $this->original_deadline_at?->toIso8601ZuluString(),
            'final_unit_price' => $this->final_unit_price_halalas === null ? null : Money::sar($this->final_unit_price_halalas),
            'cancellation_rule' => (string) config('groupbuy.campaigns.cancellation_rule'),
        ];
    }

    /**
     * The customer `Variant` schema.
     *
     * @return array<string, mixed>
     */
    public static function variant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->uuid,
            'sku' => $variant->sku,
            'name' => App::getLocale() === 'en' ? $variant->name_en : $variant->name_ar,
            'option_values' => (object) $variant->option_values,
        ];
    }
}
