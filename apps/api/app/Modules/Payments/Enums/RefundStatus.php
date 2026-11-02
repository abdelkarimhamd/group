<?php

namespace App\Modules\Payments\Enums;

/**
 * pending_approval -> approved -> processing -> succeeded | failed, or
 * pending_approval -> rejected. Sending approved refunds to Moyasar comes
 * with finance (E15).
 */
enum RefundStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * Statuses whose amount is (or will be) returned, so it counts against
     * what is left to refund.
     *
     * @return list<self>
     */
    public static function outstanding(): array
    {
        return [self::PendingApproval, self::Approved, self::Processing, self::Succeeded];
    }
}
