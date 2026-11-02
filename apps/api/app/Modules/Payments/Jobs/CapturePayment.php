<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Captures;
use App\Support\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Captures one hold at close (App\Modules\Payments\Services\Captures). One
 * job per payment at a time, held until it finishes, so two captures for a
 * hold are never in flight together. An unknown outcome retries with
 * backoff for up to groupbuy.payments.capture_retry_hours; Captures checks
 * the capture cutoff before every try.
 */
class CapturePayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds the lock lasts: it outlives every retry, then frees the hold for the sweep. */
    public int $uniqueFor;

    public function __construct(public readonly int $paymentId)
    {
        $this->uniqueFor = ((int) config('groupbuy.payments.capture_retry_hours') + 1) * 3600;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours((int) config('groupbuy.payments.capture_retry_hours'));
    }

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(Captures $captures): void
    {
        $payment = PaymentAuthorization::query()->find($this->paymentId);

        if ($payment !== null) {
            $captures->capture($payment, Actor::system());
        }
    }

    public function failed(Throwable $e): void
    {
        $payment = PaymentAuthorization::query()->find($this->paymentId);

        if ($payment !== null) {
            app(Captures::class)->unresolved($payment, $e);
        }
    }
}
