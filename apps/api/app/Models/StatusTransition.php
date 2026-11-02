<?php

namespace App\Models;

use App\Models\Concerns\AppendOnlyModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One status change of a participation, payment, refund, order, supplier
 * purchase order or invoice. Written in the same transaction as the change.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string $actor_type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $request_id
 * @property CarbonImmutable $occurred_at
 */
class StatusTransition extends Model
{
    use AppendOnlyModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
