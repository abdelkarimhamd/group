<?php

namespace Tests\Fakes;

use App\Modules\Fulfillment\Couriers\Booking;
use App\Modules\Fulfillment\Couriers\BookingRequest;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\CourierRefused;
use App\Modules\Fulfillment\Couriers\CourierUnavailable;
use App\Modules\Fulfillment\Couriers\TrackingUpdate;

/**
 * A courier whose answers a test sets: refuse or go quiet on bookings, and
 * report the tracking updates given to it. Uses the fake driver's codes.
 */
final class ScriptedCourier implements CourierGateway
{
    /** @var list<BookingRequest> */
    public array $booked = [];

    /** @var list<list<string>> */
    public array $tracked = [];

    /** @var 'book'|'refuse'|'quiet' */
    public string $onBook = 'book';

    public bool $trackingDown = false;

    /** @var list<TrackingUpdate> */
    public array $updates = [];

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
        $this->booked[] = $request;

        return match ($this->onBook) {
            'refuse' => throw new CourierRefused('Consignee city is not served (fake).'),
            'quiet' => throw new CourierUnavailable('Timed out (fake).'),
            default => new Booking('SCR'.count($this->booked), null),
        };
    }

    public function track(array $trackingNumbers): array
    {
        $this->tracked[] = $trackingNumbers;

        if ($this->trackingDown) {
            throw new CourierUnavailable('Tracking down (fake).');
        }

        return array_values(array_filter($this->updates, fn (TrackingUpdate $update) => in_array($update->trackingNumber, $trackingNumbers, true)));
    }
}
