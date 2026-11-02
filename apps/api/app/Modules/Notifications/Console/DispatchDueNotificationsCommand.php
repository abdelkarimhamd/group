<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Services\Deliveries;
use Illuminate\Console\Command;

/**
 * Every minute: messages due for a retry, and messages whose job was lost,
 * are sent again.
 */
final class DispatchDueNotificationsCommand extends Command
{
    protected $signature = 'notifications:dispatch-due';

    protected $description = 'Send notifications due for a retry or whose job was lost';

    public function handle(Deliveries $deliveries): int
    {
        $this->info('Dispatched '.$deliveries->dispatchDue().' notifications.');

        return self::SUCCESS;
    }
}
