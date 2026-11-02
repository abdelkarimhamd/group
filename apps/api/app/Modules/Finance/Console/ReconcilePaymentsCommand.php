<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Enums\ReconciliationStatus;
use App\Modules\Finance\Services\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily: compare yesterday's payments (Riyadh time), and every payment
 * still open, with Moyasar.
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile {--date= : The day to check, YYYY-MM-DD in Riyadh time (default yesterday)}';

    protected $description = 'Reconcile payments with Moyasar';

    public function handle(Reconciliation $reconciliation): int
    {
        $date = $this->option('date');
        $day = is_string($date) && $date !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('groupbuy.display_timezone'))
            : null;

        if ($day === false) {
            $this->error('Give --date as YYYY-MM-DD.');

            return self::INVALID;
        }

        $run = $reconciliation->run($day);
        $this->info("Checked {$run->payments_checked} payments for {$run->run_date->toDateString()}: {$run->status->value}, {$run->mismatch_count} mismatches.");

        return $run->status === ReconciliationStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
