<?php

namespace App\Modules\Fulfillment\Http\Resources;

use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The `AdminShipment` schema: a shipment as staff see it, with its courier
 * booking and where each update came from.
 *
 * @mixin Shipment
 */
class AdminShipmentResource extends JsonResource
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
            'shipped_at' => $this->shipped_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'booking' => $this->booking_status === null ? null : [
                'status' => $this->booking_status->value,
                'reference' => $this->booking_key,
                'requested_at' => $this->booking_requested_at?->toIso8601ZuluString(),
                'error' => $this->booking_error,
                'label_url' => $this->label_path === null ? null : Storage::disk((string) config('groupbuy.media.documents_disk'))->temporaryUrl(
                    $this->label_path,
                    now()->addMinutes((int) config('groupbuy.media.attachment_url_minutes')),
                ),
            ],
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'events' => $this->events->map(fn (ShipmentEvent $event) => [
                'status' => $event->status->value,
                'description' => $event->description,
                'location' => $event->location,
                'source' => $event->source,
                'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
            ])->all(),
        ];
    }
}
