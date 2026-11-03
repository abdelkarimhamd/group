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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pusher\PusherException;

/**
 * Pushes one campaign's progress to the website and app over Reverb.
 *
 * The numbers are read when the job runs, not when the change happened, so
 * a late push still carries the newest numbers. Unique per campaign while
 * queued, so a burst of joins becomes one push; the lock is released when
 * the job starts its run, so a change made during a run queues the next
 * push. Runs for one campaign never overlap, so pushes leave in the order
 * they read the numbers (the payload's `version` lets clients drop an older
 * one anyway).
 *
 * Pushes are best effort: clients catch up with GET /campaigns/{id}/progress
 * when they connect and keep a slow poll, so a push Reverb did not take is
 * logged and dropped, never retried. Before sending, the job stores the
 * payload where that endpoint caches it, so a catch-up read is never older
 * than a push sent before it.
 */
class BroadcastProgress implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * No limit on tries: waiting for another run of the same campaign
     * (middleware()) releases the job, and every release counts as a try.
     * The wait ends anyway, since only that one run holds the overlap lock,
     * and a lost run's lock expires after 15 seconds. Not retryUntil either:
     * it counts from dispatch, so a busy queue would fail pushes before they
     * ever ran, and a late push still reads the newest numbers.
     */
    public int $tries = 0;

    /** Errors other than Reverb's (the database, say) are tried again, up to here. */
    public int $maxExceptions = 3;

    /** A run that hangs fails instead of coming back forever. */
    public int $timeout = 30;

    public bool $failOnTimeout = true;

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

        $payload = Progress::payload($campaign);
        Cache::put(Progress::cacheKey($campaign->uuid), $payload, (int) config('groupbuy.campaigns.progress_cache_seconds'));

        try {
            broadcast(new ProgressUpdated($campaign->uuid, $payload));
        } catch (BroadcastException|GuzzleException|PusherException $e) {
            // BroadcastException: Reverb answered with an error. Guzzle's: no
            // answer. PusherException: an answer that was not Reverb's (a
            // proxy's page, say).
            Log::warning('campaigns.progress_push_failed', [
                'campaign_id' => $campaign->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
