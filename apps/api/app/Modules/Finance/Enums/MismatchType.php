<?php

namespace App\Modules\Finance\Enums;

enum MismatchType: string
{
    /** Moyasar shows another status than ours. */
    case Status = 'status';

    /** Same status, another captured or refunded amount. */
    case Amount = 'amount';

    /** Moyasar holds or took money for a payment we have no record of (from Moyasar's payment list). */
    case MissingLocally = 'missing_locally';

    /** We have a provider payment ID Moyasar does not know. */
    case MissingAtProvider = 'missing_at_provider';

    /** Moyasar holds money for a payment that failed here. */
    case OrphanHold = 'orphan_hold';

    /** A hold we released (or are releasing) is still authorized at Moyasar. */
    case StuckVoid = 'stuck_void';
}
