<?php

namespace App\Modules\Fulfillment\Couriers;

use LogicException;

/**
 * No courier API: staff hand the parcel over, then enter the courier's
 * tracking number and updates by hand. The default until a courier is
 * chosen (open question Q11).
 */
final class ManualCourier implements CourierGateway
{
    public function name(): ?string
    {
        return null;
    }

    public function books(): bool
    {
        return false;
    }

    public function book(BookingRequest $request): Booking
    {
        throw new LogicException('No courier API is configured (COURIER_DRIVER=manual).');
    }

    public function track(array $trackingNumbers): array
    {
        return [];
    }
}
