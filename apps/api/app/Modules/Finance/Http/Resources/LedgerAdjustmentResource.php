<?php

namespace App\Modules\Finance\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\LedgerAdjustment;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `LedgerAdjustment` schema: a hand-made posting or reversal and where
 * its approval stands. Load RELATIONS first.
 *
 * @mixin LedgerAdjustment
 */
class LedgerAdjustmentResource extends JsonResource
{
    public const RELATIONS = ['reverses', 'posting', 'requester', 'approvalRequest'];

    /** @var array<string, string>|null account names by code */
    private static ?array $accountNames = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        self::$accountNames ??= LedgerAccount::query()->pluck('name', 'code')->all();
        $approval = $this->approvalRequest;

        return [
            'id' => $this->uuid,
            'description' => $this->description,
            'lines' => $this->lines === null ? null : array_map(fn (array $line) => [
                'account' => $line['account'],
                'account_name' => self::$accountNames[$line['account']] ?? $line['account'],
                'direction' => $line['direction'],
                'amount' => Money::sar((int) $line['amount_halalas']),
            ], $this->lines),
            'reverses' => $this->reverses === null ? null : [
                'id' => $this->reverses->uuid,
                'type' => $this->reverses->type->value,
                'reference' => $this->reverses->reference,
                'description' => $this->reverses->description,
            ],
            'status' => $this->status->value,
            'approval_request_id' => $approval instanceof ApprovalRequest ? $approval->uuid : null,
            'ledger_transaction_id' => $this->posting?->uuid,
            'requested_by' => ActorJson::admin($this->requester),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
