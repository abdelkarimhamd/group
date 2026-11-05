<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Models\MoyasarSettlement;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `MoyasarSettlement` schema: one payout from Moyasar to the bank. Load
 * `posting` first.
 *
 * @mixin MoyasarSettlement
 */
class MoyasarSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'provider_settlement_id' => $this->provider_settlement_id,
            'gross' => Money::sar($this->gross_halalas),
            'fee' => Money::sar($this->fee_halalas),
            'fee_vat' => Money::sar($this->fee_vat_halalas),
            'net' => Money::sar($this->net_halalas),
            'settled_at' => $this->settled_at?->toIso8601ZuluString(),
            'line_count' => $this->line_count,
            'unmatched_count' => $this->unmatched_count,
            'ledger_transaction_id' => $this->posting?->uuid,
            'imported_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
