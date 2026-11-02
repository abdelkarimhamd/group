<?php

namespace App\Support\Audit;

use App\Models\StatusTransition;
use App\Support\Actor;
use App\Support\RequestContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Records status changes. Call it inside the same database transaction that
 * changes the status, so the history can never disagree with the record.
 */
final class StatusHistory
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        Model $subject,
        BackedEnum|string|null $from,
        BackedEnum|string $to,
        Actor $actor,
        ?string $reason = null,
        ?array $metadata = null,
    ): StatusTransition {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Status history must be written inside the transaction that changes the status.');
        }

        return StatusTransition::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'from_status' => $from instanceof BackedEnum ? (string) $from->value : $from,
            'to_status' => $to instanceof BackedEnum ? (string) $to->value : $to,
            'reason' => $reason,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'metadata' => $metadata,
            'request_id' => RequestContext::requestId(),
            'occurred_at' => now(),
        ]);
    }
}
