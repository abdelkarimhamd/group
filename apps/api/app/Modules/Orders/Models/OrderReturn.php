<?php

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Enums\ReturnReason;
use App\Modules\Orders\Enums\ReturnStatus;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer return, recorded by staff when the customer asks within the
 * return window. One per order.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_order_id
 * @property ReturnStatus $status
 * @property ReturnReason $reason
 * @property string|null $note
 * @property int $recorded_by_admin_id
 * @property int|null $decided_by_admin_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $refund_id The refund asked for when it was approved.
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerOrder $order
 * @property-read AdminUser $recordedBy
 * @property-read AdminUser|null $decidedBy
 * @property-read Refund|null $refund
 */
class OrderReturn extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'requested',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'reason' => ReturnReason::class,
            'decided_at' => 'immutable_datetime',
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
     * Still waiting for a decision: never decided, or approved with a
     * refund that finance rejected or that failed, so it can be decided
     * again.
     */
    public function isOpen(): bool
    {
        return $this->status === ReturnStatus::Requested
            || ($this->status === ReturnStatus::Approved && $this->refund !== null && $this->refund->status->isDead());
    }

    /**
     * @return BelongsTo<CustomerOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by_admin_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
