<?php

namespace App\Modules\Campaign\Listeners;

use App\Modules\Campaign\Events\CampaignClosingStarted;
use App\Modules\Campaign\Jobs\CloseCampaign;

/**
 * A campaign that starts closing gets its closing job at once.
 */
final class StartClosingJob
{
    public function handle(CampaignClosingStarted $event): void
    {
        CloseCampaign::dispatch($event->campaignId);
    }
}
