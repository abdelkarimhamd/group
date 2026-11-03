<?php

namespace App\Modules\Notifications\Enums;

/**
 * How a message reaches the customer (spec, Data model: notifications).
 * Email and in-app messages exist in the model but nothing sends them yet.
 */
enum NotificationChannel: string
{
    case Push = 'push';
    case Sms = 'sms';
    case Email = 'email';
    case InApp = 'in_app';
}
