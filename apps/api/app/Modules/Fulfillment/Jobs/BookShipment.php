<?php

namespace App\Modules\Fulfillment\Jobs;

use App\Modules\Fulfillment\Services\CourierBookings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one booking to the courier, after the commit that asked for it.
 * One try: a booking that went out is never sent again on its own (the
 * courier documents no idempotency), so anything unclear is left for staff.
 * shipments:track queues it again only if it never went out.
 */
class BookShipment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $shipmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->shipmentId;
    }

    public function handle(CourierBookings $bookings): void
    {
        $bookings->send($this->shipmentId);
    }
}
