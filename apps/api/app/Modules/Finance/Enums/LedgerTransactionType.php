<?php

namespace App\Modules\Finance\Enums;

enum LedgerTransactionType: string
{
    /** Money taken from a card: clearing up, prepayment and VAT owed. */
    case Capture = 'capture';

    /** Money returned to a card, as its credit note says. */
    case Refund = 'refund';

    /** The order was delivered: the prepayment becomes revenue. */
    case RevenueRecognition = 'revenue_recognition';

    /** A hand-made correction, approved by a second admin. */
    case Adjustment = 'adjustment';

    case Reversal = 'reversal';
}
