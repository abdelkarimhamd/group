<?php

namespace App\Modules\Fulfillment\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\TrackingUpdate;
use App\Modules\Fulfillment\Enums\BookingStatus;
use App\Modules\Fulfillment\Enums\ShipmentStatus;
use App\Modules\Fulfillment\Jobs\BookShipment;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The last mile (spec, E13): shipments from the warehouse to customers.
 * Staff hand a parcel to the courier and enter its tracking by hand, or
 * book it through the courier's API and let tracking come from the
 * courier. The order is `shipped` once the courier has the parcel;
 * delivery starts the return window; a parcel the courier brings back puts
 * the order back to `ready_to_ship`. Once every order of a campaign is
 * settled the campaign is completed. Lock order: campaign, customer order,
 * shipment.
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
        private readonly CourierGateway $courier,
    ) {}

    /**
     * Hand a ready order to the courier by hand: the shipment starts
     * `picked_up` and the order becomes `shipped`.
     *
     * @param  array{courier: string, tracking_number: string, tracking_url?: string|null, shipping_cost_halalas?: int|null}  $input
     */
    public function create(string $orderUuid, array $input, AdminUser $by): Shipment
    {
        return DB::transaction(function () use ($orderUuid, $input, $by): Shipment {
            [, $order] = $this->lockOrder($orderUuid);
            self::assertShippable($order);

            $actor = Actor::admin($by->id);
            $now = CarbonImmutable::now();
            $shipment = Shipment::create([
                'customer_order_id' => $order->id,
                'courier' => $input['courier'],
                'tracking_number' => $input['tracking_number'],
                'tracking_url' => ($input['tracking_url'] ?? null) ?? self::trackingUrl($input['courier'], $input['tracking_number']),
                'shipping_cost_halalas' => $input['shipping_cost_halalas'] ?? null,
                'status' => ShipmentStatus::PickedUp,
                'shipped_at' => $now,
                'created_by_admin_id' => $by->id,
            ]);
            $this->appendEvent($shipment, ShipmentStatus::PickedUp, null, null, $now, $actor);
            $this->history->record($shipment, null, ShipmentStatus::PickedUp, $actor);
            $this->handedOver($order, $shipment, $actor);
            $this->audit->record($actor, 'shipment.create', $shipment, null, [
                'order' => $order->order_number,
                'courier' => $shipment->courier,
                'tracking_number' => $shipment->tracking_number,
            ]);

            return $shipment;
        });
    }

    /**
     * Book a ready order with the courier's API. The shipment starts
     * `pending` with its booking asked for; the call goes out on the queue
     * after the commit (CourierBookings). The order stays `ready_to_ship`
     * until the courier collects the parcel.
     *
     * @param  array{shipping_cost_halalas?: int|null}  $input
     *
     * @throws ApiException 409 courier_api_off, shipment_exists or invalid_transition
     */
    public function book(string $orderUuid, array $input, AdminUser $by): Shipment
    {
        if (! $this->courier->books()) {
            throw new ApiException(409, 'courier_api_off');
        }

        return DB::transaction(function () use ($orderUuid, $input, $by): Shipment {
            [, $order] = $this->lockOrder($orderUuid);
            self::assertShippable($order);

            $actor = Actor::admin($by->id);
            $shipment = Shipment::create([
                'customer_order_id' => $order->id,
                'courier' => (string) $this->courier->name(),
                'tracking_number' => null,
                'shipping_cost_halalas' => $input['shipping_cost_halalas'] ?? null,
                'status' => ShipmentStatus::Pending,
                'shipped_at' => null,
                'booking_status' => BookingStatus::Requested,
                'booking_key' => (string) Str::uuid(),
                'booking_requested_at' => CarbonImmutable::now(),
                'created_by_admin_id' => $by->id,
            ]);
            $this->history->record($shipment, null, ShipmentStatus::Pending, $actor, 'booking_requested');
            $this->audit->record($actor, 'shipment.book', $shipment, null, [
                'order' => $order->order_number,
                'courier' => $shipment->courier,
                'reference' => $shipment->booking_key,
            ]);
            BookShipment::dispatch($shipment->id)->afterCommit();

            return $shipment;
        });
    }

    /**
     * Call off a shipment the courier has not collected: a booking staff no
     * longer want, or one made in the courier's portal by mistake. The
     * order stays `ready_to_ship` for another shipment. A booking still
     * waiting for the courier's answer cannot be called off.
     *
     * @throws ApiException 409 booking_in_progress or invalid_transition
     */
    public function cancel(string $shipmentUuid, ?string $reason, AdminUser $by): Shipment
    {
        return DB::transaction(function () use ($shipmentUuid, $reason, $by): Shipment {
            [, , $shipment] = $this->lockShipment($shipmentUuid);

            if ($shipment->status !== ShipmentStatus::Pending) {
                throw new ApiException(409, 'invalid_transition');
            }

            if ($shipment->booking_status === BookingStatus::Requested) {
                throw new ApiException(409, 'booking_in_progress');
            }

            $actor = Actor::admin($by->id);
            $shipment->status = ShipmentStatus::Cancelled;
            $shipment->save();
            $this->history->record($shipment, ShipmentStatus::Pending, ShipmentStatus::Cancelled, $actor, 'cancelled_by_admin');
            $this->audit->record($actor, 'shipment.cancel', $shipment, ['status' => ShipmentStatus::Pending->value], [
                'status' => ShipmentStatus::Cancelled->value,
                'reason' => $reason,
            ]);

            return $shipment;
        });
    }

    /**
     * A tracking update entered by staff. Updates may arrive out of order:
     * the newest one sets the shipment's status, and `delivered` and
     * `returned` always do; none is dated before the pickup. Nothing
     * follows a delivered, returned or cancelled shipment.
     *
     * @param  array{status: string, description?: string|null, location?: string|null, occurred_at: string}  $input
     */
    public function addEvent(string $shipmentUuid, array $input, AdminUser $by): Shipment
    {
        return DB::transaction(function () use ($shipmentUuid, $input, $by): Shipment {
            [$campaign, $order, $shipment] = $this->lockShipment($shipmentUuid);
            $status = ShipmentStatus::from($input['status']);

            if ($shipment->status->isFinal() || ($shipment->status === ShipmentStatus::Pending && $status === ShipmentStatus::Returned)) {
                throw new ApiException(409, 'invalid_transition');
            }

            // A booking without the courier's answer has no parcel to track yet.
            if ($shipment->tracking_number === null) {
                throw new ApiException(409, 'booking_in_progress');
            }

            $occurredAt = CarbonImmutable::parse($input['occurred_at']);

            // Nothing happens to a shipment before the courier picked it up;
            // a delivery dated earlier would cut the return window short.
            if ($shipment->shipped_at !== null && $occurredAt->lessThan($shipment->shipped_at)) {
                throw ApiException::unprocessable('event_before_shipment', 'occurred_at');
            }

            $actor = Actor::admin($by->id);
            $from = $shipment->status;
            $this->apply($campaign, $order, $shipment, $status, $input['description'] ?? null, $input['location'] ?? null, $occurredAt, $actor);
            $this->audit->record($actor, 'shipment.event', $shipment, ['status' => $from->value], [
                'status' => $shipment->status->value,
                'event' => $status->value,
                'occurred_at' => $occurredAt->toIso8601ZuluString(),
            ]);

            return $shipment;
        });
    }

    /**
     * Tracking read from the courier for one shipment. Each update is
     * recorded once (by the courier's key); its code becomes a status
     * through `$codes`, codes in `$ignore` are not movements, and any other
     * code means the parcel moved: `picked_up` if the courier had not
     * collected it yet, otherwise `in_transit`. Returns how many updates
     * were new.
     *
     * @param  list<TrackingUpdate>  $updates
     * @param  array<string, string>  $codes
     * @param  list<string>  $ignore
     */
    public function applyCourierUpdates(int $shipmentId, array $updates, array $codes, array $ignore): int
    {
        usort($updates, fn (TrackingUpdate $a, TrackingUpdate $b) => $a->occurredAt <=> $b->occurredAt);

        return DB::transaction(function () use ($shipmentId, $updates, $codes, $ignore): int {
            $uuid = Shipment::query()->whereKey($shipmentId)->value('uuid')
                ?? throw (new ModelNotFoundException)->setModel(Shipment::class);
            [$campaign, $order, $shipment] = $this->lockShipment($uuid);
            $recorded = 0;

            foreach ($updates as $update) {
                if ($shipment->status->isFinal() || in_array($update->code, $ignore, true)) {
                    continue;
                }

                if (ShipmentEvent::query()->where('shipment_id', $shipment->id)->where('external_key', $update->key())->exists()) {
                    continue;
                }

                $status = isset($codes[$update->code]) ? ShipmentStatus::tryFrom($codes[$update->code]) : null;

                if ($status === null) {
                    Log::info('courier.unmapped_code', ['courier' => $shipment->courier, 'code' => $update->code, 'description' => $update->description]);
                    $status = $shipment->status === ShipmentStatus::Pending ? ShipmentStatus::PickedUp : ShipmentStatus::InTransit;
                }

                $this->apply($campaign, $order, $shipment, $status, $update->description, $update->location, $update->occurredAt, Actor::system(), 'courier_api', $update->raw, $update->key());
                $recorded++;
            }

            $shipment->last_tracked_at = CarbonImmutable::now();
            $shipment->save();

            return $recorded;
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

    /**
     * The campaign, order and shipment, locked in that order.
     *
     * @return array{Campaign, CustomerOrder, Shipment}
     */
    public function lockShipment(string $shipmentUuid): array
    {
        $orderId = Shipment::query()->where('uuid', $shipmentUuid)->value('customer_order_id')
            ?? throw (new ModelNotFoundException)->setModel(Shipment::class);
        [$campaign, $order] = $this->lockOrder(CustomerOrder::query()->whereKey($orderId)->value('uuid'));
        $shipment = Shipment::query()->where('uuid', $shipmentUuid)->lockForUpdate()->firstOrFail();

        return [$campaign, $order, $shipment];
    }

    /**
     * Where the customer follows a parcel of this courier, if a link
     * pattern is configured for it.
     */
    public static function trackingUrl(string $courier, string $trackingNumber): ?string
    {
        $pattern = config('groupbuy.couriers.tracking_urls.'.mb_strtolower(trim($courier)));

        return is_string($pattern) && $pattern !== '' ? str_replace('{number}', rawurlencode($trackingNumber), $pattern) : null;
    }

    /**
     * Record one update and move the shipment and order. The newest update
     * sets the status, `delivered` and `returned` always do. The first
     * movement of a booked parcel means the courier has it.
     *
     * @param  array<string, mixed>|null  $raw
     */
    private function apply(Campaign $campaign, CustomerOrder $order, Shipment $shipment, ShipmentStatus $status, ?string $description, ?string $location, CarbonImmutable $occurredAt, Actor $actor, string $source = 'admin', ?array $raw = null, ?string $externalKey = null): void
    {
        $newest = ShipmentEvent::query()->where('shipment_id', $shipment->id)->max('occurred_at');
        $this->appendEvent($shipment, $status, $description, $location, $occurredAt, $actor, $source, $raw, $externalKey);
        $from = $shipment->status;

        if ($from === ShipmentStatus::Pending) {
            $shipment->shipped_at = $occurredAt;
            $this->handedOver($order, $shipment, $actor);
        }

        if (in_array($status, [ShipmentStatus::Delivered, ShipmentStatus::Returned], true) || $newest === null || ! $occurredAt->lessThan(CarbonImmutable::parse($newest))) {
            $shipment->status = $status;
            $shipment->delivered_at = $status === ShipmentStatus::Delivered ? $occurredAt : null;
        }

        $shipment->save();

        if ($shipment->status !== $from) {
            $this->history->record($shipment, $from, $shipment->status, $actor, null, ['occurred_at' => $occurredAt->toIso8601ZuluString()]);
        }

        if ($shipment->status === ShipmentStatus::Delivered && $from !== ShipmentStatus::Delivered) {
            $this->delivered($campaign, $order, $occurredAt, $actor);
        }

        if ($shipment->status === ShipmentStatus::Returned && $from !== ShipmentStatus::Returned) {
            $this->returnedToSender($order, $shipment, $actor);
        }
    }

    /**
     * The courier has the parcel: the order is `shipped` and the customer
     * gets the tracking number.
     */
    private function handedOver(CustomerOrder $order, Shipment $shipment, Actor $actor): void
    {
        if ($order->status !== OrderStatus::ReadyToShip) {
            return;
        }

        $order->status = OrderStatus::Shipped;
        $order->save();
        $this->history->record($order, OrderStatus::ReadyToShip, OrderStatus::Shipped, $actor);
        $this->notifier->send($order->user()->firstOrFail(), NotificationTemplate::Shipped, $order, [
            'order' => $order->order_number,
            'courier' => $shipment->courier,
            'tracking' => (string) $shipment->tracking_number,
        ], "shipped:shipment:{$shipment->id}");
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

    /**
     * The courier could not deliver and brought the parcel back: the goods
     * are in the warehouse again and the order is ready to ship, for a new
     * shipment or a refund.
     */
    private function returnedToSender(CustomerOrder $order, Shipment $shipment, Actor $actor): void
    {
        if ($order->status !== OrderStatus::Shipped) {
            return;
        }

        $order->status = OrderStatus::ReadyToShip;
        $order->save();
        $this->history->record($order, OrderStatus::Shipped, OrderStatus::ReadyToShip, $actor, 'returned_to_sender');
        $this->notifier->send($order->user()->firstOrFail(), NotificationTemplate::DeliveryFailed, $order, [
            'order' => $order->order_number,
        ], "delivery_failed:shipment:{$shipment->id}");
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function appendEvent(Shipment $shipment, ShipmentStatus $status, ?string $description, ?string $location, CarbonImmutable $at, Actor $actor, string $source = 'admin', ?array $raw = null, ?string $externalKey = null): void
    {
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'description' => $description,
            'location' => $location,
            'source' => $source,
            'raw_payload' => $raw,
            'external_key' => $externalKey,
            'occurred_at' => $at,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
        ]);
    }

    /**
     * One open shipment per order, and only once it is ready.
     */
    private static function assertShippable(CustomerOrder $order): void
    {
        $open = Shipment::query()
            ->where('customer_order_id', $order->id)
            ->whereNotIn('status', [ShipmentStatus::Returned, ShipmentStatus::Cancelled])
            ->exists();

        if ($open) {
            throw new ApiException(409, 'shipment_exists');
        }

        if ($order->status !== OrderStatus::ReadyToShip) {
            throw new ApiException(409, 'invalid_transition');
        }
    }

    /**
     * The campaign and order, locked in that order.
     *
     * @return array{Campaign, CustomerOrder}
     */
    public function lockOrder(?string $orderUuid): array
    {
        $campaignId = CustomerOrder::query()->where('uuid', $orderUuid)->value('campaign_id')
            ?? throw (new ModelNotFoundException)->setModel(CustomerOrder::class);
        $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
        $order = CustomerOrder::query()->where('uuid', $orderUuid)->lockForUpdate()->firstOrFail();

        return [$campaign, $order];
    }
}
