<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\MismatchType;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $reconciliation_run_id
 * @property int|null $payment_authorization_id
 * @property string|null $provider_payment_id
 * @property MismatchType $mismatch_type
 * @property array<string, mixed>|null $our_value
 * @property array<string, mixed>|null $provider_value
 * @property int|null $resolved_by_admin_id
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ReconciliationRun $run
 * @property-read PaymentAuthorization|null $payment
 * @property-read AdminUser|null $resolver
 */
class ReconciliationItem extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'mismatch_type' => MismatchType::class,
            'our_value' => 'array',
            'provider_value' => 'array',
            'resolved_at' => 'immutable_datetime',
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
     * @return BelongsTo<ReconciliationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    /**
     * @return BelongsTo<PaymentAuthorization, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }
}
