<?php

namespace App\Modules\Campaign\Console;

use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Payments\Services\Confirmations;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The campaign scheduler, run every minute: scheduled campaigns whose start
 * has come go live, active ones past their deadline start closing, and
 * reservations left unpaid for 15 minutes free their units, after the
 * provider is asked about any payment it has started but not settled.
 *
 * Joins are refused once the deadline passes, so the gap between runs never
 * admits a late join.
 */
final class CampaignTickCommand extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Start due campaigns, close campaigns past their deadline and expire unpaid reservations';

    public function handle(CampaignLifecycle $lifecycle, Joins $joins, Confirmations $confirmations): int
    {
        // One time for both reservation steps, so every reservation that
        // expires was checked with the provider first.
        $now = CarbonImmutable::now();
        $activated = $lifecycle->activateDue($now);
        $confirmations->checkExpiringReservations($now);
        $expired = $joins->expireReservations($now);
        $closing = $lifecycle->closeDue();

        $this->info("Started {$activated}, expired {$expired} reservations, closing {$closing}.");

        return self::SUCCESS;
    }
}
