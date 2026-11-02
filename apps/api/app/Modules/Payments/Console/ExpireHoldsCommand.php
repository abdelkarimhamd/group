<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Services\Holds;
use Illuminate\Console\Command;

/**
 * Hourly: holds past their expires_at become `expired` (spec, Expiry
 * clock). Moyasar never reports issuer expiry, so the platform keeps its
 * own clock. A hold still being voided gets one last void attempt first.
 */
final class ExpireHoldsCommand extends Command
{
    protected $signature = 'payments:expire-holds';

    protected $description = 'Mark card holds past their expiry as expired';

    public function handle(Holds $holds): int
    {
        $this->info('Expired '.$holds->expireDue().' holds.');

        return self::SUCCESS;
    }
}
