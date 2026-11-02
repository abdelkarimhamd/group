<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The campaign reached its target for the first time. Fired once, after the
 * commit that set deal_confirmed_at. Notifications (E14) tell participants.
 */
final class CampaignDealConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
