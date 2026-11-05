<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Models\MoyasarSettlementLine;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `MoyasarSettlementLine` schema: one payment, refund or fee inside a
 * settlement. Load `payment` first.
 *
 * @mixin MoyasarSettlementLine
 */
class MoyasarSettlementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'provider_payment_id' => $this->provider_payment_id,
            'payment_id' => $this->payment?->uuid,
            'amount' => Money::sar($this->amount_halalas),
            'fee' => Money::sar($this->fee_halalas),
            'fee_vat' => Money::sar($this->fee_vat_halalas),
            'payment_amount' => $this->payment_amount_halalas === null ? null : Money::sar($this->payment_amount_halalas),
            'transacted_at' => $this->transacted_at?->toIso8601ZuluString(),
        ];
    }
}
