<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\Refunds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one approved refund to Moyasar. An unknown outcome is retried with
 * backoff, each retry asking Moyasar first; a refund still unconfirmed
 * after the last try stays processing for finance (daily reconciliation
 * shows the payment).
 */
class ExecuteRefund implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public function __construct(public readonly int $refundId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 1800, 3600, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->refundId;
    }

    public function handle(Refunds $refunds): void
    {
        $refund = Refund::query()->find($this->refundId);

        if ($refund !== null) {
            $refunds->execute($refund);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::critical('payments.refund.unconfirmed', ['refund_id' => $this->refundId, 'error' => $e->getMessage()]);
    }
}
