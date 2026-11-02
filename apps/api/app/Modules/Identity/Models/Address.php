<?php

namespace App\Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved delivery address, in Saudi National Address format.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property string $city
 * @property string $district
 * @property string $street
 * @property string $building_no
 * @property string $postal_code
 * @property string|null $additional_no
 * @property string|null $short_address
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_default
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'recipient_name', 'recipient_phone', 'city', 'district', 'street', 'building_no',
    'postal_code', 'additional_no', 'short_address', 'lat', 'lng',
])]
#[UseFactory(AddressFactory::class)]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
