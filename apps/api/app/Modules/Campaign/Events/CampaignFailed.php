<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The campaign ended without a purchase (target missed, supplier rejected,
 * no supplier decision in time, or every capture failed). Every join is
 * released and every hold voided. Fired after commit. Each paid join's
 * owner is told by CampaignNotices in that transaction.
 */
final class CampaignFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
