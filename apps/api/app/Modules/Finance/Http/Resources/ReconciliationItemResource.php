<?php

namespace App\Modules\Finance\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Finance\Models\ReconciliationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `ReconciliationItem` schema. Load RELATIONS first.
 *
 * @mixin ReconciliationItem
 */
class ReconciliationItemResource extends JsonResource
{
    public const RELATIONS = ['run', 'payment', 'resolver'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'run_id' => $this->run->uuid,
            'payment_id' => $this->payment?->uuid,
            'mismatch_type' => $this->mismatch_type->value,
            'our_value' => $this->our_value === null ? null : (object) $this->our_value,
            'provider_value' => $this->provider_value === null ? null : (object) $this->provider_value,
            'resolved_by' => ActorJson::admin($this->resolver),
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'resolution_note' => $this->resolution_note,
        ];
    }
}
