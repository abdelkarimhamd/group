<?php

namespace App\Modules\Finance\Enums;

enum ReconciliationStatus: string
{
    case Running = 'running';
    case Matched = 'matched';
    case Mismatches = 'mismatches';

    /** Moyasar could not be reached; the items found so far are kept. */
    case Failed = 'failed';
}
