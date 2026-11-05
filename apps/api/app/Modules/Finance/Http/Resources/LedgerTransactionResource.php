<?php

namespace App\Modules\Finance\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `LedgerTransaction` schema: one balanced posting with its lines.
 * Load RELATIONS first.
 *
 * @mixin LedgerTransaction
 */
class LedgerTransactionResource extends JsonResource
{
    public const RELATIONS = ['entries.account', 'order', 'approvalRequest', 'reverses', 'adminActor', 'userActor'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'reference' => $this->reference,
            'description' => $this->description,
            'order' => $this->order === null ? null : ['id' => $this->order->uuid, 'number' => $this->order->order_number],
            'approval_request_id' => $this->approvalRequest?->uuid,
            'reverses_transaction_id' => $this->reverses?->uuid,
            'entries' => $this->entries->sortBy('id')->map(fn (LedgerEntry $entry) => [
                'account' => $entry->account->code,
                'account_name' => $entry->account->name,
                'direction' => $entry->direction,
                'amount' => Money::sar($entry->amount_halalas),
            ])->values()->all(),
            'actor' => ActorJson::from($this->actor_type, $this->adminActor, $this->userActor),
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
        ];
    }
}
