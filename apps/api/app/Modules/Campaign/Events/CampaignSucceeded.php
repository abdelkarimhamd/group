<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Captures are done and the campaign is purchasing (closing step 8).
 * Fired after commit. Notifications (E14) tell each participant the final
 * price and amount charged, with dedupe_key campaign:{id}:closed:{user_id}.
 */
final class CampaignSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
