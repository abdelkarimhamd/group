<?php

namespace App\Modules\Payments\Enums;

enum TransactionType: string
{
    case Authorize = 'authorize';
    case Capture = 'capture';
    case Void = 'void';
    case Refund = 'refund';
}
