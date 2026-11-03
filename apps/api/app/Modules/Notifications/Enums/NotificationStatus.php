<?php

namespace App\Modules\Notifications\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Read = 'read';
}
