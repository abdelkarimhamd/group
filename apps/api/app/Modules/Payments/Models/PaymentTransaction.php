<?php

namespace App\Modules\Payments\Models;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to Moyasar, written (as `pending`) before the call is sent. Its
 * idempotency key is unique, so a retry reuses the row instead of making a
 * second call. The result fields are written once.
 *
 * @property int $id
 * @property string $uuid
 * @property int $payment_authorization_id
 * @property TransactionType $type
 * @property int $amount_halalas
 * @property string $currency
 * @property string $idempotency_key
 * @property TransactionStatus $status
 * @property string|null $provider_reference
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $response_payload
 * @property string $requested_by_type
 * @property int|null $requested_by_id
 * @property int $attempts
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PaymentAuthorization $payment
 */
class PaymentTransaction extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'currency' => 'SAR',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount_halalas' => 'integer',
            'response_payload' => 'array',
            'attempts' => 'integer',
            'completed_at' => 'immutable_datetime',
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
     * @return BelongsTo<PaymentAuthorization, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }

    /**
     * The admin who acted, when `requested_by_type` is admin. Load with userActor.
     *
     * @return BelongsTo<AdminUser, $this>
     */
    public function adminActor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_id');
    }

    /**
     * The customer who acted, when `requested_by_type` is user.
     *
     * @return BelongsTo<User, $this>
     */
    public function userActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
