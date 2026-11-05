<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AppendOnlyModel;
use App\Modules\Finance\Enums\LedgerTransactionType;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\CustomerOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One business event in the double-entry ledger. Append-only: written by
 * App\Modules\Finance\Services\Ledger, corrected only by a reversal.
 *
 * @property int $id
 * @property string $uuid
 * @property LedgerTransactionType $type
 * @property string $reference
 * @property string $description
 * @property int|null $customer_order_id
 * @property int|null $approval_request_id
 * @property int|null $reverses_transaction_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, LedgerEntry> $entries
 * @property-read CustomerOrder|null $order
 * @property-read ApprovalRequest|null $approvalRequest
 * @property-read LedgerTransaction|null $reverses
 * @property-read AdminUser|null $adminActor
 * @property-read User|null $userActor
 */
class LedgerTransaction extends Model
{
    use AppendOnlyModel, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return BelongsTo<CustomerOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * The posting this one reverses.
     *
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * The admin who acted, when `actor_type` is admin.
     *
     * @return BelongsTo<AdminUser, $this>
     */
    public function adminActor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }

    /**
     * The customer who acted, when `actor_type` is user.
     *
     * @return BelongsTo<User, $this>
     */
    public function userActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
