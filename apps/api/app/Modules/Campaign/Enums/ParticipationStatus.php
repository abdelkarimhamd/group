<?php

namespace App\Modules\Campaign\Enums;

/**
 * Mirrors the CHECK constraint on campaign_participations.status.
 *
 * `pending_payment` holds reserved units; `authorized` and `captured` hold
 * committed units. `released` means the hold was voided because the campaign
 * failed or was cancelled.
 */
enum ParticipationStatus: string
{
    case PendingPayment = 'pending_payment';
    case Authorized = 'authorized';
    case PaymentFailed = 'payment_failed';
    case ReservationExpired = 'reservation_expired';
    case Cancelled = 'cancelled';
    case Released = 'released';
    case Captured = 'captured';
    case CaptureFailed = 'capture_failed';

    /**
     * Statuses whose units count against the per-customer limit.
     *
     * @return list<self>
     */
    public static function holdingUnits(): array
    {
        return [self::PendingPayment, self::Authorized, self::Captured];
    }
}
