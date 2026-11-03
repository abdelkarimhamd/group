<?php

namespace App\Modules\Fulfillment\Http\Resources;

use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Shipment` schema, with its tracking history oldest first. Load
 * `events` first.
 *
 * @mixin Shipment
 */
class ShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'courier' => $this->courier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'status' => $this->status->value,
            'shipped_at' => $this->shipped_at->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'events' => $this->events->map(fn (ShipmentEvent $event) => [
                'status' => $event->status->value,
                'description' => $event->description,
                'location' => $event->location,
                'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
            ])->all(),
        ];
    }
}
