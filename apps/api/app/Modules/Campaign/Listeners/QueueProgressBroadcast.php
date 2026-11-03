<?php

namespace App\Modules\Campaign\Listeners;

use App\Modules\Campaign\Events\CampaignProgressChanged;
use App\Modules\Campaign\Jobs\BroadcastProgress;

/**
 * Every committed change to a campaign's units, price or status queues a
 * push of its progress (collapsed per campaign while one is waiting).
 */
final class QueueProgressBroadcast
{
    public function handle(CampaignProgressChanged $event): void
    {
        BroadcastProgress::dispatch($event->campaignId);
    }
}
