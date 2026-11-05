<?php

namespace App\Modules\Finance\Enums;

/**
 * Mirrors the CHECK constraint on ledger_adjustments.status.
 */
enum LedgerAdjustmentStatus: string
{
    /** Waiting for a second admin with the finance role. */
    case Pending = 'pending';

    case Posted = 'posted';

    case Rejected = 'rejected';
}
