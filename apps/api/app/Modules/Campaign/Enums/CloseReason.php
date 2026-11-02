<?php

namespace App\Modules\Campaign\Enums;

enum CloseReason: string
{
    case Deadline = 'deadline';
    case MaxReached = 'max_reached';
}
