<?php

namespace App\Modules\Fulfillment\Couriers;

/**
 * A courier's API (open question Q11): booking a parcel and reading its
 * tracking. `COURIER_DRIVER` picks one: `manual` (no API: staff enter the
 * courier's tracking number and updates by hand), `fake` (development and
 * tests, refused in production) or `aramex`.
 */
interface CourierGateway
{
    /** The courier's name as shipments record it, e.g. "Aramex". Null for `manual`. */
    public function name(): ?string;

    /** Whether parcels can be booked through the API. */
    public function books(): bool;

    /**
     * Book one parcel. `$request->reference` is our booking key and goes to
     * the courier as our reference.
     *
     * @throws CourierRefused the courier said no; nothing was booked
     * @throws CourierUnavailable no clear answer; the booking may exist
     */
    public function book(BookingRequest $request): Booking;

    /**
     * Every update the courier has for these tracking numbers, in no
     * particular order. Unknown numbers are left out.
     *
     * @param  list<string>  $trackingNumbers
     * @return list<TrackingUpdate>
     *
     * @throws CourierUnavailable
     */
    public function track(array $trackingNumbers): array;
}
