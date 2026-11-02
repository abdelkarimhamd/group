<?php

namespace App\Modules\Notifications\Enums;

/**
 * The messages customers get (spec, E14). The Arabic and English texts are
 * in lang/{ar,en}/notifications.php under the same key.
 */
enum NotificationTemplate: string
{
    /** A paid join counted: the hold is placed. */
    case Joined = 'joined';
    /** The campaign reached its target. */
    case DealConfirmed = 'deal_confirmed';
    /** The admin used the campaign's one extension. */
    case Extended = 'extended';
    /** The campaign missed its target or the supplier fell through: hold released. */
    case CampaignFailed = 'campaign_failed';
    /** An admin cancelled the campaign before any capture: hold released. */
    case CampaignCancelled = 'campaign_cancelled';
    /** The customer cancelled a paid join: hold released. */
    case JoinCancelled = 'join_cancelled';
    /** The hold could not be captured at close: left out, hold released. */
    case CaptureFailed = 'capture_failed';
    /** The final amount was charged and the order made. */
    case Charged = 'charged';
    /** The order left the warehouse with the courier. */
    case Shipped = 'shipped';
    /** The order was delivered; the return window starts. */
    case Delivered = 'delivered';
    /** Money went back to the card (a refund, or a cancelled campaign). */
    case Refunded = 'refunded';
}
