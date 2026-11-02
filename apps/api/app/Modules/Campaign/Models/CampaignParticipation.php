<?php

namespace App\Modules\Campaign\Models;

use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One customer's join: the unit that is held, captured and turned into an
 * order. Created by App\Modules\Campaign\Services\Joins.
 *
 * @property int $id
 * @property string $uuid
 * @property int $campaign_id
 * @property int $user_id
 * @property int $product_variant_id
 * @property int $qty
 * @property int $hold_unit_price_halalas
 * @property int $hold_amount_halalas
 * @property int $tier_version
 * @property int|null $final_unit_price_halalas
 * @property int|null $capture_amount_halalas
 * @property string $currency
 * @property int|null $delivery_address_id
 * @property array<string, mixed> $delivery_address Snapshot taken at join.
 * @property ParticipationStatus $status
 * @property CarbonImmutable $reservation_expires_at
 * @property string $client_request_id
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $captured_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Campaign $campaign
 * @property-read User $user
 * @property-read ProductVariant $variant
 * @property-read PaymentAuthorization|null $latestPayment
 * @property-read CustomerOrder|null $order
 */
class CampaignParticipation extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'status' => ParticipationStatus::class,
            'qty' => 'integer',
            'hold_unit_price_halalas' => 'integer',
            'hold_amount_halalas' => 'integer',
            'tier_version' => 'integer',
            'final_unit_price_halalas' => 'integer',
            'capture_amount_halalas' => 'integer',
            'delivery_address' => 'array',
            'reservation_expires_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
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
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    /**
     * @return HasMany<PaymentAuthorization, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentAuthorization::class, 'participation_id');
    }

    /**
     * The latest payment attempt (a second hold found for the same form,
     * which is only voided, does not count).
     *
     * @return HasOne<PaymentAuthorization, $this>
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(PaymentAuthorization::class, 'participation_id')
            ->ofMany(['id' => 'max'], fn (Builder $query) => $query->attempts());
    }

    /**
     * The order made once the hold was captured.
     *
     * @return HasOne<CustomerOrder, $this>
     */
    public function order(): HasOne
    {
        return $this->hasOne(CustomerOrder::class, 'participation_id');
    }
}
