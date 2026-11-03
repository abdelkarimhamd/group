<?php

namespace App\Modules\Fulfillment\Console;

use App\Modules\Fulfillment\Services\Shipments;
use Illuminate\Console\Command;

/**
 * Hourly: delivered orders whose return window has ended become
 * `completed`, and so does a campaign left with nothing on its way.
 */
final class CompleteDeliveredOrdersCommand extends Command
{
    protected $signature = 'orders:complete-delivered';

    protected $description = 'Complete delivered orders whose return window has ended';

    public function handle(Shipments $shipments): int
    {
        $this->info('Completed '.$shipments->completeDelivered().' orders.');

        return self::SUCCESS;
    }
}
