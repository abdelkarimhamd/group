<?php

namespace App\Modules\Finance\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AuditLogEntry` schema. Load RELATIONS first; the controller sets
 * `subject_public_id` (the subject's UUID, or its row ID when it has none).
 *
 * @mixin AuditLog
 */
class AuditLogEntryResource extends JsonResource
{
    public const RELATIONS = ['adminActor', 'userActor'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor' => ActorJson::from($this->actor_type, $this->adminActor, $this->userActor),
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->resource->getAttribute('subject_public_id'),
            'before' => $this->before === null ? null : (object) $this->before,
            'after' => $this->after === null ? null : (object) $this->after,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
        ];
    }
}
