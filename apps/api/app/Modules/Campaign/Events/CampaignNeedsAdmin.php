<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Captures finished below the target or the supplier MOQ (closing step 6):
 * an admin must proceed or cancel. Fired after commit. Notifications (E14)
 * alert ops at once.
 */
final class CampaignNeedsAdmin implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
