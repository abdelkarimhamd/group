<?php

namespace App\Modules\Fulfillment\Services;

use App\Modules\Fulfillment\Couriers\Booking;
use App\Modules\Fulfillment\Couriers\BookingRequest;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\CourierRefused;
use App\Modules\Fulfillment\Couriers\CourierUnavailable;
use App\Modules\Fulfillment\Enums\BookingStatus;
use App\Modules\Fulfillment\Enums\ShipmentStatus;
use App\Modules\Fulfillment\Jobs\BookShipment;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Orders\Models\CustomerOrder;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sending a booking to the courier, the way payments are sent: (1) under
 * the locks, mark the booking as going out and commit; (2) log the call
 * and send it, outside any transaction; (3) record the answer under the
 * locks. The shipment row is the attempt record and its `booking_key` our
 * reference at the courier.
 *
 * The courier documents no idempotency, so a booking that went out is
 * never sent again on its own: a refusal, or no clear answer, cancels the
 * shipment with the reason, and staff book again (after checking the
 * courier's portal for the reference, when the answer was unclear).
 */
final class CourierBookings
{
    public function __construct(
        private readonly CourierGateway $courier,
        private readonly Shipments $shipments,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    public function send(int $shipmentId): void
    {
        $shipment = $this->claim($shipmentId);

        if ($shipment === null) {
            return;
        }

        Log::info('courier.book', ['shipment' => $shipment->uuid, 'courier' => $shipment->courier, 'reference' => $shipment->booking_key]);

        try {
            $booking = $this->courier->book($this->request($shipment));
        } catch (CourierRefused $e) {
            $this->failed($shipment->uuid, mb_substr($e->getMessage(), 0, 500));

            return;
        } catch (CourierUnavailable $e) {
            report($e);
            $this->failed($shipment->uuid, mb_substr("No clear answer from the courier ({$e->getMessage()}). Check its portal for reference {$shipment->booking_key} before booking again.", 0, 500));

            return;
        }

        // Logged before it is recorded, so a failure below loses nothing.
        Log::info('courier.booked', ['shipment' => $shipment->uuid, 'reference' => $shipment->booking_key, 'tracking_number' => $booking->trackingNumber]);
        $this->booked($shipment, $booking);
    }

    /**
     * Bookings still waiting for their answer this long after they were
     * asked for. One that never went out (its job was lost) is queued
     * again; one that went out but whose answer was never recorded is
     * cancelled for staff to check. Returns how many of each.
     *
     * @return array{requeued: int, abandoned: int}
     */
    public function sweep(?CarbonImmutable $now = null): array
    {
        $before = ($now ?? CarbonImmutable::now())->subMinutes((int) config('groupbuy.couriers.booking_requeue_after_minutes'));
        $stale = Shipment::query()
            ->where('status', ShipmentStatus::Pending)
            ->where('booking_status', BookingStatus::Requested)
            ->where('booking_requested_at', '<=', $before)
            ->orderBy('id')
            ->get(['id', 'uuid', 'booking_key', 'booking_sent_at']);
        $result = ['requeued' => 0, 'abandoned' => 0];

        foreach ($stale as $shipment) {
            if ($shipment->booking_sent_at === null) {
                BookShipment::dispatch($shipment->id);
                $result['requeued']++;
            } else {
                $this->failed($shipment->uuid, "No answer from the courier was recorded. Check its portal for reference {$shipment->booking_key} before booking again.");
                $result['abandoned']++;
            }
        }

        return $result;
    }

    /**
     * Step 1: take the booking if it is still waiting and has never gone
     * out, and mark it as going out.
     */
    private function claim(int $shipmentId): ?Shipment
    {
        $uuid = Shipment::query()->whereKey($shipmentId)->value('uuid');

        if ($uuid === null) {
            return null;
        }

        return DB::transaction(function () use ($uuid): ?Shipment {
            [, , $shipment] = $this->shipments->lockShipment($uuid);

            if ($shipment->status !== ShipmentStatus::Pending || $shipment->booking_status !== BookingStatus::Requested || $shipment->booking_sent_at !== null) {
                return null;
            }

            $shipment->booking_sent_at = CarbonImmutable::now();
            $shipment->save();

            return $shipment;
        });
    }

    private function request(Shipment $shipment): BookingRequest
    {
        $order = CustomerOrder::query()->with(['variant', 'campaign.product'])->findOrFail($shipment->customer_order_id);
        $address = $order->shipping_address;
        $unitGrams = $order->variant->weight_grams ?? (int) config('groupbuy.couriers.default_unit_weight_grams');

        return new BookingRequest(
            reference: (string) $shipment->booking_key,
            orderNumber: $order->order_number,
            consignee: [
                'name' => (string) ($address['recipient_name'] ?? ''),
                'phone' => (string) ($address['recipient_phone'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'district' => $address['district'] ?? null,
                'street' => $address['street'] ?? null,
                'building_no' => $address['building_no'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'short_address' => $address['short_address'] ?? null,
            ],
            pieces: 1,
            weightGrams: $unitGrams * $order->qty,
            description: "{$order->qty} x {$order->campaign->product->name_en}",
        );
    }

    /**
     * Step 3, success: the tracking number and label. The label is stored
     * before the row points at it; without one, staff print it from the
     * courier's portal.
     */
    private function booked(Shipment $sent, Booking $booking): void
    {
        $disk = (string) config('groupbuy.media.documents_disk');
        $labelPath = null;

        if ($booking->labelPdf !== null) {
            $path = sprintf('labels/%s/%s.pdf', CarbonImmutable::now()->format('Y'), $sent->uuid);

            try {
                $labelPath = Storage::disk($disk)->put($path, $booking->labelPdf) ? $path : null;
            } catch (Throwable $e) {
                report($e);
            }
        }

        DB::transaction(function () use ($sent, $booking, $labelPath): void {
            [, $order, $shipment] = $this->shipments->lockShipment($sent->uuid);

            if ($shipment->booking_status !== BookingStatus::Requested) {
                Log::critical('courier.booked_after_settled', ['shipment' => $shipment->uuid, 'tracking_number' => $booking->trackingNumber]);

                return;
            }

            $shipment->tracking_number = mb_substr($booking->trackingNumber, 0, 100);
            $shipment->tracking_url = Shipments::trackingUrl($shipment->courier, $shipment->tracking_number);
            $shipment->booking_status = BookingStatus::Booked;
            $shipment->label_path = $labelPath;
            $shipment->save();
            $this->audit->record(Actor::system(), 'shipment.booked', $shipment, ['booking_status' => BookingStatus::Requested->value], [
                'booking_status' => BookingStatus::Booked->value,
                'order' => $order->order_number,
                'tracking_number' => $shipment->tracking_number,
            ]);
        });
    }

    /**
     * Step 3, refusal or no clear answer: the shipment is cancelled with
     * the reason, and the order stays ready for another one.
     */
    private function failed(string $shipmentUuid, string $error): void
    {
        DB::transaction(function () use ($shipmentUuid, $error): void {
            [, $order, $shipment] = $this->shipments->lockShipment($shipmentUuid);

            if ($shipment->booking_status !== BookingStatus::Requested) {
                return;
            }

            $actor = Actor::system();
            $shipment->booking_status = BookingStatus::Failed;
            $shipment->booking_error = $error;
            $shipment->status = ShipmentStatus::Cancelled;
            $shipment->save();
            $this->history->record($shipment, ShipmentStatus::Pending, ShipmentStatus::Cancelled, $actor, 'booking_failed');
            $this->audit->record($actor, 'shipment.booking_failed', $shipment, ['booking_status' => BookingStatus::Requested->value], [
                'booking_status' => BookingStatus::Failed->value,
                'order' => $order->order_number,
                'error' => $error,
            ]);
            Log::warning('courier.booking_failed', ['shipment' => $shipment->uuid, 'error' => $error]);
        });
    }
}
