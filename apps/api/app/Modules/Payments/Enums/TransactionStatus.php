<?php

namespace App\Modules\Payments\Enums;

/**
 * `pending` is written before the call to Moyasar is sent. A row left
 * pending means the outcome is unknown and must be checked with Moyasar,
 * never retried blind.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
