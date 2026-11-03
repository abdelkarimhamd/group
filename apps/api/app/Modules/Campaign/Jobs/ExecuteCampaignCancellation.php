<?php

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Services\CampaignCancellations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries out an approved campaign cancellation. While a capture of the
 * campaign is in flight it comes back every minute, for up to two hours;
 * after that, or if the job is lost, finance:requeue-approved queues it
 * again until the request is carried out (approval_requests.executed_at).
 */
class ExecuteCampaignCancellation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 120;

    /** Longer than every try together, so a requeue is dropped while this job still waits. */
    public int $uniqueFor = 3 * 3600;

    public function __construct(public readonly int $approvalRequestId) {}

    public function uniqueId(): string
    {
        return (string) $this->approvalRequestId;
    }

    public function handle(CampaignCancellations $cancellations): void
    {
        if (! $cancellations->execute($this->approvalRequestId)) {
            $this->release(60);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::critical('campaigns.cancellation.blocked', ['approval_request_id' => $this->approvalRequestId, 'error' => $e->getMessage()]);
    }
}
