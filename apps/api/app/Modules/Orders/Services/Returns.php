<?php

namespace App\Modules\Orders\Services;

use App\Exceptions\ApiException;
use App\Modules\Fulfillment\Services\Shipments;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\ReturnReason;
use App\Modules\Orders\Enums\ReturnStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Services\Refunds;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns (spec, Orders: returns within the return window).
 * Customers ask support; staff record the return on the delivered order,
 * which then waits (`return_requested`) and is not completed when its
 * window ends. Approving asks finance for the refund through the usual
 * approval; once it goes through the order is `returned`
 * (ReturnSettlement). Rejecting puts the order back to `delivered`. A
 * return whose refund finance rejected, or that failed, can be decided
 * again. Lock order: campaign, customer order, return.
 */
final class Returns
{
    public function __construct(
        private readonly Shipments $shipments,
        private readonly Refunds $refunds,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array{reason: string, note?: string|null}  $input
     *
     * @throws ApiException 409 invalid_transition, return_window_ended or return_exists
     */
    public function record(string $orderUuid, array $input, AdminUser $by): OrderReturn
    {
        return DB::transaction(function () use ($orderUuid, $input, $by): OrderReturn {
            [, $order] = $this->shipments->lockOrder($orderUuid);

            if (OrderReturn::query()->where('customer_order_id', $order->id)->exists()) {
                throw new ApiException(409, 'return_exists');
            }

            if ($order->status !== OrderStatus::Delivered) {
                throw new ApiException(409, 'invalid_transition');
            }

            if ($order->return_window_ends_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new ApiException(409, 'return_window_ended');
            }

            $actor = Actor::admin($by->id);
            $return = OrderReturn::create([
                'customer_order_id' => $order->id,
                'status' => ReturnStatus::Requested,
                'reason' => ReturnReason::from($input['reason']),
                'note' => $input['note'] ?? null,
                'recorded_by_admin_id' => $by->id,
            ]);
            $this->history->record($return, null, ReturnStatus::Requested, $actor);

            $order->status = OrderStatus::ReturnRequested;
            $order->save();
            $this->history->record($order, OrderStatus::Delivered, OrderStatus::ReturnRequested, $actor, 'return_requested');
            $this->audit->record($actor, 'order_return.record', $return, null, [
                'order' => $order->order_number,
                'reason' => $return->reason->value,
            ]);

            return $return;
        });
    }

    /**
     * Accept the return and ask finance for the refund (reason
     * `customer_return`, waiting for a second admin's approval).
     *
     * @param  array{amount_halalas: int, note?: string|null}  $input
     *
     * @throws ApiException 409 invalid_transition; 422 from Refunds::request
     */
    public function approve(string $returnUuid, array $input, AdminUser $by): OrderReturn
    {
        return DB::transaction(function () use ($returnUuid, $input, $by): OrderReturn {
            [$order, $return] = $this->lock($returnUuid);
            $order->loadMissing('payment');
            $refund = $this->refunds->request([
                'payment_id' => $order->payment->uuid,
                'customer_order_id' => $order->uuid,
                'amount_halalas' => $input['amount_halalas'],
                'reason' => RefundReason::CustomerReturn->value,
                'note' => $input['note'] ?? null,
            ], $by);

            $actor = Actor::admin($by->id);
            $from = $return->status;
            $return->status = ReturnStatus::Approved;
            $return->decided_by_admin_id = $by->id;
            $return->decided_at = CarbonImmutable::now();
            $return->decision_note = $input['note'] ?? null;
            $return->refund_id = $refund->id;
            $return->save();

            if ($from !== ReturnStatus::Approved) {
                $this->history->record($return, $from, ReturnStatus::Approved, $actor);
            }

            $this->audit->record($actor, 'order_return.approve', $return, ['status' => $from->value], [
                'status' => ReturnStatus::Approved->value,
                'refund' => $refund->uuid,
                'amount_halalas' => $refund->amount_halalas,
            ]);
            $this->notifier->send($order->user()->firstOrFail(), NotificationTemplate::ReturnAccepted, $order, [
                'order' => $order->order_number,
                'amount_halalas' => $refund->amount_halalas,
            ], "return_accepted:refund:{$refund->id}");

            return $return;
        });
    }

    /**
     * Decline the return: the order is `delivered` again, and completed by
     * the hourly sweep if its window has ended.
     *
     * @param  array{note: string}  $input
     *
     * @throws ApiException 409 invalid_transition
     */
    public function reject(string $returnUuid, array $input, AdminUser $by): OrderReturn
    {
        return DB::transaction(function () use ($returnUuid, $input, $by): OrderReturn {
            [$order, $return] = $this->lock($returnUuid);

            $actor = Actor::admin($by->id);
            $from = $return->status;
            $return->status = ReturnStatus::Rejected;
            $return->decided_by_admin_id = $by->id;
            $return->decided_at = CarbonImmutable::now();
            $return->decision_note = $input['note'];
            $return->save();
            $this->history->record($return, $from, ReturnStatus::Rejected, $actor);

            $order->status = OrderStatus::Delivered;
            $order->save();
            $this->history->record($order, OrderStatus::ReturnRequested, OrderStatus::Delivered, $actor, 'return_rejected');
            $this->audit->record($actor, 'order_return.reject', $return, ['status' => $from->value], [
                'status' => ReturnStatus::Rejected->value,
                'note' => $return->decision_note,
            ]);
            $this->notifier->send($order->user()->firstOrFail(), NotificationTemplate::ReturnDeclined, $order, [
                'order' => $order->order_number,
            ], "return_declined:order_return:{$return->id}");

            return $return;
        });
    }

    /**
     * The order and its open return, locked.
     *
     * @return array{CustomerOrder, OrderReturn}
     */
    private function lock(string $returnUuid): array
    {
        $orderId = OrderReturn::query()->where('uuid', $returnUuid)->value('customer_order_id')
            ?? throw (new ModelNotFoundException)->setModel(OrderReturn::class);
        [, $order] = $this->shipments->lockOrder(CustomerOrder::query()->whereKey($orderId)->value('uuid'));
        $return = OrderReturn::query()->with('refund')->where('uuid', $returnUuid)->lockForUpdate()->firstOrFail();

        if (! $return->isOpen() || $order->status !== OrderStatus::ReturnRequested) {
            throw new ApiException(409, 'invalid_transition');
        }

        return [$order, $return];
    }
}
