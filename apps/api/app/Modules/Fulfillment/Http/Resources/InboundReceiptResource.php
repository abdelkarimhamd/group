<?php

namespace App\Modules\Fulfillment\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Fulfillment\Models\InboundReceipt;
use App\Modules\Fulfillment\Models\InboundReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `InboundReceipt` schema. Load RELATIONS first.
 *
 * @mixin InboundReceipt
 */
class InboundReceiptResource extends JsonResource
{
    public const RELATIONS = ['lines.variant', 'receivedBy'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'warehouse_name' => $this->warehouse_name,
            'received_at' => $this->received_at->toIso8601ZuluString(),
            'received_by' => ActorJson::admin($this->receivedBy),
            'notes' => $this->notes,
            'lines' => $this->lines->map(fn (InboundReceiptLine $line) => [
                'product_variant_id' => $line->variant->uuid,
                'qty_expected' => $line->qty_expected,
                'qty_received' => $line->qty_received,
                'qty_damaged' => $line->qty_damaged,
                'qty_missing' => $line->missing(),
            ])->all(),
            // Photos come with file storage (an integration, last).
            'photo_urls' => [],
        ];
    }
}
