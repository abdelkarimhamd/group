<?php

namespace App\Modules\Finance\Enums;

/**
 * Mirrors the CHECK constraint on approval_requests.type.
 */
enum ApprovalType: string
{
    case ManualRefund = 'manual_refund';
    case ManualCapture = 'manual_capture';
    case ManualVoid = 'manual_void';
    case LedgerAdjustment = 'ledger_adjustment';
    case CampaignCancellation = 'campaign_cancellation';
}
