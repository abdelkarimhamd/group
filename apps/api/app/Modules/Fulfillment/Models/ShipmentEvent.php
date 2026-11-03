<?php

namespace App\Modules\Fulfillment\Models;

use App\Modules\Fulfillment\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One tracking update, from the courier's API or entered by an admin.
 * Append-only.
 *
 * @property int $id
 * @property int $shipment_id
 * @property ShipmentStatus $status
 * @property string|null $description
 * @property string|null $location
 * @property string $source courier_api or admin
 * @property array<string, mixed>|null $raw_payload
 * @property CarbonImmutable $occurred_at
 * @property string $actor_type
 * @property int|null $actor_id
 * @property CarbonImmutable $created_at
 */
class ShipmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'raw_payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
