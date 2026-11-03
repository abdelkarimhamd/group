<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Models\ReconciliationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `ReconciliationRun` schema.
 *
 * @mixin ReconciliationRun
 */
class ReconciliationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'run_date' => $this->run_date->toDateString(),
            'provider' => $this->provider,
            'status' => $this->status->value,
            'payments_checked' => $this->payments_checked,
            'mismatch_count' => $this->mismatch_count,
            'started_at' => $this->started_at->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
        ];
    }
}
