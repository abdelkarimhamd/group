<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Services\Deliveries;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one queued message. Retries are scheduled on the message itself
 * (next_attempt_at) and picked up by notifications:dispatch-due, so the
 * job runs once.
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId) {}

    public function handle(Deliveries $deliveries): void
    {
        $deliveries->deliver($this->notificationId);
    }
}
