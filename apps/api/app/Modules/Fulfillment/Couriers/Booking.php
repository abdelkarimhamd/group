<?php

namespace App\Modules\Fulfillment\Couriers;

/**
 * What the courier gave back for a booked parcel: its tracking (waybill)
 * number and, when it sends one, the label to stick on the parcel as PDF
 * bytes.
 */
final readonly class Booking
{
    public function __construct(
        public string $trackingNumber,
        public ?string $labelPdf = null,
    ) {}
}
