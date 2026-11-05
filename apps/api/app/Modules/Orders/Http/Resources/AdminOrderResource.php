<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Fulfillment\Http\Resources\AdminShipmentResource;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Http\Resources\RefundResource;
use Illuminate\Http\Request;

/**
 * The `AdminOrder` schema: the order, the customer to deliver to, the money
 * returned on it, every shipment and the customer's return.
 *
 * @mixin CustomerOrder
 */
class AdminOrderResource extends OrderResource
{
    public const RELATIONS = [
        ...OrderResource::RELATIONS, 'user', 'participation', ...RefundResource::NESTED, 'shipments.events',
        'orderReturn.recordedBy', 'orderReturn.decidedBy', 'orderReturn.refund.payment', 'orderReturn.refund.order',
        'orderReturn.refund.approvalRequest', 'orderReturn.refund.creditNote', 'orderReturn.refund.adminActor',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'customer' => [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
                'phone' => $this->user->phone_e164,
            ],
            'participation_id' => $this->participation->uuid,
            'payment_id' => $this->payment->uuid,
            'refunds' => RefundResource::collection($this->refunds)->resolve($request),
            'shipments' => AdminShipmentResource::collection($this->shipments)->resolve($request),
            'return' => $this->orderReturn === null ? null : OrderReturnResource::make($this->orderReturn->setRelation('order', $this->resource))->resolve($request),
        ];
    }
}
