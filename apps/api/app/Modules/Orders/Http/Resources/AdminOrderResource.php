<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Http\Resources\RefundResource;
use Illuminate\Http\Request;

/**
 * The `AdminOrder` schema: the order, the customer to deliver to, and the
 * money returned on it.
 *
 * @mixin CustomerOrder
 */
class AdminOrderResource extends OrderResource
{
    public const RELATIONS = [...OrderResource::RELATIONS, 'user', 'participation', ...RefundResource::NESTED];

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
        ];
    }
}
