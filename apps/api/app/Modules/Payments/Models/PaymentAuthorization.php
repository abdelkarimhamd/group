<?php

namespace App\Modules\Payments\Models;

use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Moyasar payment attempt, which is one hold on the customer's card.
 * Moyasar's payment carries this row's uuid in its metadata, so a webhook
 * finds the row even if the customer's return to the site is lost.
 *
 * @property int $id
 * @property string $uuid
 * @property int $participation_id
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property string|null $source_type
 * @property string|null $scheme
 * @property string|null $card_last4
 * @property int $authorized_amount_halalas
 * @property int $captured_amount_halalas
 * @property int $refunded_amount_halalas
 * @property string $currency
 * @property PaymentStatus $status
 * @property RejectionReason|null $rejection_reason
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $captured_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable $expires_at When the hold lapses: created + 14 days.
 * @property string|null $provider_status
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CampaignParticipation $participation
 */
class PaymentAuthorization extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'provider' => 'moyasar',
        'status' => 'pending_authorization',
        'captured_amount_halalas' => 0,
        'refunded_amount_halalas' => 0,
        'currency' => 'SAR',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'rejection_reason' => RejectionReason::class,
            'authorized_amount_halalas' => 'integer',
            'captured_amount_halalas' => 'integer',
            'refunded_amount_halalas' => 'integer',
            'authorized_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
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
     * No capture is attempted after this: the hold's expiry minus the cutoff.
     */
    public function captureCutoffAt(): CarbonImmutable
    {
        return $this->expires_at->subHours((int) config('groupbuy.payments.capture_cutoff_hours'));
    }

    /**
     * Attempts the customer made, leaving out second holds found for a
     * form that already had one (recorded only so they can be voided).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function attempts(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('payment_authorizations.rejection_reason')
            ->orWhere('payment_authorizations.rejection_reason', '!=', RejectionReason::DuplicateHold->value));
    }

    /**
     * @return BelongsTo<CampaignParticipation, $this>
     */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(CampaignParticipation::class, 'participation_id');
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }
}
