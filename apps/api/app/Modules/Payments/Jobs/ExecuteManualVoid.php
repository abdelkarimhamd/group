<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Payments\Services\ManualVoids;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one approved manual void. An unknown outcome is retried with
 * backoff; each try asks Moyasar first. One still unresolved after the last
 * try stays pending and shows up in the next reconciliation.
 */
class ExecuteManualVoid implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /**
     * Longer than every try and its backoff (about 80 minutes). Without it
     * the lock never expires, and a lost job would keep every requeue of
     * finance:requeue-approved out for good.
     */
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $approvalRequestId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->approvalRequestId;
    }

    public function handle(ManualVoids $voids): void
    {
        $request = ApprovalRequest::query()->find($this->approvalRequestId);

        if ($request !== null) {
            $voids->execute($request);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::critical('payments.manual_void.unresolved', ['approval_request_id' => $this->approvalRequestId, 'error' => $e?->getMessage()]);
    }
}
