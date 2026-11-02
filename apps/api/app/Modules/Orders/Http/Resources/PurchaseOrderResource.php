<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Catalog\Http\Resources\AdminVariantResource;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrderLine;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `PurchaseOrder` schema. Load RELATIONS first.
 *
 * @mixin SupplierPurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    public const RELATIONS = ['campaign', 'supplier', 'quotation', 'lines.variant'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'po_number' => $this->po_number,
            'campaign' => ['id' => $this->campaign->uuid, 'code' => $this->campaign->code],
            'supplier' => ['id' => $this->supplier->uuid, 'name' => $this->supplier->name],
            'supplier_quotation_id' => $this->quotation->uuid,
            'alibaba_order_ref' => $this->alibaba_order_ref,
            'total' => $this->total(),
            'fx_rate_to_sar' => $this->fx_rate_to_sar,
            'total_sar' => $this->total_halalas === null ? null : Money::sar($this->total_halalas),
            'status' => $this->status->value,
            'lines' => $this->lines->map(fn (SupplierPurchaseOrderLine $line) => [
                'product_variant' => AdminVariantResource::make($line->variant)->resolve($request),
                'qty' => $line->qty,
                'unit_cost' => Money::of($line->unit_cost_minor, $line->currency),
            ])->all(),
            'placed_at' => $this->placed_at?->toIso8601ZuluString(),
            'supplier_confirmed_at' => $this->supplier_confirmed_at?->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'shipped_at' => $this->shipped_at?->toIso8601ZuluString(),
            'freight_forwarder' => $this->freight_forwarder,
            'freight_tracking_ref' => $this->freight_tracking_ref,
            'eta' => $this->eta?->format('Y-m-d'),
            // Warehouse receipts come with fulfilment (E13).
            'receipts' => [],
        ];
    }
}
