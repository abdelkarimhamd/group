<?php

namespace App\Modules\Campaign\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Campaign\Models\CampaignEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `CampaignEvent` schema. Load RELATIONS first.
 *
 * @mixin CampaignEvent
 */
class CampaignEventResource extends JsonResource
{
    public const RELATIONS = ['adminActor', 'userActor'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_type' => $this->event_type,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'committed_qty_after' => $this->committed_qty_after,
            'payload' => (object) $this->payload,
            'actor' => ActorJson::from($this->actor_type, $this->adminActor, $this->userActor),
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
        ];
    }
}
