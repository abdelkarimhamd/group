<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Holds;
use App\Support\Actor;
use App\Support\ActorType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Voids one authorized hold. Retries an unknown outcome with backoff; each
 * retry asks the provider first. A hold still not voided after the last
 * try is caught by daily reconciliation (stuck void).
 */
class VoidPayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $actorType,
        public readonly ?int $actorId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(Holds $holds): void
    {
        $payment = PaymentAuthorization::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $actor = match (ActorType::from($this->actorType)) {
            ActorType::Admin => Actor::admin((int) $this->actorId),
            ActorType::User => Actor::user((int) $this->actorId),
            ActorType::System => Actor::system(),
        };

        $holds->void($payment, $actor);
    }
}
