<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Services\MoyasarWebhooks;
use Illuminate\Console\Command;

/**
 * Every five minutes: verified Moyasar webhooks that were stored but never
 * processed (a lost queue push, or retries used up while Moyasar was down)
 * are queued again, so the database, not the queue, decides what is seen.
 */
final class RequeueWebhooksCommand extends Command
{
    protected $signature = 'payments:requeue-webhooks';

    protected $description = 'Queue again verified Moyasar webhooks that were never processed';

    public function handle(MoyasarWebhooks $webhooks): int
    {
        $this->info('Queued '.$webhooks->requeueUnprocessed().' webhooks again.');

        return self::SUCCESS;
    }
}
