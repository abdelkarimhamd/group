<?php

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Payments\Http\Resources\RefundResource;
use App\Modules\Payments\Services\Refunds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `OrderReturn` schema.
 *
 * @mixin OrderReturn
 */
class OrderReturnResource extends JsonResource
{
    public const RELATIONS = [
        'order.user', 'order.payment', 'recordedBy', 'decidedBy',
        'refund.payment', 'refund.order', 'refund.approvalRequest', 'refund.creditNote', 'refund.adminActor',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'customer_order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
            'customer' => ['name' => $this->order->user->name, 'phone' => $this->order->user->phone_e164],
            'status' => $this->status->value,
            'reason' => $this->reason->value,
            'note' => $this->note,
            'recorded_by' => ActorJson::from('admin', $this->recordedBy),
            'recorded_at' => $this->created_at->toIso8601ZuluString(),
            'decided_by' => ActorJson::admin($this->decidedBy),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'refund' => $this->refund === null ? null : RefundResource::make($this->refund)->resolve($request),
            'refundable' => Refunds::refundable($this->order->payment),
            'open' => $this->isOpen(),
        ];
    }
}
