<?php

namespace App\Modules\Payments\Enums;

/**
 * Why a payment was voided or failed on our side. Mirrors the CHECK
 * constraint on payment_authorizations.rejection_reason. The capture
 * reasons mark a hold that could not be captured at close and is voided.
 */
enum RejectionReason: string
{
    case Declined = 'declined';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case MethodNotAllowed = 'method_not_allowed';
    case NotAuthorizeOnly = 'not_authorize_only';
    case ReservationExpired = 'reservation_expired';
    case CampaignClosed = 'campaign_closed';
    case ParticipationCancelled = 'participation_cancelled';
    case DuplicateHold = 'duplicate_hold';
    case CaptureDeclined = 'capture_declined';
    case CaptureCutoffPassed = 'capture_cutoff_passed';
}
