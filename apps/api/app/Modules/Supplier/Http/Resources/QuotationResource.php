<?php

namespace App\Modules\Supplier\Http\Resources;

use App\Modules\Supplier\Models\SupplierQuotation;
use App\Modules\Supplier\Services\QuotationUsage;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The `Quotation` schema. Load RELATIONS first. The attachment is private, so
 * its link is signed and short-lived (groupbuy.media.attachment_url_minutes).
 *
 * @mixin SupplierQuotation
 */
class QuotationResource extends JsonResource
{
    public const RELATIONS = ['supplier', 'product', 'variant'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $breakdown = [];

        foreach (SupplierQuotation::BREAKDOWN as $part) {
            $breakdown[$part] = Money::sar((int) $this->landed_cost_breakdown[$part]);
        }

        return [
            'id' => $this->uuid,
            'supplier_id' => $this->supplier->uuid,
            'product_id' => $this->product->uuid,
            'product_variant_id' => $this->variant?->uuid,
            'moq' => $this->moq,
            'unit_cost' => $this->resource->unitCost(),
            'shipping_terms' => $this->shipping_terms,
            'lead_time_days' => $this->lead_time_days,
            'valid_until' => $this->valid_until->toDateString(),
            'fx_rate_to_sar' => $this->fx_rate_to_sar,
            'landed_cost_basis_qty' => $this->landed_cost_basis_qty,
            'landed_cost_breakdown' => $breakdown,
            'landed_cost_per_unit' => $this->resource->landedCostPerUnit(),
            'attachment_url' => $this->attachment_path === null ? null : Storage::disk($this->attachment_disk)->temporaryUrl(
                $this->attachment_path,
                now()->addMinutes((int) config('groupbuy.media.attachment_url_minutes')),
            ),
            'status' => $this->status->value,
            'used_by_campaign_ids' => app(QuotationUsage::class)->campaignIds($this->resource),
        ];
    }
}
