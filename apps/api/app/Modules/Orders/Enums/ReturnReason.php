<?php

namespace App\Modules\Orders\Enums;

/**
 * Why the customer sends the goods back.
 */
enum ReturnReason: string
{
    case Defective = 'defective';
    case DamagedInTransit = 'damaged_in_transit';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case ChangedMind = 'changed_mind';
    case Other = 'other';
}
