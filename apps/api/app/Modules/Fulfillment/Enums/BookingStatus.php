<?php

namespace App\Modules\Fulfillment\Enums;

/**
 * A shipment booked through the courier's API: asked for, answered with a
 * tracking number and label, or refused (the shipment is then cancelled).
 */
enum BookingStatus: string
{
    case Requested = 'requested';
    case Booked = 'booked';
    case Failed = 'failed';
}
