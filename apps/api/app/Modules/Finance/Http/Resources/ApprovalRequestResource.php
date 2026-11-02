<?php

namespace App\Modules\Finance\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Finance\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `ApprovalRequest` schema. Load RELATIONS first.
 *
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    public const RELATIONS = ['subject', 'requester', 'decider'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject->getAttribute('uuid'),
            'payload' => (object) $this->payload,
            'status' => $this->status->value,
            'requested_by' => ActorJson::admin($this->requester),
            'decided_by' => ActorJson::admin($this->decider),
            'reason' => $this->reason,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
        ];
    }
}
