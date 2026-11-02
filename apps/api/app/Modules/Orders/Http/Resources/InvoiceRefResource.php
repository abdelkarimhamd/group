<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Invoice;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `InvoiceRef` schema.
 *
 * @mixin Invoice
 */
class InvoiceRefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'invoice_number' => $this->invoice_number,
            'issued_at' => $this->issued_at->toIso8601ZuluString(),
            'total' => Money::sar($this->total_halalas),
            // The PDF is rendered and stored with the storage integration.
            'pdf_url' => null,
        ];
    }
}
