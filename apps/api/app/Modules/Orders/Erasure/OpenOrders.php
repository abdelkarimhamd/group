<?php

namespace App\Modules\Orders\Erasure;

use App\Modules\Identity\Erasure\ErasureBlocker;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\CustomerOrder;

/**
 * An order is settled once it is completed (the return window ended),
 * cancelled or returned. Until then it may still be delivered, returned or
 * refunded, and the customer must be reachable.
 */
final class OpenOrders implements ErasureBlocker
{
    public function blocks(User $user): ?string
    {
        $open = CustomerOrder::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Returned])
            ->exists();

        return $open ? 'orders' : null;
    }
}
