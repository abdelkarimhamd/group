<?php

namespace App\Modules\Payments\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Payments\Models\Refund;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Refund` schema.
 *
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    public const RELATIONS = ['payment', 'order', 'approvalRequest', 'creditNote', 'adminActor'];

    /** The same relations, loaded through an order's refunds. */
    public const NESTED = ['refunds.payment', 'refunds.order', 'refunds.approvalRequest', 'refunds.creditNote', 'refunds.adminActor'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'payment_id' => $this->payment->uuid,
            'customer_order_id' => $this->order?->uuid,
            'amount' => Money::sar($this->amount_halalas),
            'method' => $this->method->value,
            'reason' => $this->reason->value,
            'status' => $this->status->value,
            'provider_refund_id' => $this->provider_refund_id,
            'approval_request_id' => $this->approvalRequest?->uuid,
            'credit_note_id' => $this->creditNote?->uuid,
            'requested_by' => ActorJson::from($this->requested_by_type, $this->adminActor),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'processed_at' => $this->processed_at?->toIso8601ZuluString(),
        ];
    }
}
