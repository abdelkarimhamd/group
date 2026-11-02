<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Identity\Models\AdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An action that moves money, waiting for a second admin with the finance
 * role. Opened through App\Modules\Finance\Services\Approvals.
 *
 * @property int $id
 * @property string $uuid
 * @property ApprovalType $type
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed> $payload
 * @property int $requested_by_admin_id
 * @property int|null $decided_by_admin_id
 * @property ApprovalStatus $status
 * @property string|null $reason
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Model $subject
 * @property-read AdminUser $requester
 * @property-read AdminUser|null $decider
 */
class ApprovalRequest extends Model
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
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'decided_at' => 'immutable_datetime',
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }
}
