<?php

namespace App\Modules\Fulfillment\Enums;

/**
 * Where a parcel is on its way to the customer (spec, Data model: shipments).
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
     * What a tracking update may say. Returns and cancelled shipments need
     * their order moved too, which comes with returns handling.
     *
     * @return list<self>
     */
    public static function trackable(): array
    {
        return [self::PickedUp, self::InTransit, self::OutForDelivery, self::FailedAttempt, self::Delivered];
    }
}
