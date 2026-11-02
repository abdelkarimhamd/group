<?php

namespace App\Modules\Orders\Enums;

/**
 * A customer order, from capture to delivery (spec, Orders). Purchasing
 * (E12) and fulfilment (E13) move it past `confirmed`.
 */
enum OrderStatus: string
{
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case ReturnRequested = 'return_requested';
    case Returned = 'returned';
}
