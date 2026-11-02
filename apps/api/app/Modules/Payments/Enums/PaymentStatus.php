<?php

namespace App\Modules\Payments\Enums;

/**
 * Mirrors the CHECK constraint on payment_authorizations.status. States only
 * move forward; transitions are in the spec's Payment holds section.
 */
enum PaymentStatus: string
{
    case PendingAuthorization = 'pending_authorization';
    case Authorized = 'authorized';
    case Failed = 'failed';
    case Voided = 'voided';
    case Expired = 'expired';
    case Captured = 'captured';
    case CaptureFailed = 'capture_failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
