<?php

namespace App\Modules\Fulfillment\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Fulfillment\Enums\ShipmentStatus;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentEvent;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The last mile (spec, E13): one shipment per customer order from the
 * warehouse, tracked by hand until a courier API is chosen (Q11). Delivery
 * starts the return window; once every order of a campaign is delivered
 * or cancelled the campaign is completed. Lock order: campaign, customer
 * order, shipment.
 */
final class Shipments
{
    /** Order statuses that no longer hold a campaign open. */
    private const SETTLED = [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Returned];

    public function __construct(
        private readonly CampaignLifecycle $lifecycle,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Hand a ready order to the courier: the shipment starts `picked_up`
     * and the order becomes `shipped`.
     *
     * @param  array{courier: string, tracking_number: string, tracking_url?: string|null, shipping_cost_halalas?: int|null}  $input
     */
    public function create(string $orderUuid, array $input, AdminUser $by): Shipment
    {
        return DB::transaction(function () use ($orderUuid, $input, $by): Shipment {
            [, $order] = $this->lockOrder($orderUuid);

            if (Shipment::query()->where('customer_order_id', $order->id)->exists()) {
                throw new ApiException(409, 'shipment_exists');
            }

            if ($order->status !== OrderStatus::ReadyToShip) {
                throw new ApiException(409, 'invalid_transition');
            }

            $actor = Actor::admin($by->id);
            $now = CarbonImmutable::now();
            $shipment = Shipment::create([
                'customer_order_id' => $order->id,
                'courier' => $input['courier'],
                'tracking_number' => $input['tracking_number'],
                'tracking_url' => $input['tracking_url'] ?? null,
                'shipping_cost_halalas' => $input['shipping_cost_halalas'] ?? null,
                'status' => ShipmentStatus::PickedUp,
                'shipped_at' => $now,
                'created_by_admin_id' => $by->id,
            ]);
            $this->appendEvent($shipment, ShipmentStatus::PickedUp, null, null, $now, $actor);
            $this->history->record($shipment, null, ShipmentStatus::PickedUp, $actor);

            $order->status = OrderStatus::Shipped;
            $order->save();
            $this->history->record($order, OrderStatus::ReadyToShip, OrderStatus::Shipped, $actor);
            $this->audit->record($actor, 'shipment.create', $shipment, null, [
                'order' => $order->order_number,
                'courier' => $shipment->courier,
                'tracking_number' => $shipment->tracking_number,
            ]);
            $this->notifier->send($order->user()->firstOrFail(), NotificationTemplate::Shipped, $order, [
                'order' => $order->order_number,
                'courier' => $shipment->courier,
                'tracking' => $shipment->tracking_number,
            ], "shipped:customer_order:{$order->id}");

            return $shipment;
        });
    }

    /**
     * A tracking update. Updates may arrive out of order: the newest one
     * sets the shipment's status, and `delivered` always does; none is
     * dated before the pickup. Delivery marks the order delivered and
     * starts its return window. Nothing follows a delivered, returned or
     * cancelled shipment.
     *
     * @param  array{status: string, description?: string|null, location?: string|null, occurred_at: string}  $input
     */
    public function addEvent(string $shipmentUuid, array $input, AdminUser $by): Shipment
    {
        return DB::transaction(function () use ($shipmentUuid, $input, $by): Shipment {
            $orderId = Shipment::query()->where('uuid', $shipmentUuid)->value('customer_order_id')
                ?? throw (new ModelNotFoundException)->setModel(Shipment::class);
            [$campaign, $order] = $this->lockOrder(CustomerOrder::query()->whereKey($orderId)->value('uuid'));
            $shipment = Shipment::query()->where('uuid', $shipmentUuid)->lockForUpdate()->firstOrFail();

            if ($shipment->status->isFinal()) {
                throw new ApiException(409, 'invalid_transition');
            }

            $actor = Actor::admin($by->id);
            $status = ShipmentStatus::from($input['status']);
            $occurredAt = CarbonImmutable::parse($input['occurred_at']);

            // Nothing happens to a shipment before the courier picked it up;
            // a delivery dated earlier would cut the return window short.
            if ($occurredAt->lessThan($shipment->shipped_at)) {
                throw ApiException::unprocessable('event_before_shipment', 'occurred_at');
            }

            $newest = ShipmentEvent::query()->where('shipment_id', $shipment->id)->max('occurred_at');
            $this->appendEvent($shipment, $status, $input['description'] ?? null, $input['location'] ?? null, $occurredAt, $actor);
            $from = $shipment->status;

            if ($status === ShipmentStatus::Delivered || $newest === null || ! $occurredAt->lessThan(CarbonImmutable::parse($newest))) {
                $shipment->status = $status;
                $shipment->delivered_at = $status === ShipmentStatus::Delivered ? $occurredAt : null;
                $shipment->save();

                if ($shipment->status !== $from) {
                    $this->history->record($shipment, $from, $shipment->status, $actor, null, ['occurred_at' => $occurredAt->toIso8601ZuluString()]);
                }
            }

            $this->audit->record($actor, 'shipment.event', $shipment, ['status' => $from->value], [
                'status' => $shipment->status->value,
                'event' => $status->value,
                'occurred_at' => $occurredAt->toIso8601ZuluString(),
            ]);

            if ($status === ShipmentStatus::Delivered) {
                $this->delivered($campaign, $order, $occurredAt, $actor);
            }

            return $shipment;
        });
    }

    /**
     * Delivered orders whose return window has ended are completed; so is
     * any campaign left with nothing open. Returns how many orders moved.
     */
    public function completeDelivered(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $completed = 0;
        $due = CustomerOrder::query()
            ->where('status', OrderStatus::Delivered)
            ->where('return_window_ends_at', '<=', $now)
            ->orderBy('id')
            ->pluck('uuid');

        foreach ($due as $uuid) {
            $completed += DB::transaction(function () use ($uuid, $now): int {
                [$campaign, $order] = $this->lockOrder($uuid);

                if ($order->status !== OrderStatus::Delivered || $order->return_window_ends_at->greaterThan($now)) {
                    return 0;
                }

                $order->status = OrderStatus::Completed;
                $order->save();
                $this->history->record($order, OrderStatus::Delivered, OrderStatus::Completed, Actor::system(), 'return_window_ended');
                $this->completeCampaignIfSettled($campaign, Actor::system());

                return 1;
            });
        }

        return $completed;
    }

    /**
     * The campaign is completed once none of its customer orders is still
     * on its way. Call with the campaign locked.
     */
    public function completeCampaignIfSettled(Campaign $campaign, Actor $actor): void
    {
        if ($campaign->status !== CampaignStatus::Fulfilling) {
            return;
        }

        $open = CustomerOrder::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotIn('status', self::SETTLED)
            ->exists();

        if (! $open) {
            $this->lifecycle->complete($campaign, $actor);
        }
    }

    private function delivered(Campaign $campaign, CustomerOrder $order, CarbonImmutable $at, Actor $actor): void
    {
        if ($order->status !== OrderStatus::Shipped) {
            return;
        }

        $order->status = OrderStatus::Delivered;
        $order->delivered_at = $at;
        $order->return_window_ends_at = $at->addDays((int) config('groupbuy.orders.return_window_days'));
        $order->save();
        $this->history->record($order, OrderStatus::Shipped, OrderStatus::Delivered, $actor);
        $this->ledger->recogniseRevenue($order, $actor);
        $this->notifier->send($order->user()->firstOrFail(), NotificationTemplate::Delivered, $order, [
            'order' => $order->order_number,
            'return_by_at' => $order->return_window_ends_at->toIso8601ZuluString(),
        ], "delivered:customer_order:{$order->id}");
        $this->completeCampaignIfSettled($campaign, $actor);
    }

    private function appendEvent(Shipment $shipment, ShipmentStatus $status, ?string $description, ?string $location, CarbonImmutable $at, Actor $actor): void
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'description' => $description,
            'location' => $location,
            'source' => 'admin',
            'occurred_at' => $at,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
        ]);
    }

    /**
     * @return array{Campaign, CustomerOrder}
     */
    private function lockOrder(?string $orderUuid): array
    {
        $campaignId = CustomerOrder::query()->where('uuid', $orderUuid)->value('campaign_id')
            ?? throw (new ModelNotFoundException)->setModel(CustomerOrder::class);
        $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
        $order = CustomerOrder::query()->where('uuid', $orderUuid)->lockForUpdate()->firstOrFail();

        return [$campaign, $order];
    }
}
