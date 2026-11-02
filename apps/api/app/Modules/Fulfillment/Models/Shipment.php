<?php

namespace App\Modules\Fulfillment\Models;

use App\Modules\Fulfillment\Enums\ShipmentStatus;
use App\Modules\Orders\Models\CustomerOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The last mile of one customer order (warehouse to customer).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_order_id
 * @property string $type
 * @property string $courier
 * @property string $tracking_number
 * @property string|null $tracking_url
 * @property ShipmentStatus $status
 * @property int|null $shipping_cost_halalas
 * @property CarbonImmutable $shipped_at
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $created_by_admin_id
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
