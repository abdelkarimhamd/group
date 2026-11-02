<?php

namespace App\Modules\Finance\Enums;

/**
 * Mirrors the CHECK constraint on approval_requests.status.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
