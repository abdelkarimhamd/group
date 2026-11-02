<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Closing reached the supplier reconfirmation (spec, closing step 4): an
 * admin must record the supplier's answer before supplier_decision_due_at.
 * Fired after commit. Notifications (E14) alert the admins.
 */
final class CampaignAwaitingSupplier implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
