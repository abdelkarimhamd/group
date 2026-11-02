<?php

namespace App\Modules\Payments\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminPayment` schema. Load `participation.campaign` first, and
 * TRANSACTIONS when showing one payment; lists leave `transactions` empty.
 *
 * @mixin PaymentAuthorization
 */
class AdminPaymentResource extends JsonResource
{
    public const TRANSACTIONS = ['transactions.adminActor', 'transactions.userActor'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->participation->campaign;

        return [
            'id' => $this->uuid,
            'participation_id' => $this->participation->uuid,
            'campaign' => ['id' => $campaign->uuid, 'code' => $campaign->code],
            'provider_payment_id' => $this->provider_payment_id,
            'source_type' => $this->source_type,
            'scheme' => $this->scheme,
            'card_last4' => $this->card_last4,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason?->value,
            'authorized_amount' => Money::sar($this->authorized_amount_halalas),
            'captured_amount' => Money::sar($this->captured_amount_halalas),
            'refunded_amount' => Money::sar($this->refunded_amount_halalas),
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'capture_cutoff_at' => $this->resource->captureCutoffAt()->toIso8601ZuluString(),
            'authorized_at' => $this->authorized_at?->toIso8601ZuluString(),
            'captured_at' => $this->captured_at?->toIso8601ZuluString(),
            'voided_at' => $this->voided_at?->toIso8601ZuluString(),
            'provider_status' => $this->provider_status,
            'last_synced_at' => $this->last_synced_at?->toIso8601ZuluString(),
            'transactions' => $this->relationLoaded('transactions')
                ? $this->transactions->map(fn (PaymentTransaction $transaction) => [
                    'id' => $transaction->uuid,
                    'type' => $transaction->type->value,
                    'amount' => Money::sar($transaction->amount_halalas),
                    'idempotency_key' => $transaction->idempotency_key,
                    'status' => $transaction->status->value,
                    'provider_reference' => $transaction->provider_reference,
                    'error_code' => $transaction->error_code,
                    'error_message' => $transaction->error_message,
                    'requested_by' => ActorJson::from($transaction->requested_by_type, $transaction->adminActor, $transaction->userActor),
                    'created_at' => $transaction->created_at->toIso8601ZuluString(),
                    'completed_at' => $transaction->completed_at?->toIso8601ZuluString(),
                ])->all()
                : [],
        ];
    }
}
