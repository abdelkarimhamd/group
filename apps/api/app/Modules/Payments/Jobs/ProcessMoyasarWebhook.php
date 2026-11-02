<?php

namespace App\Modules\Payments\Jobs;

use App\Exceptions\ApiException;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\Confirmations;
use App\Support\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies one verified webhook: fetches the payment it names from Moyasar
 * and hands it to Confirmations. A provider outage is retried with backoff;
 * an event whose tries run out stays unprocessed, and Moyasar's resend or
 * payments:requeue-webhooks queues it again. Anything else is recorded on
 * the event and not retried. One job per event at a time.
 */
class ProcessMoyasarWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** Longer than every retry together; a lost job's lock then frees the event. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $eventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Confirmations $confirmations): void
    {
        $event = PaymentWebhookEvent::query()->find($this->eventId);

        if ($event === null || ! $event->signature_valid || $event->processed_at !== null) {
            return;
        }

        if ($event->provider_payment_id === null) {
            $this->finish($event, 'no_payment_id');

            return;
        }

        try {
            $payment = $confirmations->syncProviderPayment($event->provider_payment_id, Actor::system());
        } catch (ApiException $e) {
            $this->finish($event, $e->errorCode);

            return;
        }

        $this->finish($event, $payment === null ? 'unknown_payment' : null);
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    /**
     * Tries ran out: say why, but leave the event unprocessed so it is queued again.
     */
    public function failed(Throwable $e): void
    {
        PaymentWebhookEvent::query()
            ->whereKey($this->eventId)
            ->whereNull('processed_at')
            ->update(['processing_error' => mb_substr('failed: '.$e->getMessage(), 0, 1000)]);
    }

    private function finish(PaymentWebhookEvent $event, ?string $error): void
    {
        PaymentWebhookEvent::query()
            ->whereKey($event->id)
            ->whereNull('processed_at')
            ->update(['processed_at' => now(), 'processing_error' => $error]);
    }
}
