<?php

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Catalog\Http\Resources\AdminVariantResource;
use App\Modules\Fulfillment\Http\Resources\InboundReceiptResource;
use App\Modules\Orders\Models\PurchaseOrderDocument;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrderLine;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The `PurchaseOrder` schema. Load RELATIONS first.
 *
 * @mixin SupplierPurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    public const RELATIONS = ['campaign', 'supplier', 'quotation', 'lines.variant', 'receipts.lines.variant', 'receipts.receivedBy', 'documents.uploadedBy'];

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
            'receipts' => InboundReceiptResource::collection($this->receipts)->resolve($request),
            'documents' => $this->documents->map(fn (PurchaseOrderDocument $document) => [
                'id' => $document->uuid,
                'kind' => $document->kind->value,
                'file_name' => $document->file_name,
                'size_bytes' => $document->size_bytes,
                'url' => Storage::disk($document->disk)->temporaryUrl(
                    $document->path,
                    now()->addMinutes((int) config('groupbuy.media.attachment_url_minutes')),
                ),
                'uploaded_by' => ActorJson::admin($document->uploadedBy),
                'uploaded_at' => $document->created_at->toIso8601ZuluString(),
                'removable' => $document->isRemovable(),
            ])->all(),
        ];
    }
}
