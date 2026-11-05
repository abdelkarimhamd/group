<?php

namespace App\Modules\Fulfillment\Enums;

/**
 * Where a parcel is on its way to the customer (spec, Data model: shipments).
 * `pending` is a booking the courier has not collected yet.
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case FailedAttempt = 'failed_attempt';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * No tracking update follows these.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned, self::Cancelled], true);
    }

    /**
     * What staff may enter as a tracking update. `returned` is the courier
     * bringing the parcel back, which puts the order back to ready to ship;
     * a shipment not yet collected is called off instead (cancelled).
     *
     * @return list<self>
     */
    public static function trackable(): array
    {
        return [self::PickedUp, self::InTransit, self::OutForDelivery, self::FailedAttempt, self::Delivered, self::Returned];
    }
}
