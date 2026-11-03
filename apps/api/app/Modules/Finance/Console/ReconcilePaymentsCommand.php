<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Enums\ReconciliationStatus;
use App\Modules\Finance\Services\Reconciliation;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
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
        $day = null;

        if ($date !== null && $date !== '') {
            $day = is_string($date) ? self::parseDay($date) : null;

            if ($day === null) {
                $this->error('Give --date as YYYY-MM-DD.');

                return self::INVALID;
            }
        }

        $run = $reconciliation->run($day);
        $this->info("Checked {$run->payments_checked} payments for {$run->run_date->toDateString()}: {$run->status->value}, {$run->mismatch_count} mismatches.");

        return $run->status === ReconciliationStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A real calendar day, or null: createFromFormat throws on text it
     * cannot read and rolls 2026-13-45 over to a real date.
     */
    private static function parseDay(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('groupbuy.display_timezone'));
        } catch (InvalidFormatException) {
            return null;
        }

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $date ? $day : null;
    }
}
