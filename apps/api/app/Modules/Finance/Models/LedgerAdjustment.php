<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\LedgerAdjustmentStatus;
use App\Modules\Identity\Models\AdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A hand-made ledger posting (or the reversal of one) waiting for, or
 * posted after, a second admin's approval. Written by
 * App\Modules\Finance\Services\LedgerAdjustments.
 *
 * @property int $id
 * @property string $uuid
 * @property string $description
 * @property list<array{account: string, direction: 'D'|'C', amount_halalas: int}>|null $lines
 * @property int|null $reverses_transaction_id
 * @property LedgerAdjustmentStatus $status
 * @property int $requested_by_admin_id
 * @property int|null $ledger_transaction_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read LedgerTransaction|null $reverses
 * @property-read LedgerTransaction|null $posting
 * @property-read AdminUser $requester
 * @property-read ApprovalRequest|null $approvalRequest
 */
class LedgerAdjustment extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => LedgerAdjustmentStatus::class,
            'lines' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'reverses_transaction_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_id');
    }

    /**
     * The second-admin request it waits on (one per adjustment).
     *
     * @return MorphOne<ApprovalRequest, $this>
     */
    public function approvalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'subject');
    }
}
