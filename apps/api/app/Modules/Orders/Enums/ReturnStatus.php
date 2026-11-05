<?php

namespace App\Modules\Orders\Enums;

/**
 * A customer return (spec, Orders: returns within the return window).
 * Recorded by staff as `requested`; `approved` asks finance for a refund,
 * `rejected` puts the order back to delivered; `completed` once the refund
 * went through and the order is returned.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
}
