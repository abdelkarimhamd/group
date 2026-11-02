<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Captures are done and the campaign is purchasing (closing step 8).
 * Fired after commit. Each customer was told what they were charged when
 * their order was made (Orders::createForCapture).
 */
final class CampaignSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
