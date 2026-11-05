<?php

namespace App\Modules\Fulfillment\Services;

use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\CourierUnavailable;
use App\Modules\Fulfillment\Couriers\TrackingUpdate;
use App\Modules\Fulfillment\Enums\ShipmentStatus;
use App\Modules\Fulfillment\Models\Shipment;
use Illuminate\Support\Facades\Log;

/**
 * Reads tracking from the courier's API for every parcel of that courier
 * still on its way, least recently read first, and records what is new
 * (Shipments::applyCourierUpdates). The call goes out with no transaction
 * open; each shipment's updates are then recorded in its own.
 */
final class CourierTracking
{
    public function __construct(
        private readonly CourierGateway $courier,
        private readonly Shipments $shipments,
    ) {}

    /**
     * @return array{read: int, recorded: int}
     */
    public function poll(): array
    {
        $name = $this->courier->name();
        $result = ['read' => 0, 'recorded' => 0];

        if ($name === null) {
            return $result;
        }

        $driver = (string) config('services.courier.driver');
        $codes = (array) config("groupbuy.couriers.{$driver}.codes", []);
        $ignore = array_values((array) config("groupbuy.couriers.{$driver}.ignore", []));

        $due = Shipment::query()
            ->whereRaw('lower(courier) = ?', [mb_strtolower($name)])
            ->whereNotNull('tracking_number')
            ->whereNotIn('status', [ShipmentStatus::Delivered, ShipmentStatus::Returned, ShipmentStatus::Cancelled])
            ->orderByRaw('last_tracked_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit((int) config('groupbuy.couriers.track_per_run'))
            ->get(['id', 'tracking_number']);

        foreach ($due->chunk(max(1, (int) config('groupbuy.couriers.track_per_call'))) as $chunk) {
            try {
                $updates = $this->courier->track($chunk->pluck('tracking_number')->values()->all());
            } catch (CourierUnavailable $e) {
                Log::warning('courier.tracking_unavailable', ['courier' => $name, 'error' => $e->getMessage()]);

                continue;
            }

            $byNumber = [];

            foreach ($updates as $update) {
                $byNumber[$update->trackingNumber][] = $update;
            }

            foreach ($chunk as $shipment) {
                /** @var list<TrackingUpdate> $mine */
                $mine = $byNumber[$shipment->tracking_number] ?? [];
                $result['recorded'] += $this->shipments->applyCourierUpdates($shipment->id, $mine, $codes, $ignore);
                $result['read']++;
            }
        }

        return $result;
    }
}
