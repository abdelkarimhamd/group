<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Actor;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only audit trail. Every admin action, and every action
 * that moves money, is recorded here with who did it and what changed.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        Actor $actor,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => RequestContext::ip(),
            'user_agent' => RequestContext::userAgent(),
            'request_id' => RequestContext::requestId(),
            'occurred_at' => now(),
        ]);
    }
}
