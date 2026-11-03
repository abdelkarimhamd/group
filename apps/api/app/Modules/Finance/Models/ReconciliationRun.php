<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\ReconciliationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property CarbonImmutable $run_date
 * @property string $provider
 * @property ReconciliationStatus $status
 * @property int $payments_checked
 * @property int $mismatch_count
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ReconciliationRun extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
        'provider' => 'moyasar',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'run_date' => 'immutable_date',
            'payments_checked' => 'integer',
            'mismatch_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
     * @return HasMany<ReconciliationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class);
    }
}
