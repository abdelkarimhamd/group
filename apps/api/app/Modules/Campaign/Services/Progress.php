<?php

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Support\Money\Money;

/**
 * The `CampaignProgress` schema: what the campaign page shows live. Only
 * authorized units count; pending payments only reduce what is left to sell.
 */
final class Progress
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Campaign $campaign): array
    {
        return [
            'campaign_id' => $campaign->uuid,
            'status' => $campaign->status->value,
            'committed_qty' => $campaign->committed_qty,
            'min_target_qty' => $campaign->min_target_qty,
            'max_qty' => $campaign->max_qty,
            'remaining_qty' => $campaign->remainingQty(),
            'units_to_target' => $campaign->deal_confirmed_at !== null ? 0 : max(0, $campaign->min_target_qty - $campaign->committed_qty),
            'deal_confirmed_at' => $campaign->deal_confirmed_at?->toIso8601ZuluString(),
            'current_unit_price' => self::currentUnitPrice($campaign),
            'deadline_at' => $campaign->deadline_at?->toIso8601ZuluString(),
            'updated_at' => $campaign->updated_at->toIso8601ZuluString(),
        ];
    }

    /**
     * The price of the next unit sold: the tier for committed quantity + 1.
     */
    public static function currentUnitPrice(Campaign $campaign): Money
    {
        return $campaign->tierSet()?->current($campaign->committed_qty)->unitPrice() ?? Money::zero();
    }
}
