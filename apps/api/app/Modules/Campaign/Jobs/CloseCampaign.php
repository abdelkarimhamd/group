<?php

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Services\Closing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs the closing steps for one campaign (App\Modules\Campaign\Services\Closing).
 * Unique per campaign while queued, so wake-ups collapse into one run; the
 * lock is released when the run starts, so a wake-up during a run is never
 * lost. Every step re-checks the campaign under its row lock, so a second
 * run at once is harmless.
 */
class CloseCampaign implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** A lock left by a lost job expires; the sweep then wakes the campaign again. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $campaignId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(Closing $closing): void
    {
        $closing->run($this->campaignId);
    }
}
