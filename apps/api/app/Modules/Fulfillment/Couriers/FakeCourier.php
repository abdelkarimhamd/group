<?php

namespace App\Modules\Fulfillment\Couriers;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Stands in for a courier API in development and staging, so the admin
 * portal can book parcels and watch them move. It makes no network call
 * and keeps no state: the tracking number holds the booking time, and the
 * parcel is picked up two hours later, in transit at six, out for delivery
 * at a day and delivered at a day and two hours. Its codes are mapped in
 * `groupbuy.couriers.fake.codes`. Refuses to run in production.
 */
final class FakeCourier implements CourierGateway
{
    /** Hours after booking => code, description. */
    private const SCRIPT = [
        0 => ['CR', 'Shipment record created'],
        2 => ['PU', 'Picked up from the warehouse'],
        6 => ['IT', 'Arrived at the Riyadh hub'],
        24 => ['OD', 'Out for delivery'],
        26 => ['DL', 'Delivered'],
    ];

    public function __construct(bool $production)
    {
        if ($production) {
            throw new LogicException('The fake courier cannot run in production. Set COURIER_DRIVER.');
        }
    }

    public function name(): ?string
    {
        return 'Fake courier';
    }

    public function books(): bool
    {
        return true;
    }

    public function book(BookingRequest $request): Booking
    {
        $number = sprintf('FAKE%d%s', CarbonImmutable::now()->getTimestamp(), strtoupper(substr(hash('sha256', $request->reference), 0, 4)));

        return new Booking($number, "%PDF-1.4\n% Fake courier label for {$request->orderNumber} ({$number})\n%%EOF\n");
    }

    public function track(array $trackingNumbers): array
    {
        $now = CarbonImmutable::now();
        $updates = [];

        foreach ($trackingNumbers as $number) {
            if (preg_match('/^FAKE(\d{10})[0-9A-F]{4}$/', $number, $match) !== 1) {
                continue;
            }

            $booked = CarbonImmutable::createFromTimestamp((int) $match[1]);

            foreach (self::SCRIPT as $hours => [$code, $description]) {
                $at = $booked->addHours($hours);

                if ($at->lessThanOrEqualTo($now)) {
                    $updates[] = new TrackingUpdate($number, $code, $description, 'Riyadh', $at, ['fake' => true, 'code' => $code]);
                }
            }
        }

        return $updates;
    }
}
