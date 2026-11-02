<?php

namespace App\Models;

use App\Models\Concerns\AppendOnlyModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One admin, customer or system action, kept forever.
 *
 * @property int $id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $occurred_at
 */
class AuditLog extends Model
{
    use AppendOnlyModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
