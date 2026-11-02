<?php

namespace App\Modules\Campaign\Enums;

/**
 * `warehouse` for the pilot; `direct` (supplier ships to the customer) comes later.
 */
enum DeliveryMode: string
{
    case Warehouse = 'warehouse';
    case Direct = 'direct';
}
