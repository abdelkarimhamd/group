<?php

namespace App\Modules\Orders\Enums;

/**
 * Reporting to ZATCA (e-invoicing phase 2). An invoice issued while phase 2
 * is off stays `pending` (a phase 1 invoice). Otherwise: `pending` until
 * the EGS unit signs it, `submitted` once signed and waiting for ZATCA's
 * answer, then `accepted` (reported, with or without warnings) or
 * `rejected` (ZATCA found errors; it is not sent again on its own).
 */
enum ZatcaStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
