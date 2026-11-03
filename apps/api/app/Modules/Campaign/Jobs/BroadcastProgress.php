<?php

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Events\ProgressUpdated;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Progress;
use DateTimeInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Pusher\PusherException;
use Throwable;

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
 * logged and dropped, never retried.
 */
class BroadcastProgress implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Waiting for another run of the same campaign (middleware()) releases
     * the job, and every release counts as a try, so tries are bounded by
     * time instead (retryUntil): longer than a lost run's overlap lock lives.
     */
    public int $maxExceptions = 3;

    /** A lock left by a lost job expires; the next change pushes again. */
    public int $uniqueFor = 60;

    public function __construct(public readonly int $campaignId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->uniqueFor);
    }

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

    /**
     * A job that gave up while waiting never started its run, so it still
     * holds its unique lock: let the next change queue a push at once.
     */
    public function failed(?Throwable $e): void
    {
        (new UniqueLock(app(Cache::class)))->release($this);
    }
}
