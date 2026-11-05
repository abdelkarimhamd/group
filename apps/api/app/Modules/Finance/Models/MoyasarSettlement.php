<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AppendOnlyModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payout from Moyasar to the bank, as Moyasar reported it, with its
 * ledger posting. Append-only: imported once by
 * App\Modules\Finance\Services\Settlements.
 *
 * @property int $id
 * @property string $uuid
 * @property string $provider_settlement_id
 * @property string $currency
 * @property int $gross_halalas
 * @property int $fee_halalas
 * @property int $fee_vat_halalas
 * @property int $net_halalas
 * @property CarbonImmutable|null $settled_at
 * @property int $line_count
 * @property int $unmatched_count
 * @property array<string, mixed> $raw
 * @property int|null $ledger_transaction_id
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, MoyasarSettlementLine> $lines
 * @property-read LedgerTransaction|null $posting
 */
class MoyasarSettlement extends Model
{
    use AppendOnlyModel, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'gross_halalas' => 'integer',
            'fee_halalas' => 'integer',
            'fee_vat_halalas' => 'integer',
            'net_halalas' => 'integer',
            'line_count' => 'integer',
            'unmatched_count' => 'integer',
            'raw' => 'array',
            'settled_at' => 'immutable_datetime',
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<MoyasarSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MoyasarSettlementLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }
}
