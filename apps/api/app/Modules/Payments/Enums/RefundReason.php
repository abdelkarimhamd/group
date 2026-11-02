<?php

namespace App\Modules\Payments\Enums;

enum RefundReason: string
{
    case CustomerCancellation = 'customer_cancellation';
    case DeliveryDelay = 'delivery_delay';
    case Defective = 'defective';
    case Shortage = 'shortage';
    case CampaignCancelledAfterCapture = 'campaign_cancelled_after_capture';
    case Other = 'other';
}
