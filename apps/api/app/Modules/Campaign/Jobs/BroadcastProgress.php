<?php

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Events\ProgressUpdated;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Progress;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Pushes one campaign's progress to the website and app over Reverb.
 *
 * The numbers are read when the job runs, not when the change happened, so
 * a late push still carries the newest numbers. Unique per campaign while
 * queued, so a burst of joins becomes one push; the lock is released when
 * the job starts, so a change made during a run queues the next push. Runs
 * for one campaign never overlap, so pushes leave in the order they read
 * the numbers: a client never gets older numbers after newer ones (the
 * timestamps only have whole seconds, so clients could not tell).
 *
 * Pushes are best effort: clients catch up with GET /campaigns/{id}/progress
 * when they connect and keep a slow poll, so a push Reverb did not take is
 * logged and dropped, never retried.
 */
class BroadcastProgress implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Waiting for the run before it counts as a try (see middleware()). */
    public int $tries = 10;

    public int $maxExceptions = 3;

    /** A lock left by a lost job expires; the next change pushes again. */
    public int $uniqueFor = 60;

    public function __construct(public readonly int $campaignId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->campaignId))->releaseAfter(1)->expireAfter(15)];
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(): void
    {
        // Drafts and campaigns awaiting approval are never sent: their
        // channels are public.
        $campaign = Campaign::visibleToCustomers()->with('currentTiers')->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        try {
            broadcast(new ProgressUpdated($campaign->uuid, Progress::payload($campaign)));
        } catch (BroadcastException|GuzzleException $e) {
            Log::warning('campaigns.progress_push_failed', [
                'campaign_id' => $campaign->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
