<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Services\ZatcaReporting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signs one invoice's e-invoice, stores its XML, renders its PDF and
 * reports it to Fatoora (ZatcaReporting). Queued after the commit that
 * issued it; zatca:report queues it again if it is still open later.
 */
class SubmitInvoiceToZatca implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** Longer than every try and its backoff (about 1 hour 45 minutes). */
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $stampId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->stampId;
    }

    public function handle(ZatcaReporting $zatca): void
    {
        $stamp = $zatca->sign($this->stampId);
        $zatca->afterSigning($stamp);
        $zatca->report($stamp->id);
    }

    public function failed(?Throwable $e): void
    {
        Log::critical('zatca.submit.failed', ['stamp' => $this->stampId, 'error' => $e?->getMessage()]);
    }
}
