<?php

namespace App\Modules\Fulfillment\Console;

use App\Modules\Fulfillment\Services\CourierBookings;
use App\Modules\Fulfillment\Services\CourierTracking;
use Illuminate\Console\Command;

/**
 * Every thirty minutes: settle bookings left without an answer, then read
 * tracking from the courier's API for parcels still on their way. Does
 * nothing with COURIER_DRIVER=manual.
 */
final class TrackShipmentsCommand extends Command
{
    protected $signature = 'shipments:track';

    protected $description = "Settle stale courier bookings and read tracking from the courier's API";

    public function handle(CourierBookings $bookings, CourierTracking $tracking): int
    {
        $swept = $bookings->sweep();
        $read = $tracking->poll();
        $this->info("Bookings queued again: {$swept['requeued']}, cancelled for checking: {$swept['abandoned']}. Parcels read: {$read['read']}, new updates: {$read['recorded']}.");

        return self::SUCCESS;
    }
}
