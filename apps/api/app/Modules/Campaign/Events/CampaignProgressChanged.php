<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A campaign's committed or reserved units, price or status changed. Fired
 * after commit. Each one queues a Reverb push of the campaign's progress
 * (App\Modules\Campaign\Jobs\BroadcastProgress).
 */
final class CampaignProgressChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $campaignId) {}
}
