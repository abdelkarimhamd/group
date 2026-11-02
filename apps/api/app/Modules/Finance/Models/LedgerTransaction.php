<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\LedgerTransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LedgerEntry> $entries
 */
class LedgerTransaction extends Model
{
    use HasUuids;

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
}
