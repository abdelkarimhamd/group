<?php

namespace App\Modules\Campaign\Models;

use App\Models\Concerns\AppendOnlyModel;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only campaign history: every status change, price change and
 * milestone. Written through App\Modules\Campaign\Services\CampaignEvents.
 *
 * @property int $id
 * @property string $uuid
 * @property int $campaign_id
 * @property string $event_type
 * @property CampaignStatus|null $from_status
 * @property CampaignStatus|null $to_status
 * @property int $committed_qty_after
 * @property array<string, mixed> $payload
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $request_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
class CampaignEvent extends Model
{
    use AppendOnlyModel, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'from_status' => CampaignStatus::class,
            'to_status' => CampaignStatus::class,
            'committed_qty_after' => 'integer',
            'payload' => 'array',
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
     * The admin who acted, when `actor_type` is admin. Load with userActor.
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
