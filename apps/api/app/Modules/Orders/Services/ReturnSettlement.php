<?php

namespace App\Modules\Orders\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Fulfillment\Services\Shipments;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\ReturnStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Payments\Models\Refund;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;

/**
 * What a refund that went through does to a return: the refund asked for
 * when the return was approved, or any refund of everything on an order
 * waiting on a return, makes the order `returned` and the return
 * `completed`. Kept apart from Returns so Refunds can call it without
 * depending on what calls Refunds.
 */
final class ReturnSettlement
{
    public function __construct(
        private readonly StatusHistory $history,
        private readonly Shipments $shipments,
    ) {}

    /**
     * Call with the campaign and order locked. Returns whether the order
     * was closed.
     */
    public function refunded(Campaign $campaign, CustomerOrder $order, Refund $refund, bool $everythingRefunded, Actor $actor): bool
    {
        if ($order->status !== OrderStatus::ReturnRequested) {
            return false;
        }

        $return = OrderReturn::query()->where('customer_order_id', $order->id)->lockForUpdate()->first();
        $approvedRefund = $return?->status === ReturnStatus::Approved && $return->refund_id === $refund->id;

        if (! $approvedRefund && ! $everythingRefunded) {
            return false;
        }

        $order->status = OrderStatus::Returned;
        $order->save();
        $this->history->record($order, OrderStatus::ReturnRequested, OrderStatus::Returned, $actor, 'refunded');

        if ($return !== null && in_array($return->status, [ReturnStatus::Requested, ReturnStatus::Approved], true)) {
            $from = $return->status;
            $return->status = ReturnStatus::Completed;
            $return->decided_at ??= CarbonImmutable::now();
            $return->save();
            $this->history->record($return, $from, ReturnStatus::Completed, $actor, 'refunded');
        }

        $this->shipments->completeCampaignIfSettled($campaign, $actor);

        return true;
    }
}
