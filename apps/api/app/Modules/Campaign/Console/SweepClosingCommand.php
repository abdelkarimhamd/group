<?php

namespace App\Modules\Campaign\Console;

use App\Modules\Campaign\Jobs\CloseCampaign;
use App\Modules\Campaign\Services\Closing;
use Illuminate\Console\Command;

/**
 * Every five minutes, wakes the closing job for each campaign that has not
 * finished closing (spec, closing job risks: crash mid-run). A run that is
 * waiting does nothing; one past its supplier decision deadline fails the
 * campaign; a stuck capture is queued again.
 */
final class SweepClosingCommand extends Command
{
    protected $signature = 'campaigns:sweep-closing';

    protected $description = 'Wake the closing job for campaigns that have not finished closing';

    public function handle(Closing $closing): int
    {
        $ids = $closing->unfinished();

        foreach ($ids as $id) {
            CloseCampaign::dispatch($id);
        }

        $this->info('Woke '.count($ids).' closing campaigns.');

        return self::SUCCESS;
    }
}
