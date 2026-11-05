<?php

namespace App\Modules\Fulfillment\Models;

use App\Modules\Fulfillment\Enums\BookingStatus;
use App\Modules\Fulfillment\Enums\ShipmentStatus;
use App\Modules\Orders\Models\CustomerOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The last mile of one customer order (warehouse to customer). Handed to
 * the courier by hand (`picked_up` at once) or booked through the
 * courier's API (`pending`, with `booking_status`, until the courier
 * collects it).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_order_id
 * @property string $type
 * @property string $courier
 * @property string|null $tracking_number Null only while a booking waits for the courier's answer.
 * @property string|null $tracking_url
 * @property ShipmentStatus $status
 * @property int|null $shipping_cost_halalas
 * @property CarbonImmutable|null $shipped_at When the courier collected it.
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $created_by_admin_id
 * @property BookingStatus|null $booking_status Null when handed over by hand.
 * @property string|null $booking_key Our reference at the courier.
 * @property CarbonImmutable|null $booking_requested_at
 * @property CarbonImmutable|null $booking_sent_at Set just before the call to the courier.
 * @property string|null $booking_error
 * @property string|null $label_path On the documents disk.
 * @property CarbonImmutable|null $last_tracked_at
 * @property-read CustomerOrder $order
 * @property-read Collection<int, ShipmentEvent> $events
 */
class Shipment extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'warehouse_to_customer',
        'status' => 'picked_up',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipping_cost_halalas' => 'integer',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'booking_status' => BookingStatus::class,
            'booking_requested_at' => 'immutable_datetime',
            'booking_sent_at' => 'immutable_datetime',
            'last_tracked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<CustomerOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /**
     * Oldest first.
     *
     * @return HasMany<ShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
