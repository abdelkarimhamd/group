<?php

namespace App\Modules\Payments\Enums;

enum RefundMethod: string
{
    /** A full refund soon after capture: the capture is voided. */
    case Void = 'void';

    case Refund = 'refund';
}
