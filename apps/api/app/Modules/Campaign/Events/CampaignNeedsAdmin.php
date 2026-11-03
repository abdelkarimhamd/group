<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Captures finished below the target or the supplier MOQ (closing step 6):
 * an admin must proceed or cancel. Fired after commit. Admins find it in
 * the portal's campaign list; alerts to ops by SMS or email come with the
 * real integrations.
 */
final class CampaignNeedsAdmin implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
