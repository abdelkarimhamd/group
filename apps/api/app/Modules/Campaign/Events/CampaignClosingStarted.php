<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The campaign moved from active to closing (deadline passed or maximum
 * reached). Fired after commit. The closing job (E10) listens for it.
 */
final class CampaignClosingStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
